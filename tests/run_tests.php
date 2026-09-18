<?php
/* Suite de tests du plugin Pronote.
 *
 *   sudo -u www-data php plugins/pronote/tests/run_tests.php
 *
 * Rejouable : elle repart d'un état propre et supprime ses équipements à la fin.
 * Elle ne contacte jamais Pronote — l'authentification réelle ne peut être
 * testée qu'avec un compte, manuellement.
 */

require_once __DIR__ . '/../../../core/php/core.inc.php';

/* Un widget ne se rend pas sans utilisateur : Jeedom résout le thème via la session. */
foreach (user::all() as $u) {
    if ($u->getProfils() === 'admin') { $_SESSION['user'] = $u; break; }
}

$FAIL = 0;
$COUNT = 0;
function t($label, $ok, $detail = '') {
    global $FAIL, $COUNT;
    $COUNT++;
    if (!$ok) { $FAIL++; }
    echo ($ok ? "  ok    " : "  ECHEC ") . "| " . $label . ($detail !== '' ? "  ->  " . $detail : "") . "\n";
}
function section($n) { echo "\n" . $n . "\n" . str_repeat('-', strlen($n)) . "\n"; }

/* --- état de départ propre ------------------------------------------------ */
foreach (array('__selftest_pronote', '__selftest_pronote_2') as $lid) {
    $old = eqLogic::byLogicalId($lid, 'pronote');
    if (is_object($old)) { $old->remove(); }
}
$savedDelay  = config::byKey('call_delay', 'pronote', 5);
$savedWidget = config::byKey('custom_widget', 'pronote', 0);
$savedRaw    = config::byKey('debug_raw', 'pronote', 0);

section('1. Plugin et dépendances');
$plugin = plugin::byId('pronote');
if (!is_object($plugin)) { echo "plugin introuvable\n"; exit(1); }
if (!$plugin->isActive()) { $plugin->setIsEnable(1); }
t('plugin actif', plugin::byId('pronote')->isActive());
$dep = pronote::dependancy_info();
t('dépendances OK', $dep['state'] === 'ok', $dep['state']);
t('pronotepy importable', pronote::pythonReady());
t('version pronotepy connue', pronote::pronotepyVersion() !== '', pronote::pronotepyVersion());

section('2. Création de l\'équipement et des commandes');
$eq = new pronote();
$eq->setName('AUTOTEST Pronote');
$eq->setLogicalId('__selftest_pronote');
$eq->setEqType_name('pronote');
$eq->setIsEnable(1);
$eq->setIsVisible(1);
$eq->setConfiguration('mode', 'qr');
$eq->setConfiguration('url', 'https://0450001a.index-education.net/pronote/eleve.html');
$eq->setConfiguration('frequency', 30);
$eq->setConfiguration('homework_days', 7);
$eq->setConfiguration('per_subject', 1);
foreach (array('notes', 'devoirs', 'edt', 'absences', 'vie') as $b) { $eq->setConfiguration('data_' . $b, 1); }
foreach (array('punitions', 'cantine', 'competences') as $b) { $eq->setConfiguration('data_' . $b, 0); }
$eq->save();
$eq = eqLogic::byId($eq->getId());
t('équipement sauvegardé', is_object($eq), 'id=' . $eq->getId());

$ids = array();
foreach ($eq->getCmd() as $c) { $ids[] = $c->getLogicalId(); }
sort($ids);
$expected = 0;
foreach (pronote::commandCatalog() as $c) { if (in_array($c[7], array('core', 'notes', 'devoirs', 'edt', 'absences', 'vie'))) { $expected++; } }
t('commandes créées (catalogue des blocs cochés)', count($ids) === $expected, count($ids) . '/' . $expected . ' : ' . implode(', ', $ids));
t('commandes de période et vacances (toujours présentes)', in_array('period_name', $ids) && in_array('next_holiday_start', $ids));
t('refresh présent', in_array('refresh', $ids));
t('bloc notes présent', in_array('avg_general', $ids));
t('bloc décoché absent (punitions)', !in_array('punishments', $ids));
t('bloc décoché absent (cantine)', !in_array('menu_today', $ids));

section('3. Jeu d\'essai : script Python vers commandes');
$raw = shell_exec(escapeshellarg(pronote::getPythonPath()) . ' ' . escapeshellarg(pronote::getScriptPath()) . ' --selftest 2>&1');
$payload = json_decode((string)$raw, true);
t('sortie JSON valide', is_array($payload) && !empty($payload['ok']));
$eq->applyData($payload['data']);
$eq = eqLogic::byId($eq->getId());
$get = function ($lid) use ($eq) { $c = $eq->getCmd(null, $lid); return is_object($c) ? $c->execCmd() : null; };
t('avg_general écrit', $get('avg_general') == 14.7, (string)$get('avg_general'));
t('homework_count écrit', (int)$get('homework_count') > 0, (string)$get('homework_count'));
t('période écrite', $get('period_name') != '' && (int)$get('period_progress') >= 0, $get('period_name') . ' ' . $get('period_progress') . '%');
t('prochaines vacances écrites', preg_match('/^\d{2}\/\d{2}\/\d{4}$/', (string)$get('next_holiday_start')) === 1, (string)$get('next_holiday_start'));
t('dernières notes (HTML) écrites', strpos((string)$get('grades_html'), 'pronote-grades') !== false);
t('messagerie et informations écrites', strpos((string)$get('messages_html'), 'unread') !== false && (int)$get('new_infos') === 1);
t('absences détaillées écrites', strpos((string)$get('absences_html'), 'non justifiée') !== false && (int)$get('absences_unjustified') === 1);
$store = $eq->getData();
t('données structurées enregistrées (cours, devoirs, notes, vacances)',
   isset($store['_lessons'], $store['_homework'], $store['_grades'], $store['_holidays']) && count($store['_lessons']) > 10,
   isset($store['_lessons']) ? count($store['_lessons']) . ' cours, ' . count($store['_homework']) . ' devoirs' : 'absentes');
t('fichier de données protégé (0600, .htaccess)', (fileperms($eq->dataFile()) & 0777) === 0600 && file_exists(pronote::dataDir() . '/.htaccess'));
t('briefing du soir en français', preg_match('/^(Demain|Lundi|Mardi)/u', (string)$get('briefing_evening')) === 1 && strpos((string)$get('briefing_evening'), 'Léa') !== false, mb_substr((string)$get('briefing_evening'), 0, 70));
t('briefing du matin', strpos((string)$get('briefing_morning'), 'Léa') !== false, mb_substr((string)$get('briefing_morning'), 0, 70));
t('prochain contrôle et bilan hebdo', (string)$get('next_test') !== '' && strpos((string)$get('weekly_summary'), 'Moyenne générale') !== false, (string)$get('next_test'));
t('dernier événement lisible', strpos((string)$get('last_event'), 'Nouvelle note') !== false, (string)$get('last_event'));
$tomorrowIsSchool = (int)date('N', strtotime('+1 day')) <= 5;
t('réveil demain cohérent avec le calendrier', $tomorrowIsSchool ? preg_match('/^\d{2}:\d{2}$/', (string)$get('wake_time_tomorrow')) === 1 : ((string)$get('wake_time_tomorrow') === '' && (int)$get('no_school_tomorrow') === 1),
   'demain ' . ($tomorrowIsSchool ? 'école, réveil ' . $get('wake_time_tomorrow') : 'pas école'));
t('première synchro : aucune matière en baisse', (string)$get('subjects_declining') === '' && (int)$get('avg_down_event') === 0);
$again = $payload['data'];
$again['_subjects'][0]['value'] = 13.9; // Mathématiques 15,2 -> 13,9
$eq->applyData($again);
$eq = eqLogic::byId($eq->getId());
t('matière en baisse détectée', strpos((string)$get('subjects_declining'), 'Mathématiques') !== false && (int)$get('avg_down_event') === 1, (string)$get('subjects_declining'));
$eq->applyData($payload['data']);
$eq = eqLogic::byId($eq->getId());
t('remontée : plus de matière en baisse', (string)$get('subjects_declining') === '');
t('next_course écrit', $get('next_course') != '', (string)$get('next_course'));
$sub = $eq->getCmd(null, 'avg_subject_mathematiques');
t('moyenne par matière créée à la volée', is_object($sub), is_object($sub) ? $sub->getName() . ' = ' . $sub->execCmd() : 'absente');

section('4. Les cases pilotent bien les commandes');
$eq->setConfiguration('data_absences', 0);
$eq->save();
$eq = eqLogic::byId($eq->getId());
$ids2 = array();
foreach ($eq->getCmd() as $c) { $ids2[] = $c->getLogicalId(); }
t('commandes du bloc décoché supprimées', !in_array('absences', $ids2) && !in_array('delays', $ids2));
t('les autres commandes survivent', in_array('avg_general', $ids2));
$eq->setConfiguration('data_absences', 1);
$eq->save();
$eq = eqLogic::byId($eq->getId());
$ids3 = array();
foreach ($eq->getCmd() as $c) { $ids3[] = $c->getLogicalId(); }
t('recochage recrée les commandes', in_array('absences', $ids3));

section('5. Chemins d\'erreur');
$r = $eq->runFetch(array('mode' => 'ent', 'ent' => 'ent_inexistant', 'username' => 'a', 'password' => 'b', 'credentials' => null));
t('ENT inconnu -> code config', isset($r['code']) && $r['code'] === 'config');
t('ENT inconnu -> liste des ENT possibles', isset($r['error']) && strpos($r['error'], 'Valeurs possibles') !== false);
$r = $eq->runFetch(array('mode' => 'qr', 'credentials' => null, 'qr_json' => null));
t('aucun jeton -> code auth', isset($r['code']) && $r['code'] === 'auth');
$r = $eq->runFetch(array('mode' => 'qr', 'credentials' => null, 'qr_json' => array('mauvaise' => 'forme')));
t('QR mal formé -> code config', isset($r['code']) && $r['code'] === 'config');
$r = $eq->runFetch(array('mode' => 'password', 'url' => '', 'credentials' => null));
t('URL manquante -> code config', isset($r['code']) && $r['code'] === 'config');

section('6. Réponses brutes journalisées');
config::save('debug_raw', 1, 'pronote');
@unlink(log::getPathToLog('pronote_raw'));
$eq->runFetch(array('mode' => 'qr', 'credentials' => null));
clearstatcache();
t('log pronote_raw alimenté', (int)@filesize(log::getPathToLog('pronote_raw')) > 0,
   (int)@filesize(log::getPathToLog('pronote_raw')) . ' octets');
config::save('debug_raw', 0, 'pronote');

section('7. Cron');
/* Indépendant de l'heure et du calendrier : plage horaire ouverte et vacances
   coupées le temps de la section (la plage elle-même est testée en section 9). */
$sStart = config::byKey('sync_start', 'pronote', '06:00'); $sEnd = config::byKey('sync_end', 'pronote', '20:00');
$sSusp = config::byKey('suspend_holidays', 'pronote', 0);
config::save('sync_start', '00:00', 'pronote'); config::save('sync_end', '23:59', 'pronote'); config::save('suspend_holidays', 0, 'pronote');
$sMode = config::byKey('sync_mode', 'pronote', ''); $sTimes = config::byKey('sync_times', 'pronote', '');
config::save('sync_mode', 'interval', 'pronote');
$eq->setCache('lastSync', 0);
t('isDue() vrai sans synchro précédente', $eq->isDue());
$eq->setCache('lastSync', time());
t('isDue() faux juste après une synchro', !$eq->isDue());
$eq->setConfiguration('frequency', 0);
$eq->save();
$eq = eqLogic::byId($eq->getId());
$eq->setCache('lastSync', 0);
t('fréquence « manuelle » -> jamais dû', !$eq->isDue());
$eq->setConfiguration('frequency', 30);
$eq->save();
$eq = eqLogic::byId($eq->getId());

$eq2 = new pronote();
$eq2->setName('AUTOTEST Pronote 2');
$eq2->setLogicalId('__selftest_pronote_2');
$eq2->setEqType_name('pronote');
$eq2->setIsEnable(1);
$eq2->setConfiguration('mode', 'qr');
$eq2->setConfiguration('frequency', 30);
$eq2->setConfiguration('data_notes', 1);
$eq2->save();
config::save('call_delay', 3, 'pronote');
$eq->setCache('lastSync', 0);
$eq2->setCache('lastSync', 0);
$t0 = microtime(true);
pronote::cron15();
$elapsed = microtime(true) - $t0;
t('cron15 espace deux élèves du délai configuré', $elapsed >= 3, round($elapsed, 2) . ' s');
t('cron15 ne lève pas d\'exception', true);

/* heures fixes */
config::save('sync_mode', 'times', 'pronote');
$eq->setConfiguration('frequency', null); $eq->save(true); $eq = eqLogic::byId($eq->getId());
$at = function ($h, $m = 0) { return mktime($h, $m, 0, (int)date('n'), (int)date('j'), (int)date('Y')); };
config::save('sync_times', '06:30, 12:00, 16:30, 20:00', 'pronote');
t('4 créneaux lus', count($eq->syncSlots()) === 4, implode(',', $eq->syncSlots()));
$eq->setCache('failCount', 0); $eq->setCache('lastSync', $at(5));
t('05:45 : avant le premier créneau -> pas dû', !$eq->isDue($at(5, 45)));
t('06:30 : créneau passé, pas synchronisé depuis -> dû', $eq->isDue($at(6, 30)));
$eq->setCache('lastSync', $at(6, 31));
t('07:00 : déjà synchronisé pour ce créneau -> pas dû', !$eq->isDue($at(7)));
t('12:00 : créneau suivant -> dû', $eq->isDue($at(12)));
$eq->setCache('lastSync', $at(12, 2));
t('15:00 : rien entre 12:00 et 16:30 -> pas dû', !$eq->isDue($at(15)));
$eq->setCache('failCount', 2);
t('après échec : réessai selon le repli, sans attendre le créneau', $eq->isDue($at(12, 2) + $eq->nextDelay()));
$eq->setCache('failCount', 0);
t('libellé du rythme', strpos($eq->rhythmLabel(), '4') === 0 && strpos($eq->rhythmLabel(), '16:30') !== false, $eq->rhythmLabel());
config::save('sync_mode', $sMode, 'pronote'); config::save('sync_times', $sTimes, 'pronote');
config::save('sync_start', $sStart, 'pronote'); config::save('sync_end', $sEnd, 'pronote'); config::save('suspend_holidays', $sSusp, 'pronote');

section('8. Widgets');
$eq = eqLogic::byId($eq->getId());
$eq->applyData($payload['data']);
$eq = eqLogic::byId($eq->getId());
config::save('custom_widget', 0, 'pronote');
$std = $eq->toHtml('dashboard');
t('tuile standard rendue', is_string($std) && strlen($std) > 100, strlen($std) . ' octets');
config::save('custom_widget', 1, 'pronote');
$err = '';
try { $custom = $eq->toHtml('dashboard'); } catch (Throwable $e) { $custom = ''; $err = $e->getMessage(); }
t('widget maison sans erreur', $err === '', $err === '' ? strlen($custom) . ' octets' : $err);
t('widget affiche la moyenne (virgule française)', strpos($custom, '14,7') !== false);
t('widget affiche le prochain cours', strpos($custom, 'Anglais') !== false);
t('aucun placeholder oublié', !preg_match('/#[a-zA-Z_]+#/', $custom),
   preg_match('/#[a-zA-Z_]+#/', $custom, $m) ? 'reste ' . $m[0] : 'aucun');
$mob = $eq->toHtml('mobile');
t('rendu mobile sans erreur', is_string($mob) && strlen($mob) > 50, strlen($mob) . ' octets');
t('avatar dans les widgets (initiales sans photo)', strpos($custom, 'pw-av') !== false && strpos($mob, 'pw-av') !== false);
t('URL iCal formée avec la clé du plugin', strpos($eq->icalUrl(true), 'ical.php?apikey=') !== false && strpos($eq->icalUrl(true), 'id=' . $eq->getId()) !== false);

section('8 bis. Panneau');
$renderPanel = function () {
    ob_start();
    try {
        include __DIR__ . '/../desktop/php/panel.php';
        return array(true, ob_get_clean());
    } catch (Throwable $t) {
        ob_end_clean();
        return array(false, get_class($t) . ' : ' . $t->getMessage() . ' @' . basename($t->getFile()) . ':' . $t->getLine());
    }
};
list($ok, $panel) = $renderPanel();
t('panneau rendu sans erreur', $ok, $ok ? strlen($panel) . ' octets' : $panel);
t('panneau : élève, semaine, devoirs, matières', $ok && strpos($panel, 'AUTOTEST Pronote') !== false && strpos($panel, 'pnp-week') !== false
   && strpos($panel, 'pnp-subj') !== false && strpos($panel, 'pronote-hw') !== false);
t('panneau : cours placés dans la grille', $ok && preg_match_all('/class="ev/', $panel) > 5);
t('panneau : carte briefing et faits de demain', $ok && strpos($panel, 'pnp-brief') !== false && strpos($panel, 'réveil demain') !== false);
t('panneau : pas de case « fait » sans jeton (écriture impossible)', $ok && strpos($panel, 'class="chk pnp-hwchk"') === false);
$eq->setConfiguration('hide_grades', 1); $eq->save(true);
$discret = $eq->toHtml('dashboard');
t('mode discret : moyenne masquée sur le widget', strpos($discret, '•••') !== false && strpos($discret, '14,7') === false);
$eq->setConfiguration('hide_grades', 0); $eq->save(true);
t('panneau : rien d\'échappé à moitié (pas de #placeholder#)', $ok && !preg_match('/#[a-zA-Z_]+#/', $panel));

section('8 ter. Vacances selon l\'établissement');
$savedSusp = config::byKey('suspend_holidays', 'pronote', 0);
config::save('suspend_holidays', 1, 'pronote');
list($src, $ranges) = $eq->holidayRangesFor();
t('source = établissement quand Pronote publie le calendrier', $src === 'pronote' && count($ranges) === 1, $src . ' (' . count($ranges) . ')');
$nh = pronote::nextHoliday($eq);
t('prochaines vacances = celles de Pronote', is_array($nh) && strpos($nh[2], 'jeu d\'essai') !== false, is_array($nh) ? $nh[2] : 'aucune');
t('pause active au milieu des vacances Pronote', pronote::inHoliday($nh[0] + 3 * 86400, $eq));
t('pas de pause la veille', !pronote::inHoliday($nh[0] - 3600, $eq));
t('pas de pause le lendemain de la fin', !pronote::inHoliday($nh[1] + 3600, $eq));
$eq->setConfiguration('holiday_source', 'zone'); $eq->save(true);
t('source forcée « zone » : calendrier de Pronote ignoré', $eq->holidayRangesFor()[0] === 'zone');
$eq->setConfiguration('holiday_source', ''); $eq->save(true);
config::save('suspend_holidays', $savedSusp, 'pronote');

section('9. Plage horaire (dont passage de minuit)');
$sStart = config::byKey('sync_start', 'pronote', '06:00');
$sEnd   = config::byKey('sync_end', 'pronote', '20:00');
$at = function ($h, $m = 0) { return mktime($h, $m, 0, 1, 15, 2026); };

config::save('sync_start', '06:00', 'pronote');
config::save('sync_end', '20:00', 'pronote');
t('plage normale : 12h dedans', pronote::inSyncWindow($at(12)));
t('plage normale : 05h dehors', !pronote::inSyncWindow($at(5)));
t('plage normale : 22h dehors', !pronote::inSyncWindow($at(22)));
t('plage normale : bornes incluses', pronote::inSyncWindow($at(6)) && pronote::inSyncWindow($at(20)));

config::save('sync_start', '22:00', 'pronote');
config::save('sync_end', '06:00', 'pronote');
t('plage à cheval sur minuit : 23h dedans', pronote::inSyncWindow($at(23)));
t('plage à cheval sur minuit : 02h dedans', pronote::inSyncWindow($at(2)));
t('plage à cheval sur minuit : 12h DEHORS', !pronote::inSyncWindow($at(12)));

config::save('sync_start', '', 'pronote');
t('plage vide -> aucune restriction', pronote::inSyncWindow($at(3)));
config::save('sync_start', 'nimportequoi', 'pronote');
config::save('sync_end', '20:00', 'pronote');
t('plage illisible -> aucune restriction', pronote::inSyncWindow($at(3)));
config::save('sync_start', $sStart, 'pronote');
config::save('sync_end', $sEnd, 'pronote');

section('10. Régressions');
/* La page ne doit pas dépendre de $plugin : Jeedom ne le définit que si l'URL
   porte le paramètre « m ». Bug remonté le 09/09/2026. */
/* L'include partage la portée de l'appelant : on isole le rendu dans une
   fonction, sinon les variables de la page écrasent celles du test. */
$renderPage = function ($withPlugin) {
    if ($withPlugin) { $plugin = plugin::byId('pronote'); }
    ob_start();
    try {
        include __DIR__ . '/../desktop/php/pronote.php';
        $out = ob_get_clean();
        return array(true, strlen($out) . ' octets');
    } catch (Throwable $t) {
        ob_end_clean();
        return array(false, get_class($t) . ' : ' . $t->getMessage()
            . ' @' . basename($t->getFile()) . ':' . $t->getLine());
    }
};
list($ok, $detail) = $renderPage(false);
t('page équipement rendue sans $plugin défini', $ok, $detail);

/* eqType est indispensable au cœur : sans lui, Sauvegarder et Supprimer sont
   morts côté navigateur. Bug remonté le 09/09/2026. */
ob_start();
try { include __DIR__ . '/../desktop/php/pronote.php'; } catch (Throwable $t) {}
$pageHtml = ob_get_clean();
$jsVars = '';
if (function_exists('getVarToJS')) { $jsVars = getVarToJS(); }
t('la page déclare la variable JS eqType',
   strpos($pageHtml . $jsVars, 'eqType') !== false,
   strpos($pageHtml . $jsVars, 'eqType') !== false ? 'déclarée' : 'ABSENTE');
list($ok, $detail) = $renderPage(true);
t('page équipement rendue avec $plugin défini', $ok, $detail);

section('11. Nettoyage');
config::save('call_delay', $savedDelay, 'pronote');
config::save('custom_widget', $savedWidget, 'pronote');
config::save('debug_raw', $savedRaw, 'pronote');
$eq2->remove();
t('équipement de test 2 supprimé', !is_object(eqLogic::byLogicalId('__selftest_pronote_2', 'pronote')));
if (getenv('KEEP_TEST_EQ') !== '1') {
    $eq->remove();
    t('équipement de test supprimé', !is_object(eqLogic::byLogicalId('__selftest_pronote', 'pronote')));
} else {
    echo "  (équipement TEST Pronote conservé : KEEP_TEST_EQ=1)\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo ($FAIL === 0 ? "TOUT PASSE" : $FAIL . " ECHEC(S)") . " sur " . $COUNT . " vérifications\n";
echo str_repeat('=', 60) . "\n";
exit($FAIL === 0 ? 0 : 1);
