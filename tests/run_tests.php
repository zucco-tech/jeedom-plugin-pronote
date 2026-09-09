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
t('commandes créées', count($ids) === 21, count($ids) . ' : ' . implode(', ', $ids));
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
t('homework_count écrit', $get('homework_count') == 3, (string)$get('homework_count'));
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
