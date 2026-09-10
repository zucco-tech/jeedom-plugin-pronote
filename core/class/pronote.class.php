<?php
/* Plugin Pronote pour Jeedom — classe principale.
 *
 * Architecture : pas de démon. Le cron Jeedom (cron15) réveille le plugin toutes
 * les 15 minutes ; chaque équipement décide s'il est temps de se synchroniser
 * selon sa propre fréquence et sa plage horaire. La récupération des données est
 * déléguée à un script Python isolé (resources/pronote/pronote_fetch.py) qui
 * rend un JSON sur stdout.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class pronote extends eqLogic {

    /* ------------------------------------------------------------------ */
    /* Chemins                                                             */
    /* ------------------------------------------------------------------ */

    public static function getPluginPath() {
        return realpath(__DIR__ . '/../..');
    }

    public static function getPythonPath() {
        return self::getPluginPath() . '/resources/venv/bin/python3';
    }

    public static function getScriptPath() {
        return self::getPluginPath() . '/resources/pronote/pronote_fetch.py';
    }

    /* ------------------------------------------------------------------ */
    /* Réglages : plugin par défaut, surcharge par élève si renseignée     */
    /* ------------------------------------------------------------------ */

    const PLUGIN_SETTINGS = array(
        'frequency' => 30, 'homework_days' => 7, 'per_subject' => 0, 'skip_done' => 0, 'device_name' => 'Jeedom',
    );

    /** Valeur d'un réglage : celle de l'élève si elle existe, sinon celle du plugin. */
    public function setting($_key) {
        $default = isset(self::PLUGIN_SETTINGS[$_key]) ? self::PLUGIN_SETTINGS[$_key] : '';
        $own = $this->getConfiguration($_key, null);
        if ($own !== null && $own !== '') {
            return $own;
        }
        $global = config::byKey($_key, 'pronote', null);
        return ($global === null || $global === '') ? $default : $global;
    }

    /* ------------------------------------------------------------------ */
    /* Secrets                                                             */
    /* ------------------------------------------------------------------ */

    const SECRET_KEYS = array('credentials', 'password', 'account_pin');
    const SECRET_PREFIX = 'enc:';

    /** Lit une configuration sensible, chiffrée ou non (migration transparente). */
    public function getSecret($_key, $_default = '') {
        $v = (string)$this->getConfiguration($_key, '');
        if ($v === '') {
            return $_default;
        }
        if (strpos($v, self::SECRET_PREFIX) === 0 && method_exists('utils', 'decrypt')) {
            $clear = utils::decrypt(substr($v, strlen(self::SECRET_PREFIX)));
            return ($clear === false || $clear === null) ? $_default : $clear;
        }
        return $v;
    }

    /** Écrit une configuration sensible chiffrée (sans sauvegarder). */
    public function setSecret($_key, $_value) {
        $_value = (string)$_value;
        if ($_value === '' || !method_exists('utils', 'encrypt')) {
            $this->setConfiguration($_key, $_value);
            return;
        }
        $this->setConfiguration($_key, self::SECRET_PREFIX . utils::encrypt($_value));
    }

    /** Chiffre ce qui est encore en clair (après une saisie dans l'IHM ou une mise à jour). */
    public function encryptSecrets() {
        $changed = false;
        foreach (self::SECRET_KEYS as $k) {
            $v = (string)$this->getConfiguration($k, '');
            if ($v !== '' && strpos($v, self::SECRET_PREFIX) !== 0) {
                $this->setSecret($k, $v);
                $changed = true;
            }
        }
        return $changed;
    }

    /* ------------------------------------------------------------------ */
    /* Dépendances                                                         */
    /* ------------------------------------------------------------------ */

    public static function dependancy_info() {
        $return = array();
        $return['log'] = log::getPathToLog('pronote_update');
        $return['progress_file'] = jeedom::getTmpFolder('pronote') . '/dependance';
        if (file_exists($return['progress_file'])) {
            $return['state'] = 'in_progress';
            return $return;
        }
        $return['state'] = self::pythonReady() ? 'ok' : 'nok';
        return $return;
    }

    public static function dependancy_install() {
        log::remove('pronote_update');
        return array(
            'script' => self::getPluginPath() . '/resources/install_apt.sh ' . jeedom::getTmpFolder('pronote') . '/dependance',
            'log' => log::getPathToLog('pronote_update')
        );
    }

    /** Le venv existe-t-il et pronotepy y est-il importable ? (résultat gardé 10 min) */
    public static function pythonReady($_force = false) {
        if (!file_exists(self::getPythonPath())) {
            cache::set('pronote::pythonReady', 0, 60);
            return false;
        }
        if (!$_force) {
            $c = cache::byKey('pronote::pythonReady');
            if (is_object($c) && $c->getValue(null) !== null) {
                return (bool)$c->getValue();
            }
        }
        $cmd = escapeshellarg(self::getPythonPath()) . ' -c ' . escapeshellarg('import pronotepy') . ' 2>&1';
        $ok = (trim((string)shell_exec($cmd)) === '');
        cache::set('pronote::pythonReady', $ok ? 1 : 0, 600);
        return $ok;
    }

    /**
     * Décode une image de QR Code Pronote et rend son contenu.
     * @return array payload du script Python (ok / qr / error)
     */
    public static function decodeQrImage($_path) {
        if (!file_exists($_path)) {
            return array('ok' => false, 'code' => 'config', 'error' => __('Image introuvable', __FILE__));
        }
        if (!file_exists(self::getPythonPath())) {
            return array('ok' => false, 'code' => 'deps', 'error' => __('Dépendances non installées', __FILE__));
        }
        $cmd = escapeshellarg(self::getPythonPath()) . ' ' . escapeshellarg(self::getScriptPath())
             . ' --decode-qr ' . escapeshellarg($_path)
             . ' 2>>' . escapeshellarg(log::getPathToLog('pronote'));
        $raw = shell_exec($cmd);
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) {
            return array('ok' => false, 'code' => 'script',
                         'error' => __('Réponse illisible du décodeur (voir le log)', __FILE__));
        }
        return $payload;
    }

    /** Version de pronotepy réellement installée, ou '' si indisponible. */
    public static function pronotepyVersion() {
        if (!file_exists(self::getPythonPath())) {
            return '';
        }
        $cmd = escapeshellarg(self::getPythonPath()) . ' -c ' . escapeshellarg('import pronotepy;print(pronotepy.__version__)') . ' 2>/dev/null';
        return trim((string)shell_exec($cmd));
    }

    /* ------------------------------------------------------------------ */
    /* Santé (page Santé de Jeedom)                                        */
    /* ------------------------------------------------------------------ */

    public static function health() {
        $return = array();
        $return[] = array(
            'test' => __('Dépendances Python', __FILE__),
            'result' => self::pythonReady() ? __('OK', __FILE__) . ' — pronotepy ' . self::pronotepyVersion() : __('NOK', __FILE__),
            'advice' => self::pythonReady() ? '' : __('Relancer l\'installation des dépendances', __FILE__),
            'state' => self::pythonReady(),
        );
        if (config::byKey('suspend_holidays', 'pronote', 0) == 1) {
            $nh = self::nextHoliday();
            $return[] = array(
                'test' => __('Vacances scolaires (zone ', __FILE__) . config::byKey('holiday_zone', 'pronote', '?') . ')',
                'result' => $nh ? $nh[2] . ' : ' . date('d/m', $nh[0]) . ' → ' . date('d/m', $nh[1]) . (self::inHoliday() ? __(' — synchro suspendue', __FILE__) : '') : __('Calendrier indisponible', __FILE__),
                'advice' => $nh ? '' : __('Vérifier l\'accès internet de Jeedom ; la synchro n\'est pas suspendue', __FILE__),
                'state' => (bool)$nh,
            );
        }
        foreach (self::byType('pronote', true) as $eqLogic) {
            $err = (string)$eqLogic->getCache('lastError', '');
            $token = $eqLogic->getSecret('credentials', '') !== '';
            $last = (int)$eqLogic->getCache('lastSync', 0);
            $ok = $token && $err === '';
            $return[] = array(
                'test' => $eqLogic->getName(),
                'result' => !$token ? __('Aucun jeton', __FILE__) : ($err !== '' ? $err : __('OK', __FILE__) . ($last ? ' — ' . date('d/m H:i', $last) : '')),
                'advice' => !$token ? __('Enrôler un QR Code', __FILE__) : ($err !== '' ? __('Voir le log pronote', __FILE__) : ''),
                'state' => $ok,
            );
        }
        return $return;
    }

    /* ------------------------------------------------------------------ */
    /* Cron                                                                */
    /* ------------------------------------------------------------------ */

    public static function cron15() {
        $delay = (int)config::byKey('call_delay', 'pronote', 5);
        $first = true;
        foreach (self::byType('pronote', true) as $eqLogic) {
            try {
                if (!$eqLogic->isDue()) {
                    continue;
                }
                // Deux élèves interrogés dans la même seconde, c'est le meilleur
                // moyen de se faire bloquer par Index Éducation.
                if (!$first && $delay > 0) {
                    sleep(min($delay, 60));
                }
                $first = false;
                $eqLogic->synchronize();
            } catch (Exception $e) {
                log::add('pronote', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /**
     * L'équipement doit-il être synchronisé maintenant ?
     * @param int|null $_now horodatage à considérer (pour les tests).
     */
    public function isDue($_now = null) {
        $now = ($_now === null) ? time() : (int)$_now;

        $freq = (int)$this->setting('frequency');
        if ($freq <= 0) {
            return false;
        }
        if (!self::inSyncWindow($now)) {
            return false;
        }
        if (self::inHoliday($now)) {
            return false;
        }
        $last = (int)$this->getCache('lastSync', 0);
        return (($now - $last) >= $this->nextDelay() - 30);
    }

    /**
     * Délai avant la prochaine tentative, en secondes : la fréquence choisie,
     * doublée à chaque échec consécutif (plafond 8 h). Insister toutes les
     * 30 min sur un jeton mort ne le ressuscite pas et expose au blocage.
     */
    public function nextDelay() {
        $freq = max(1, (int)$this->setting('frequency')) * 60;
        $fails = (int)$this->getCache('failCount', 0);
        return min($freq * pow(2, min($fails, 5)), 8 * 3600);
    }

    /**
     * L'horodatage tombe-t-il dans la plage horaire du plugin ?
     * Gère la plage qui traverse minuit (22:00 -> 06:00) : sans ce cas, la
     * plage était silencieusement ignorée et le plugin tournait 24 h/24.
     */
    public static function inSyncWindow($_timestamp = null) {
        $ts = ($_timestamp === null) ? time() : (int)$_timestamp;
        $start = trim((string)config::byKey('sync_start', 'pronote', '06:00'));
        $end   = trim((string)config::byKey('sync_end', 'pronote', '20:00'));
        if ($start === '' || $end === '') {
            return true; // plage non renseignée : aucune restriction
        }

        $toMinutes = function ($hhmm) {
            $parts = explode(':', $hhmm);
            if (count($parts) !== 2) {
                return null;
            }
            return ((int)$parts[0]) * 60 + ((int)$parts[1]);
        };
        $s = $toMinutes($start);
        $e = $toMinutes($end);
        if ($s === null || $e === null) {
            return true; // configuration illisible : on ne bloque pas
        }
        if ($s === $e) {
            return true; // plage dégénérée : traitée comme « toute la journée »
        }

        $now = ((int)date('G', $ts)) * 60 + ((int)date('i', $ts));
        if ($s < $e) {
            return ($now >= $s && $now <= $e);
        }
        // Plage à cheval sur minuit.
        return ($now >= $s || $now <= $e);
    }

    /* ------------------------------------------------------------------ */
    /* Synchronisation                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Appelle le script Python et applique le résultat aux commandes.
     * @return array le payload décodé (pour l'affichage dans l'IHM).
     */
    public function synchronize() {
        /* Verrou : une synchro manuelle qui chevauche le cron ferait deux
           connexions avec le même jeton — la seconde échoue et peut
           l'invalider. */
        $lockKey = 'pronote::sync::' . $this->getId();
        $lock = cache::byKey($lockKey);
        if (is_object($lock) && $lock->getValue(0) == 1) {
            return array('ok' => false, 'code' => 'busy', 'error' => __('Synchronisation déjà en cours', __FILE__));
        }
        cache::set($lockKey, 1, 180);

        try {
            $payload = $this->runFetch();
        } finally {
            cache::set($lockKey, 0, 1);
        }

        if (!isset($payload['ok']) || $payload['ok'] !== true) {
            $err = isset($payload['error']) ? $payload['error'] : 'erreur inconnue';
            $fails = (int)$this->getCache('failCount', 0) + 1;
            $this->setCache('failCount', $fails);
            $this->setCache('lastError', $err);
            $this->setCache('lastSync', time());
            // Niveau warning, pas error : Jeedom promeut chaque log « error » en
            // message du centre de messages (addMessageForErrorLog), ce qui
            // doublerait l'alerte throttlée ci-dessous et spammerait à chaque échec.
            log::add('pronote', 'warning', $this->getHumanName() . ' : ' . $err
                . ' (échec ' . $fails . ', prochaine tentative dans ' . round($this->nextDelay() / 60) . ' min)');
            $this->notifyOnce($payload, $err);
            return $payload;
        }

        // Le jeton tourne à chaque connexion : il faut le réécrire, sinon la
        // synchronisation suivante échouera.
        if (isset($payload['credentials']) && is_array($payload['credentials'])) {
            $this->setSecret('credentials', json_encode($payload['credentials']));
            $this->save(true);
        }

        $this->applyData(isset($payload['data']) ? $payload['data'] : array());

        $this->setCache('failCount', 0);
        $this->setCache('lastError', '');
        $this->setCache('lastNotified', '');
        $this->setCache('lastSync', time());
        log::add('pronote', 'info', $this->getHumanName() . ' : synchronisation OK');
        return $payload;
    }

    /** Une alerte par erreur distincte, et pas plus d'une toutes les 6 h. */
    protected function notifyOnce($payload, $err) {
        if (config::byKey('alert_on_auth_error', 'pronote', 1) != 1) {
            return;
        }
        if (!isset($payload['code']) || !in_array($payload['code'], array('auth', 'deps', 'config', 'suspended'))) {
            return;
        }
        $key = md5($err);
        $lastKey = (string)$this->getCache('lastNotified', '');
        $lastAt = (int)$this->getCache('lastNotifiedAt', 0);
        if ($lastKey === $key && (time() - $lastAt) < 6 * 3600) {
            return;
        }
        $this->setCache('lastNotified', $key);
        $this->setCache('lastNotifiedAt', time());
        message::add('pronote', __('Pronote — ', __FILE__) . $this->getName() . ' : ' . $err);
    }

    /** Exécute le script Python et décode sa sortie JSON. */
    public function runFetch($extra = array()) {
        /* Adresse IP suspendue par Pronote : toute tentative prolonge la
           suspension. On refuse de contacter Pronote pendant 30 min, cron ou
           clic compris — c'est le seul remède. */
        $until = (int)$this->getCache('suspendedUntil', 0);
        if ($until > time()) {
            return array('ok' => false, 'code' => 'suspended',
                         'error' => __('Adresse IP suspendue par Pronote : nouvelle tentative possible à ', __FILE__) . date('H:i', $until)
                                  . __('. Ne pas insister, chaque tentative prolonge la suspension.', __FILE__));
        }
        if (!file_exists(self::getPythonPath())) {
            return array('ok' => false, 'code' => 'deps', 'error' => __('Dépendances non installées (venv absent)', __FILE__));
        }

        $request = array_merge(array(
            'mode'        => $this->getConfiguration('mode', 'qr'),
            'url'         => trim($this->getConfiguration('url', '')),
            'ent'         => $this->getConfiguration('ent', ''),
            'username'    => $this->getConfiguration('username', ''),
            'password'    => $this->getSecret('password', ''),
            'credentials' => json_decode($this->getSecret('credentials', ''), true),
            'account'     => $this->getConfiguration('account', 'eleve'),
            'account_pin' => $this->getSecret('account_pin', ''),
            'child_name'  => trim((string)$this->getConfiguration('child_name', '')),
            'device_name' => (string)$this->setting('device_name'),
            'homework_days' => (int)$this->setting('homework_days'),
            'per_subject' => ($this->setting('per_subject') == 1),
            'skip_done'   => ($this->setting('skip_done') == 1),
            'data'        => $this->enabledData(),
            'log_level'   => config::byKey('log_level', 'pronote', 'info'),
        ), $extra);

        // Le fichier de requête porte le jeton : lisible par son propriétaire seul,
        // et supprimé quoi qu'il arrive.
        $tmp = jeedom::getTmpFolder('pronote') . '/req_' . $this->getId() . '_' . getmypid() . '.json';
        file_put_contents($tmp, json_encode($request));
        @chmod($tmp, 0600);

        // Borne dure : un serveur Pronote qui ne répond plus ne doit pas bloquer
        // le cron. Une synchro normale prend une à deux secondes.
        $limit = max(30, (int)config::byKey('fetch_timeout', 'pronote', 120));
        $cmd = 'timeout ' . (int)$limit . ' '
             . escapeshellarg(self::getPythonPath()) . ' ' . escapeshellarg(self::getScriptPath())
             . ' --request ' . escapeshellarg($tmp) . ' 2>>' . escapeshellarg(log::getPathToLog('pronote'));

        try {
            $raw = shell_exec($cmd);
        } finally {
            @unlink($tmp);
        }
        if ($raw === null || trim((string)$raw) === '') {
            return array('ok' => false, 'code' => 'network',
                         'error' => __('Pronote n\'a pas répondu dans le délai (', __FILE__) . $limit . ' s)');
        }

        if (config::byKey('debug_raw', 'pronote', 0) == 1) {
            // Écriture directe : log::add() est filtré par le niveau de log.
            // Le jeton est expurgé : un log se copie-colle sur un forum.
            $safe = preg_replace('/"credentials":\s*\{[^}]*\}/', '"credentials": "(masqué)"', (string)$raw);
            @file_put_contents(
                log::getPathToLog('pronote_raw'),
                '[' . date('Y-m-d H:i:s') . '] ' . $this->getHumanName() . ' : '
                    . substr($safe, 0, 4000) . PHP_EOL,
                FILE_APPEND
            );
        }

        $payload = json_decode((string)$raw, true);
        if (is_array($payload) && isset($payload['code']) && $payload['code'] === 'suspended') {
            $this->setCache('suspendedUntil', time() + 30 * 60);
        }
        if (!is_array($payload)) {
            log::add('pronote', 'error', 'Sortie Python illisible : ' . substr((string)$raw, 0, 500));
            return array('ok' => false, 'code' => 'script', 'error' => __('Réponse illisible du script Python (voir le log)', __FILE__));
        }
        return $payload;
    }

    /** Liste des blocs de données activés sur cet équipement. */
    public function enabledData() {
        $out = array();
        foreach (self::dataBlocks() as $key => $label) {
            if ($this->getConfiguration('data_' . $key, 0) == 1) {
                $out[] = $key;
            }
        }
        return $out;
    }

    public static function dataBlocks() {
        return array(
            'notes'       => 'Notes et moyennes',
            'devoirs'     => 'Devoirs à faire',
            'edt'         => 'Emploi du temps',
            'absences'    => 'Absences et retards',
            'vie'         => 'Vie scolaire',
            'punitions'   => 'Punitions',
            'cantine'     => 'Menus de la cantine',
            'competences' => 'Compétences',
        );
    }

    /** Initiales d'un prénom/nom, pour les avatars. */
    public static function initials($name) {
        $parts = preg_split('/[\s\-]+/u', trim((string)$name));
        $out = '';
        foreach ($parts as $p) {
            if ($p !== '') {
                $out .= mb_strtoupper(mb_substr($p, 0, 1));
            }
            if (mb_strlen($out) >= 2) {
                break;
            }
        }
        return $out === '' ? '?' : $out;
    }

    /** Teinte stable (0-359) dérivée d'un nom, même formule que le widget. */
    public static function hue($name) {
        $h = 0;
        $name = mb_strtolower((string)$name);
        for ($i = 0; $i < mb_strlen($name); $i++) {
            $h = ($h * 31 + mb_ord(mb_substr($name, $i, 1))) % 360;
        }
        return $h;
    }

    /** Écrit les valeurs reçues dans les commandes correspondantes. */
    public function applyData($data) {
        /* Fiche élève : classe et établissement remontés de Pronote. */
        if (isset($data['_meta']) && is_array($data['_meta'])) {
            $changed = false;
            foreach (array('name' => 'student_name', 'class_name' => 'student_class', 'establishment' => 'establishment') as $k => $conf) {
                if (!empty($data['_meta'][$k]) && $this->getConfiguration($conf, '') !== $data['_meta'][$k]) {
                    $this->setConfiguration($conf, $data['_meta'][$k]);
                    $changed = true;
                }
            }
            if ($changed) {
                $this->save(true);
            }
        }

        foreach ($data as $logicalId => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                continue;
            }
            $cmd->event($value);
        }

        /* Événement « nouvelle note » : binaire à 1 quand le nombre de notes a
           augmenté depuis la synchro précédente, 0 sinon — un scénario se
           déclenche sur le passage à 1. */
        if (isset($data['_grades_count'])) {
            $count = (int)$data['_grades_count'];
            $prev = $this->getCache('gradesCount', null);
            $evt = $this->getCmd(null, 'grade_new_event');
            if (is_object($evt)) {
                $evt->event(($prev !== null && $count > (int)$prev) ? 1 : 0);
            }
            $this->setCache('gradesCount', $count);
        }

        // Détail par matière : commandes créées à la volée si l'option est active.
        if ($this->setting('per_subject') == 1 && isset($data['_subjects']) && is_array($data['_subjects'])) {
            foreach ($data['_subjects'] as $subject) {
                if (!isset($subject['logicalId'])) {
                    continue;
                }
                $slug = isset($subject['slug']) ? $subject['slug'] : substr($subject['logicalId'], strlen('avg_subject_'));
                $this->subjectCmd('avg_subject_' . $slug, 'Moyenne — ' . $subject['name'], 'numeric', '/20', 1, 1)
                     ->event($subject['value']);
                if (isset($subject['class_value']) && $subject['class_value'] !== null) {
                    $this->subjectCmd('avg_class_subject_' . $slug, 'Classe — ' . $subject['name'], 'numeric', '/20', 1, 0)
                         ->event($subject['class_value']);
                }
                if (!empty($subject['last_grade'])) {
                    $this->subjectCmd('last_grade_subject_' . $slug, 'Dernière note — ' . $subject['name'], 'string', '', 0, 0)
                         ->event($subject['last_grade']);
                }
            }
        }
    }

    /** Commande par matière, créée si absente (réglages utilisateur conservés). */
    protected function subjectCmd($logicalId, $name, $subType, $unit, $hist, $visible) {
        $cmd = $this->getCmd(null, $logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }
        $cmd = new pronoteCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($logicalId);
        $cmd->setName(substr($name, 0, 45));
        $cmd->setType('info');
        $cmd->setSubType($subType);
        if ($unit !== '') {
            $cmd->setUnite($unit);
        }
        $cmd->setIsHistorized($hist);
        $cmd->setIsVisible($visible);
        $cmd->save();
        return $cmd;
    }

    /* ------------------------------------------------------------------ */
    /* Vacances scolaires (calendrier officiel, data.education.gouv.fr)    */
    /* ------------------------------------------------------------------ */

    const HOLIDAY_API = 'https://data.education.gouv.fr/api/explore/v2.1/catalog/datasets/fr-en-calendrier-scolaire/records';

    /**
     * Périodes de vacances de la zone configurée : liste de [début, fin]
     * (timestamps), mise en cache 7 jours. Rend un tableau vide si l'API ne
     * répond pas — on ne suspend jamais « au cas où ».
     */
    public static function holidayRanges($_force = false) {
        $zone = trim((string)config::byKey('holiday_zone', 'pronote', ''));
        if ($zone === '') {
            return array();
        }
        $key = 'pronote::holidays::' . $zone;
        if (!$_force) {
            $c = cache::byKey($key);
            if (is_object($c) && is_array($c->getValue(null))) {
                return $c->getValue();
            }
        }
        $since = date('Y-m-d', time() - 60 * 86400);
        $url = self::HOLIDAY_API . '?' . http_build_query(array(
            'where' => 'zones="Zone ' . $zone . '" AND end_date>="' . $since . '"',
            'select' => 'description,start_date,end_date',
            'order_by' => 'start_date',
            'limit' => 100,
        ));
        $ctx = stream_context_create(array('http' => array('timeout' => 10, 'ignore_errors' => true)));
        $raw = @file_get_contents($url, false, $ctx);
        $json = json_decode((string)$raw, true);
        $ranges = array();
        if (is_array($json) && isset($json['results'])) {
            $seen = array();
            foreach ($json['results'] as $r) {
                /* Les horodatages de l'API sont en UTC (« 2027-05-05T22:00:00+00:00 »
                   = 06/05 à minuit, heure de Paris) : on les lit avec leur fuseau,
                   puis on cale chaque borne sur la journée locale entière. */
                $a = strtotime((string)($r['start_date'] ?? ''));
                $b = strtotime((string)($r['end_date'] ?? ''));
                if (!$a || !$b) {
                    continue;
                }
                $desc = (string)($r['description'] ?? '');
                /* start_date = minuit du premier jour de vacances ; end_date =
                   l'INSTANT de la reprise (minuit du jour de rentrée), qu'il
                   faut exclure. Un marqueur d'un seul jour (« Pont de
                   l'Ascension », « Début des Vacances d'Été ») a une fin égale
                   au début : il couvre sa journée, ou tout l'été. */
                $a = mktime(0, 0, 0, (int)date('n', $a), (int)date('j', $a), (int)date('Y', $a));
                if ($b <= $a) {
                    if (stripos($desc, 'Été') !== false || stripos($desc, 'ete') !== false) {
                        $year = (int)date('n', $a) >= 6 ? (int)date('Y', $a) : (int)date('Y', $a) - 1;
                        $b = mktime(23, 59, 59, 8, 31, $year);
                    } else {
                        $b = $a + 86399;
                    }
                } else {
                    $b = $b - 1;   // 02/11 00:00 (reprise) -> 01/11 23:59:59
                }
                $sig = $a . '-' . $b;   // une ligne par académie : on dédoublonne
                if (isset($seen[$sig])) {
                    continue;
                }
                $seen[$sig] = true;
                $ranges[] = array($a, $b, $desc);
            }
            usort($ranges, function ($x, $y) { return $x[0] - $y[0]; });
            cache::set($key, $ranges, 7 * 86400);
        } else {
            log::add('pronote', 'warning', 'Calendrier des vacances indisponible (zone ' . $zone . ') : synchronisation non suspendue');
        }
        return $ranges;
    }

    /** Sommes-nous en vacances scolaires ? (jamais vrai si la fonction est désactivée) */
    public static function inHoliday($_ts = null) {
        if (config::byKey('suspend_holidays', 'pronote', 0) != 1) {
            return false;
        }
        $ts = ($_ts === null) ? time() : (int)$_ts;
        foreach (self::holidayRanges() as $r) {
            if ($ts >= $r[0] && $ts <= $r[1]) {
                return true;
            }
        }
        return false;
    }

    /** Prochaine période de vacances : [début, fin, libellé] ou null. */
    public static function nextHoliday() {
        foreach (self::holidayRanges() as $r) {
            if ($r[1] >= time()) {
                return $r;
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Cycle de vie de l'équipement                                        */
    /* ------------------------------------------------------------------ */

    public function preSave() {
        if (trim($this->getConfiguration('url', '')) === '' && $this->getConfiguration('mode', 'qr') !== 'qr') {
            throw new Exception(__('L\'URL de l\'espace élève est obligatoire', __FILE__));
        }
        $this->encryptSecrets();
    }

    public function postSave() {
        $this->syncCommands();
    }

    /** Catalogue des commandes : [logicalId, nom, type, sousType, unité, historisé, visible, bloc] */
    public static function commandCatalog() {
        return array(
            array('refresh',            'Rafraîchir les données',   'action', 'other',   '',    0, 1, 'core'),
            array('last_sync',          'Dernière synchronisation', 'info',   'string',  '',    0, 1, 'core'),

            array('avg_general',        'Moyenne générale',         'info',   'numeric', '/20', 1, 1, 'notes'),
            array('avg_class',          'Moyenne de la classe',     'info',   'numeric', '/20', 1, 0, 'notes'),
            array('last_grade',         'Dernière note',            'info',   'string',  '',    0, 1, 'notes'),
            array('new_grades',         'Nouvelles notes (24 h)',   'info',   'numeric', '',    1, 1, 'notes'),
            array('grade_new_event',    'Nouvelle note (événement)','info',   'binary',  '',    1, 0, 'notes'),

            array('homework_count',     'Devoirs à faire',          'info',   'numeric', '',    1, 1, 'devoirs'),
            array('homework_tomorrow',  'Devoirs pour demain',      'info',   'numeric', '',    0, 1, 'devoirs'),
            array('homework_html',      'Devoirs (détail)',         'info',   'string',  '',    0, 1, 'devoirs'),
            array('homework_tomorrow_pending', 'Devoir pour demain non fait', 'info', 'binary', '', 1, 0, 'devoirs'),

            array('next_course',        'Prochain cours',           'info',   'string',  '',    0, 1, 'edt'),
            array('next_course_start',  'Début du prochain cours',  'info',   'string',  '',    0, 0, 'edt'),
            array('timetable_html',     'Emploi du temps du jour',  'info',   'string',  '',    0, 1, 'edt'),
            array('timetable_tomorrow_html', 'Emploi du temps de demain', 'info', 'string', '', 0, 0, 'edt'),
            array('timetable_week_html', 'Emploi du temps (7 jours)', 'info', 'string', '', 0, 0, 'edt'),
            array('course_cancelled',   'Cours annulé aujourd\'hui','info',   'binary',  '',    1, 1, 'edt'),
            array('course_cancelled_tomorrow', 'Cours annulé demain', 'info', 'binary', '',    1, 0, 'edt'),

            array('absences',           'Absences de la période',   'info',   'numeric', 'h',   1, 1, 'absences'),
            array('delays',             'Retards de la période',    'info',   'numeric', '',    1, 1, 'absences'),

            array('punishments',        'Punitions',                'info',   'numeric', '',    1, 0, 'punitions'),
            array('new_messages',       'Nouveaux messages',        'info',   'numeric', '',    0, 1, 'vie'),
            array('menu_today',         'Menu du jour',             'info',   'string',  '',    0, 1, 'cantine'),
            array('menu_tomorrow',      'Menu de demain',           'info',   'string',  '',    0, 0, 'cantine'),
            array('skills_html',        'Compétences (détail)',     'info',   'string',  '',    0, 1, 'competences'),
        );
    }

    /**
     * Crée les commandes des blocs activés, supprime celles des blocs désactivés.
     * Les réglages faits à la main (historisation, visibilité) sont conservés :
     * on ne touche qu'aux commandes qui apparaissent ou disparaissent.
     */
    public function syncCommands() {
        $enabled = $this->enabledData();
        $enabled[] = 'core';
        $keep = array();

        foreach (self::commandCatalog() as $c) {
            list($logicalId, $name, $type, $subType, $unit, $hist, $visible, $block) = $c;
            $cmd = $this->getCmd(null, $logicalId);

            if (!in_array($block, $enabled)) {
                if (is_object($cmd)) {
                    $cmd->remove();
                }
                continue;
            }

            $keep[] = $logicalId;
            if (is_object($cmd)) {
                continue; // déjà là : on respecte les réglages de l'utilisateur
            }

            $cmd = new pronoteCmd();
            $cmd->setEqLogic_id($this->getId());
            $cmd->setLogicalId($logicalId);
            $cmd->setName(__($name, __FILE__));
            $cmd->setType($type);
            $cmd->setSubType($subType);
            if ($unit !== '') {
                $cmd->setUnite($unit);
            }
            $cmd->setIsHistorized($hist);
            $cmd->setIsVisible($visible);
            $cmd->save();
        }

        // Moyennes par matière : nettoyage si l'option est coupée.
        if ($this->setting('per_subject') != 1) {
            foreach ($this->getCmd() as $cmd) {
                foreach (array('avg_subject_', 'avg_class_subject_', 'last_grade_subject_') as $prefix) {
                    if (strpos($cmd->getLogicalId(), $prefix) === 0) {
                        $cmd->remove();
                        break;
                    }
                }
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Widget                                                              */
    /* ------------------------------------------------------------------ */

    public function toHtml($_version = 'dashboard') {
        if (config::byKey('custom_widget', 'pronote', 1) != 1) {
            return parent::toHtml($_version);
        }

        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);

        $values = array();
        foreach ($this->getCmd('info') as $cmd) {
            $values[$cmd->getLogicalId()] = $cmd->execCmd();
        }
        $get = function ($key, $default = '') use ($values) {
            if (!isset($values[$key])) {
                return $default;
            }
            $v = $values[$key];
            return ($v === '' || $v === null) ? $default : $v;
        };

        /* Chiffres principaux. Une moyenne vide veut dire « pas encore de
           note » : afficher 0/20 en début d'année serait un mensonge. */
        $moyenne = $get('avg_general', '');
        $aDesNotes = ($get('last_grade', '') !== '');
        $replace['#moyenne#'] = ($moyenne === '' || !$aDesNotes) ? '—' : str_replace('.', ',', (string)$moyenne);
        $replace['#devoirsNb#'] = (string)$get('homework_count', '0');
        $absences = $get('absences', '0');
        $replace['#absences#'] = (is_numeric($absences) ? rtrim(rtrim(number_format((float)$absences, 1, ',', ''), '0'), ',') : $absences) . ' h';

        /* Pastilles : uniquement ce qui mérite l'attention. */
        $pill = function ($text, $color) {
            return '<span class="pw-pill" style="background:color-mix(in srgb, ' . $color . ' 18%, transparent);color:' . $color . '">'
                 . htmlspecialchars($text) . '</span>';
        };
        $badges = array();
        if ((int)$get('homework_tomorrow', 0) > 0) {
            $badges[] = $pill((int)$get('homework_tomorrow') . ' pour demain', 'var(--al-warning-color)');
        }
        if ((int)$get('new_grades', 0) > 0) {
            $n = (int)$get('new_grades');
            $badges[] = $pill($n . ($n > 1 ? ' nouvelles notes' : ' nouvelle note'), 'var(--al-info-color)');
        }
        if ((int)$get('new_messages', 0) > 0) {
            $badges[] = $pill((int)$get('new_messages') . ' message(s)', 'var(--al-info-color)');
        }
        if ((int)$get('course_cancelled', 0) == 1) {
            $badges[] = $pill('cours annulé', 'var(--al-danger-color)');
        }
        if ((int)$get('delays', 0) > 0) {
            $badges[] = $pill((int)$get('delays') . ' retard(s)', 'var(--al-warning-color)');
        }
        $replace['#badges#'] = empty($badges) ? ''
            : '<div class="pw-badges">' . implode('', $badges) . '</div>';

        /* Emploi du temps : un jour à la fois, navigation ◀ ▶ dans la tuile.
           La commande « 7 jours » porte un <ul> par jour ; à défaut (ancienne
           synchro) on retombe sur aujourd'hui / demain. */
        $semaine = (string)$get('timetable_week_html', '');
        if (strpos($semaine, 'data-date=') === false) {
            $jours = array('dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi');
            $mois  = array('', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.');
            $lib = function ($ts) use ($jours, $mois) {
                return $jours[(int)date('w', $ts)] . ' ' . (int)date('j', $ts) . ' ' . $mois[(int)date('n', $ts)];
            };
            $ul = function ($html, $ts, $rel, $today) use ($lib) {
                $inner = (trim(strip_tags($html)) === '') ? '' : preg_replace('/^<ul[^>]*>|<\/ul>$/', '', trim($html));
                return '<ul class="pronote-edt" data-date="' . date('Y-m-d', $ts) . '" data-label="' . $lib($ts)
                     . '" data-rel="' . $rel . '"' . ($today ? ' data-today="1"' : '') . '>' . $inner . '</ul>';
            };
            $semaine = $ul((string)$get('timetable_html', ''), time(), 'aujourd\'hui', true)
                     . $ul((string)$get('timetable_tomorrow_html', ''), time() + 86400, 'demain', false);
        }
        $replace['#cours#'] =
            '<div class="pw-days"></div>'
          . '<div class="pw-tl"></div>'
          . '<div class="pw-src" hidden>' . $semaine . '</div>';

        /* Devoirs. */
        $devoirs = (string)$get('homework_html', '');
        if (trim(strip_tags($devoirs)) === '') {
            $replace['#devoirs#'] = '';
            $replace['#devoirsSrc#'] = '';
        } else {
            // Mobile : liste visible. Dashboard : source cachée, le widget
            // rattache chaque devoir au jour où il est à rendre dans l'agenda.
            $replace['#devoirs#'] = '<div class="pw-label">À faire</div>' . $devoirs;
            $replace['#devoirsSrc#'] = '<div class="pw-hwsrc" hidden>' . $devoirs . '</div>';
        }

        /* Cantine : affichée dès que le bloc remonte quelque chose. */
        $menu = trim((string)$get('menu_today', ''));
        $menuDemain = trim((string)$get('menu_tomorrow', ''));
        $cantine = '';
        if ($menu !== '' || $menuDemain !== '') {
            $cantine = '<div class="pw-label">Cantine</div><div class="pw-menu">';
            if ($menu !== '') {
                $cantine .= '<div><small>aujourd\'hui</small>' . htmlspecialchars($menu) . '</div>';
            }
            if ($menuDemain !== '') {
                $cantine .= '<div><small>demain</small>' . htmlspecialchars($menuDemain) . '</div>';
            }
            $cantine .= '</div>';
        }
        $replace['#cantine#'] = $cantine;

        /* Mobile : le prochain cours en une ligne. */
        $nc = trim((string)$get('next_course', ''));
        $ncs = trim((string)$get('next_course_start', ''));
        $replace['#nextLabel#'] = ($ncs !== '' ? 'Prochain cours · ' . htmlspecialchars($ncs) : 'Prochain cours');
        $replace['#nextCourse#'] = htmlspecialchars($nc !== '' ? $nc : 'Aucun cours à venir');

        /* Bandeau d'erreur : un jeton expiré doit se voir sans ouvrir les logs. */
        $erreur = (string)$this->getCache('lastError', '');
        $replace['#alerte#'] = ($erreur === '') ? ''
            : '<div class="pw-alert">'
            . '<i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars(substr($erreur, 0, 120)) . '</div>';
        if ($erreur === '' && self::inHoliday()) {
            $nh = self::nextHoliday();
            $replace['#alerte#'] = '<div class="pw-alert pw-info"><i class="fas fa-umbrella-beach"></i> '
                . htmlspecialchars($nh ? $nh[2] : 'Vacances') . ' — synchronisation suspendue'
                . ($nh ? ' jusqu\'au ' . date('d/m', $nh[1]) : '') . '</div>';
        }

        $sync = (string)$get('last_sync', 'jamais');
        if (strpos($sync, date('d/m/Y')) === 0) {
            $sync = 'à ' . trim(substr($sync, 10));
        }
        $replace['#lastSync#'] = htmlspecialchars($sync);

        return template_replace($replace, getTemplate('core', $version, 'pronote.eqLogic', 'pronote'));
    }

}

class pronoteCmd extends cmd {

    public function dontRemoveCmd() {
        /* Toutes les commandes de ce plugin sont générées automatiquement à
         * partir des cases cochées. Jeedom supprime à la sauvegarde toute
         * commande absente du tableau envoyé par le navigateur : sans cette
         * protection, une simple sauvegarde vidait l'équipement de ses
         * commandes (et de leur historique). Seul syncCommands() décide des
         * suppressions, quand l'utilisateur décoche un bloc.
         */
        $logicalId = $this->getLogicalId();
        foreach (array('avg_subject_', 'avg_class_subject_', 'last_grade_subject_') as $prefix) {
            if (strpos($logicalId, $prefix) === 0) {
                return true;
            }
        }
        foreach (pronote::commandCatalog() as $c) {
            if ($c[0] === $logicalId) {
                return true;
            }
        }
        return false;
    }

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if ($this->getLogicalId() === 'refresh') {
            $eqLogic->synchronize();
        }
    }
}
