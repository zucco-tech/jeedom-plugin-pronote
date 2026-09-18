<?php
/* Pont entre un élève Pronote et le plugin Agenda officiel de Jeedom.
 *
 * À chaque synchronisation, les cours, journées d'école, devoirs et vacances
 * de l'élève sont projetés dans l'agenda choisi : créés, mis à jour ou
 * supprimés selon ce que Pronote dit maintenant. Chaque événement porte deux
 * marques dans son cmd_param — l'élève et un identifiant stable — si bien que
 * rien de ce que l'utilisateur crée à la main n'est jamais touché.
 *
 * Modèles : un événement de l'agenda nommé « Modèle Pronote : cours »,
 * « … : journée », « … : devoir », « … : vacances » ou « … : <matière> » sert
 * de gabarit. Ses actions de début et de fin, son icône et sa couleur sont
 * copiées sur chaque événement créé du même genre. Les actions d'un événement
 * déjà créé ne sont jamais réécrites : ce que l'utilisateur y règle reste.
 *
 * Le plugin Agenda n'est pas une dépendance : sans lui, tout ceci est inerte.
 */

class pronote_agenda {

    const TAG_STUDENT = 'pronote_student';
    const TAG_UID = 'pronote_uid';
    const TAG_KIND = 'pronote_kind';
    const TEMPLATE_PREFIX = 'modèle pronote :';
    const KINDS = array('day', 'lesson', 'homework', 'holiday');

    /** Le plugin Agenda est-il installé, actif et utilisable ? */
    public static function available() {
        try {
            $plugin = plugin::byId('calendar');
        } catch (Throwable $e) {
            return false;
        }
        return is_object($plugin) && $plugin->isActive() == 1 && class_exists('calendar_event');
    }

    /** Agendas disponibles : [id => nom lisible]. */
    public static function agendas() {
        $out = array();
        if (!self::available()) {
            return $out;
        }
        foreach (eqLogic::byType('calendar', true) as $cal) {
            $out[(int)$cal->getId()] = $cal->getHumanName();
        }
        return $out;
    }

    /** Ce que l'élève a demandé (agenda et genres d'événements). */
    public static function settings(pronote $student) {
        return array(
            'agenda'   => (int)$student->getConfiguration('calendar_id', 0),
            'day'      => $student->getConfiguration('calendar_day', 1) == 1,
            'lesson'   => $student->getConfiguration('calendar_lessons', 1) == 1,
            'homework' => $student->getConfiguration('calendar_homework', 0) == 1,
            'holiday'  => $student->getConfiguration('calendar_holidays', 1) == 1,
        );
    }

    /**
     * Projette les données de l'élève dans son agenda. Rend un résumé
     * ['created' => n, 'updated' => n, 'removed' => n, 'kept' => n, 'total' => n]
     * ou null si rien n'est configuré.
     */
    public static function sync(pronote $student) {
        if (!self::available()) {
            return null;
        }
        $conf = self::settings($student);
        if ($conf['agenda'] <= 0) {
            return null;
        }
        $agenda = eqLogic::byId($conf['agenda']);
        if (!is_object($agenda) || $agenda->getEqType_name() !== 'calendar') {
            log::add('pronote', 'warning', $student->getHumanName() . ' : agenda Jeedom introuvable (id ' . $conf['agenda'] . ')');
            return null;
        }

        $desired = self::desired($student, $conf);
        $templates = self::templates($agenda);
        $existing = self::existing($student->getId());
        $stats = array('created' => 0, 'updated' => 0, 'removed' => 0, 'kept' => 0);
        $todayStart = strtotime('today');

        foreach ($desired as $uid => $spec) {
            $tpl = self::templateFor($templates, $spec);
            if (isset($existing[$uid])) {
                $event = $existing[$uid];
                unset($existing[$uid]);
                // L'événement a pu être déplacé dans un autre agenda par l'utilisateur : on suit.
                if ((int)$event->getEqLogic_id() !== (int)$agenda->getId()) {
                    $event->setEqLogic_id($agenda->getId());
                }
                if (self::apply($event, $spec, $tpl, false)) {
                    $event->save();
                    $stats['updated']++;
                } else {
                    $stats['kept']++;
                }
                continue;
            }
            $event = new calendar_event();
            $event->setEqLogic_id($agenda->getId());
            $event->setCmd_param(self::TAG_STUDENT, (string)$student->getId());
            $event->setCmd_param(self::TAG_UID, $uid);
            self::apply($event, $spec, $tpl, true);
            $event->save();
            $stats['created']++;
        }

        /* Ce qui reste dans $existing n'est plus dans Pronote (cours déplacé,
           devoir retiré, agenda changé) : on retire les événements à venir. Le
           passé est conservé — c'est l'historique de l'agenda — sauf au-delà de
           90 jours, pour ne pas l'encombrer indéfiniment. */
        $purgeBefore = $todayStart - 90 * 86400;
        foreach ($existing as $uid => $event) {
            $start = strtotime((string)$event->getStartDate());
            if ($start >= $todayStart || $start < $purgeBefore) {
                $event->remove();
                $stats['removed']++;
            } else {
                $stats['kept']++;
            }
        }
        $stats['total'] = count($desired);
        $student->setCache('agendaStats', json_encode($stats + array('at' => time(), 'agenda' => $agenda->getHumanName())));
        log::add('pronote', 'info', $student->getHumanName() . ' → agenda « ' . $agenda->getHumanName() . '» : '
            . $stats['created'] . ' créés, ' . $stats['updated'] . ' mis à jour, ' . $stats['removed'] . ' retirés');
        return $stats;
    }

    /** Retire tous les événements de cet élève, dans tous les agendas. */
    public static function purge(pronote $student) {
        if (!self::available()) {
            return 0;
        }
        $n = 0;
        foreach (self::existing($student->getId()) as $event) {
            $event->remove();
            $n++;
        }
        $student->setCache('agendaStats', '');
        return $n;
    }

    /* ------------------------------------------------------------------ */

    /** Événements voulus : uid => spécification, calculés depuis les données de l'élève. */
    protected static function desired(pronote $student, array $conf) {
        $store = $student->getData();
        $name = trim((string)$student->getConfiguration('student_name', '')) ?: $student->getName();
        $first = preg_split('/\s+/u', $name)[0];
        $out = array();

        $lessons = (isset($store['_lessons']) && is_array($store['_lessons'])) ? $store['_lessons'] : array();
        $byDay = array();
        foreach ($lessons as $l) {
            if (empty($l['date']) || empty($l['start'])) {
                continue;
            }
            $end = !empty($l['end']) ? $l['end'] : date('H:i', strtotime($l['start']) + 55 * 60);
            $subject = trim((string)($l['subject'] ?? 'Cours'));
            $cancelled = !empty($l['cancelled']);
            if ($conf['lesson']) {
                $uid = 'lesson:' . $l['date'] . ':' . str_replace(':', '', $l['start']) . ':' . substr(md5(mb_strtolower($subject)), 0, 8);
                $details = array_filter(array($l['teacher'] ?? '', $l['room'] ?? ''));
                $out[$uid] = array(
                    'kind' => 'lesson', 'subject' => $subject,
                    'start' => $l['date'] . ' ' . $l['start'] . ':00', 'end' => $l['date'] . ' ' . $end . ':00',
                    'name' => ($cancelled ? 'Annulé · ' : '') . $subject . (count($details) ? ' — ' . implode(' · ', $details) : '')
                            . (!empty($l['test']) ? ' ✎' : ''),
                    'color' => self::subjectColor($subject), 'icon' => '',
                    'transparent' => $cancelled ? 1 : 0,
                );
            }
            if (!$cancelled) {
                if (!isset($byDay[$l['date']])) {
                    $byDay[$l['date']] = array('start' => $l['start'], 'end' => $end);
                } else {
                    $byDay[$l['date']]['start'] = min($byDay[$l['date']]['start'], $l['start']);
                    $byDay[$l['date']]['end'] = max($byDay[$l['date']]['end'], $end);
                }
            }
        }
        if ($conf['day']) {
            foreach ($byDay as $date => $b) {
                $out['day:' . $date] = array(
                    'kind' => 'day', 'subject' => '',
                    'start' => $date . ' ' . $b['start'] . ':00', 'end' => $date . ' ' . $b['end'] . ':00',
                    'name' => $first . ' — journée d\'école', 'color' => '#2b6cb0', 'icon' => '', 'transparent' => 0,
                );
            }
        }
        if ($conf['homework']) {
            foreach ((isset($store['_homework']) && is_array($store['_homework'])) ? $store['_homework'] : array() as $h) {
                if (empty($h['date'])) {
                    continue;
                }
                $subject = trim((string)($h['subject'] ?? 'Devoir'));
                $key = !empty($h['id']) ? (string)$h['id'] : substr(md5($subject . ($h['description'] ?? '')), 0, 8);
                $out['homework:' . $h['date'] . ':' . substr(md5($key), 0, 8)] = array(
                    'kind' => 'homework', 'subject' => $subject,
                    'start' => $h['date'] . ' 00:00:00', 'end' => $h['date'] . ' 23:59:00',
                    'name' => (!empty($h['done']) ? '✓ ' : '') . 'Devoir · ' . $subject
                            . (!empty($h['description']) ? ' — ' . mb_substr($h['description'], 0, 60) : ''),
                    'color' => self::subjectColor($subject), 'icon' => '', 'transparent' => 1,
                );
            }
        }
        if ($conf['holiday']) {
            $todayIso = date('Y-m-d');
            foreach ((isset($store['_holidays']) && is_array($store['_holidays'])) ? $store['_holidays'] : array() as $h) {
                if (empty($h['start']) || empty($h['end']) || $h['end'] < $todayIso) {
                    continue;
                }
                $out['holiday:' . $h['start']] = array(
                    'kind' => 'holiday', 'subject' => '',
                    'start' => $h['start'] . ' 00:00:00', 'end' => $h['end'] . ' 23:59:00',
                    'name' => (string)($h['name'] ?? 'Vacances') . ' — ' . $first,
                    'color' => '#38a169', 'icon' => '', 'transparent' => 1,
                );
            }
        }
        return $out;
    }

    /** Événements déjà créés par le plugin pour cet élève : uid => calendar_event. */
    protected static function existing($_studentId) {
        $out = array();
        foreach (calendar_event::searchByCmd_param('"' . self::TAG_STUDENT . '":"' . (int)$_studentId . '"') as $event) {
            $uid = (string)$event->getCmd_param(self::TAG_UID, '');
            if ($uid !== '') {
                $out[$uid] = $event;
            }
        }
        return $out;
    }

    /** Modèles de l'agenda : clé (« cours », « journée », « eps »…) => calendar_event. */
    protected static function templates($agenda) {
        $out = array();
        foreach (calendar_event::getEventsByEqLogic($agenda->getId()) as $event) {
            $label = mb_strtolower(trim((string)$event->getCmd_param('eventName', $event->getCmd_param('name', ''))));
            if (strpos($label, self::TEMPLATE_PREFIX) !== 0) {
                continue;
            }
            $key = trim(mb_substr($label, mb_strlen(self::TEMPLATE_PREFIX)));
            if ($key !== '') {
                $out[$key] = $event;
            }
        }
        return $out;
    }

    /** Le modèle qui s'applique à un événement : la matière d'abord, puis le genre. */
    protected static function templateFor(array $templates, array $spec) {
        if ($spec['subject'] !== '') {
            $subject = mb_strtolower($spec['subject']);
            foreach ($templates as $key => $tpl) {
                if ($key === $subject || (mb_strlen($key) >= 3 && strpos($subject, $key) !== false)) {
                    return $tpl;
                }
            }
        }
        $byKind = array('day' => 'journée', 'lesson' => 'cours', 'homework' => 'devoir', 'holiday' => 'vacances');
        $key = $byKind[$spec['kind']];
        return isset($templates[$key]) ? $templates[$key] : null;
    }

    /**
     * Applique une spécification à un événement. Rend true si quelque chose a
     * changé. À la création, le modèle fournit actions, icône et couleur ;
     * ensuite seuls les faits Pronote (dates, nom, annulation) sont réécrits.
     */
    protected static function apply(calendar_event $event, array $spec, $tpl, $isNew) {
        $changed = false;
        $set = function ($key, $value) use ($event, &$changed) {
            if ((string)$event->getCmd_param($key, '') !== (string)$value) {
                $event->setCmd_param($key, $value);
                $changed = true;
            }
        };
        if ($event->getStartDate() !== $spec['start']) {
            $event->setStartDate($spec['start']);
            $changed = true;
        }
        if ($event->getEndDate() !== $spec['end']) {
            $event->setEndDate($spec['end']);
            $changed = true;
        }
        $set('eventName', $spec['name']);
        $set('transparent', $spec['transparent']);
        $set(self::TAG_KIND, $spec['kind']);

        if ($isNew) {
            $event->setRepeat('enable', 0);
            $event->setCmd_param('text_color', 'white');
            $event->setCmd_param('color', $spec['color']);
            $event->setCmd_param('icon', $spec['icon']);
            if (is_object($tpl)) {
                foreach (array('start', 'end') as $phase) {
                    $actions = $tpl->getCmd_param($phase, array());
                    if (is_array($actions) && count($actions)) {
                        $event->setCmd_param($phase, $actions);
                    }
                }
                $icon = (string)$tpl->getCmd_param('icon', '');
                if ($icon !== '') {
                    $event->setCmd_param('icon', $icon);
                }
                $color = (string)$tpl->getCmd_param('color', '');
                if ($color !== '' && $color !== '#2980b9') {
                    $event->setCmd_param('color', $color);
                }
                $event->setCmd_param('text_color', $tpl->getCmd_param('text_color', 'white'));
                $event->setCmd_param('noDisplayOnDashboard', $tpl->getCmd_param('noDisplayOnDashboard', 0));
            }
            return true;
        }
        // Mise à jour : couleur, icône et actions sont à l'utilisateur désormais.
        return $changed;
    }

    /** Couleur stable par matière (même teinte que les widgets), en hexadécimal. */
    public static function subjectColor($subject) {
        $h = pronote::hue($subject) / 360;
        $s = 0.55; $l = 0.48;
        $q = $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $rgb = array();
        foreach (array($h + 1 / 3, $h, $h - 1 / 3) as $t) {
            if ($t < 0) { $t += 1; }
            if ($t > 1) { $t -= 1; }
            if ($t < 1 / 6) { $c = $p + ($q - $p) * 6 * $t; }
            elseif ($t < 1 / 2) { $c = $q; }
            elseif ($t < 2 / 3) { $c = $p + ($q - $p) * (2 / 3 - $t) * 6; }
            else { $c = $p; }
            $rgb[] = sprintf('%02x', (int)round($c * 255));
        }
        return '#' . implode('', $rgb);
    }

    /** Résumé lisible pour la fiche et la page Santé. */
    public static function summary(pronote $student) {
        $raw = json_decode((string)$student->getCache('agendaStats', ''), true);
        if (!is_array($raw) || !isset($raw['total'])) {
            return '';
        }
        return $raw['total'] . ' événement(s) dans « ' . ($raw['agenda'] ?? '') . ' » · dernière projection '
             . date('d/m H:i', (int)($raw['at'] ?? 0));
    }
}
