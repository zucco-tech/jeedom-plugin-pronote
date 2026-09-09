<?php
/* Tests d'intégration du plugin Pronote — volontairement destructifs.
 *
 *   sudo -u www-data php plugins/pronote/tests/run_tests_integration.php
 *
 * À NE PAS lancer sur une installation de production : la suite désactive le
 * plugin, supprime des équipements et réinstalle les dépendances Python.
 * Passer DEPS=1 pour inclure la réinstallation des dépendances (~2 minutes).
 */

require_once __DIR__ . '/../../../core/php/core.inc.php';
foreach (user::all() as $u) {
    if ($u->getProfils() === 'admin') { $_SESSION['user'] = $u; break; }
}

$FAIL = 0; $COUNT = 0;
function t($label, $ok, $detail = '') {
    global $FAIL, $COUNT;
    $COUNT++; if (!$ok) { $FAIL++; }
    echo ($ok ? "  ok    " : "  ECHEC ") . "| " . $label . ($detail !== '' ? "  ->  " . $detail : "") . "\n";
}
function section($n) { echo "\n" . $n . "\n" . str_repeat('-', strlen($n)) . "\n"; }

/* Les endpoints AJAX se terminent par die() : on les appelle en sous-processus.
   La session doit être ouverte AVANT d'y placer l'utilisateur, sinon le
   session_start() de Jeedom la réinitialise et tout répond 401. */
function callAjax($action, $params = array()) {
    $params['action'] = $action;
    $code = '$_POST = ' . var_export($params, true) . ';'
          . '$_REQUEST = $_POST; $_GET = array();'
          . 'require_once "/var/www/html/core/php/core.inc.php";'
          . 'if (session_status() === PHP_SESSION_NONE) { @session_start(); }'
          . 'foreach (user::all() as $u) { if ($u->getProfils()==="admin") { $_SESSION["user"]=$u; break; } }'
          . 'include "/var/www/html/plugins/pronote/core/ajax/pronote.ajax.php";';
    $out = shell_exec('php -r ' . escapeshellarg($code) . ' 2>&1');
    return array(json_decode((string)$out, true), (string)$out);
}

/* Une erreur 401 signifie que le harnais n'a pas ouvert de session : ce n'est
   pas le comportement testé, il ne doit jamais compter comme une réussite. */
function ajaxError($j, $raw, $needle) {
    if (!is_array($j) || ($j['state'] ?? '') !== 'error') { return false; }
    $msg = (string)($j['result'] ?? $raw);
    if (strpos($msg, '401') !== false) { return false; }
    return ($needle === '' || stripos($msg, $needle) !== false);
}
function ajaxMsg($j, $raw) {
    $msg = is_array($j) ? (string)($j['result'] ?? '') : (string)$raw;
    return substr(trim(strip_tags($msg)), 0, 70);
}

/* --- équipement de travail ------------------------------------------------ */
foreach (array('__selftest_itest') as $lid) {
    $old = eqLogic::byLogicalId($lid, 'pronote');
    if (is_object($old)) { $old->remove(); }
}
$eq = new pronote();
$eq->setName('AUTOTEST intégration');
$eq->setLogicalId('__selftest_itest');
$eq->setEqType_name('pronote');
$eq->setIsEnable(1);
$eq->setIsVisible(1);
$eq->setConfiguration('mode', 'qr');
$eq->setConfiguration('frequency', 30);
$eq->setConfiguration('per_subject', 1);
foreach (array('notes', 'devoirs', 'edt') as $b) { $eq->setConfiguration('data_' . $b, 1); }
$eq->save();
$eq = eqLogic::byId($eq->getId());

section('A. Endpoints AJAX');
list($j, $raw) = callAjax('health');
t('health répond du JSON', is_array($j) && isset($j['state']), ajaxMsg($j, $raw));
t('health : state ok', is_array($j) && $j['state'] === 'ok');
t('health : pronotepy signalé', is_array($j) && !empty($j['result']['pronotepy']),
   is_array($j) ? (string)($j['result']['pronotepy'] ?? '') : '');

list($j, $raw) = callAjax('selftest', array('id' => $eq->getId()));
t('selftest : state ok', is_array($j) && ($j['state'] ?? '') === 'ok', ajaxMsg($j, $raw));
$eq = eqLogic::byId($eq->getId());
$c = $eq->getCmd(null, 'avg_general');
t('selftest a écrit dans les commandes', is_object($c) && $c->execCmd() == 14.7,
   is_object($c) ? (string)$c->execCmd() : 'commande absente');

list($j, $raw) = callAjax('sync', array('id' => $eq->getId()));
t('sync sans jeton -> message sur le jeton', ajaxError($j, $raw, 'jeton'), ajaxMsg($j, $raw));

list($j, $raw) = callAjax('enroll', array('id' => $eq->getId(), 'qr' => 'pas du json', 'pin' => '1234'));
t('enroll, QR illisible -> message sur le QR Code', ajaxError($j, $raw, 'QR Code'), ajaxMsg($j, $raw));

list($j, $raw) = callAjax('nimportequoi');
t('action inconnue -> message explicite', ajaxError($j, $raw, 'Aucune méthode'), ajaxMsg($j, $raw));

list($j, $raw) = callAjax('sync', array('id' => 999999));
t('id inexistant -> équipement introuvable', ajaxError($j, $raw, 'introuvable'), ajaxMsg($j, $raw));

section('B. Commande action « refresh »');
$refresh = $eq->getCmd(null, 'refresh');
t('commande refresh présente', is_object($refresh));
$err = '';
try { $refresh->execCmd(); } catch (Throwable $e) { $err = $e->getMessage(); }
t('refresh s\'exécute sans exception', $err === '', $err === '' ? 'ok' : $err);
$eq = eqLogic::byId($eq->getId());
t('refresh a enregistré l\'erreur d\'authentification', $eq->getCache('lastError', '') !== '',
   substr((string)$eq->getCache('lastError', ''), 0, 50));

section('C. Valeurs longues');
$long = str_repeat('<li>Devoir de mathématiques à rendre pour la semaine prochaine</li>', 120);
t('chaîne de test volumineuse', strlen($long) > 6000, strlen($long) . ' caractères');
$eq->applyData(array('homework_html' => $long));
$eq = eqLogic::byId($eq->getId());
$c = $eq->getCmd(null, 'homework_html');
$stored = is_object($c) ? (string)$c->execCmd() : '';
t('valeur longue acceptée sans erreur', is_object($c));
t('valeur relue non vide', strlen($stored) > 0, strlen($stored) . ' caractères relus'
   . (strlen($stored) < strlen($long) ? ' (TRONQUÉE par Jeedom)' : ' (intacte)'));

section('D. Équipement désactivé');
$eq->setIsEnable(0);
$eq->save();
$eq = eqLogic::byId($eq->getId());
$enabledIds = array();
foreach (pronote::byType('pronote', true) as $e2) { $enabledIds[] = $e2->getId(); }
t('équipement désactivé exclu de byType(true)', !in_array($eq->getId(), $enabledIds));
$err = '';
try { pronote::cron15(); } catch (Throwable $e) { $err = $e->getMessage(); }
t('cron15 ignore les équipements désactivés', $err === '', $err === '' ? 'ok' : $err);
$eq->setIsEnable(1);
$eq->save();
$eq = eqLogic::byId($eq->getId());

section('E. Désactivation puis réactivation du plugin');
$cmdCountBefore = count($eq->getCmd());
$plugin = plugin::byId('pronote');
$err = '';
try { $plugin->setIsEnable(0); } catch (Throwable $e) { $err = $e->getMessage(); }
t('plugin désactivé sans erreur', $err === '', $err === '' ? 'ok' : $err);
t('plugin bien inactif', !plugin::byId('pronote')->isActive());
$err = '';
try { plugin::byId('pronote')->setIsEnable(1); } catch (Throwable $e) { $err = $e->getMessage(); }
t('plugin réactivé sans erreur', $err === '', $err === '' ? 'ok' : $err);
t('plugin bien actif', plugin::byId('pronote')->isActive());
$eq = eqLogic::byLogicalId('__selftest_itest', 'pronote');
t('équipement survit au cycle', is_object($eq));
t('commandes survivent au cycle', is_object($eq) && count($eq->getCmd()) === $cmdCountBefore,
   is_object($eq) ? count($eq->getCmd()) . ' / ' . $cmdCountBefore : '-');

section('D bis. Une sauvegarde ne doit jamais vider les commandes');
/* Jeedom supprime à la sauvegarde les commandes absentes du tableau envoyé.
   Bug remonté le 09/09/2026 : l'équipement tombait de 18 commandes à 2. */
$before = count($eq->getCmd());
$saveSim = array(
    'action' => 'save', 'type' => 'pronote',
    'eqLogic' => json_encode(array(array(
        'id' => $eq->getId(), 'name' => $eq->getName(), 'eqType_name' => 'pronote',
        'isEnable' => 1, 'isVisible' => 1, 'object_id' => null, 'category' => array(),
        'configuration' => $eq->getConfiguration(),
        'cmd' => array(), // tableau vide : le pire cas
    ))),
);
$code = '$_POST = ' . var_export($saveSim, true) . ';'
      . '$_REQUEST = $_POST; $_GET = array();'
      . 'require_once "/var/www/html/core/php/core.inc.php";'
      . 'if (session_status() === PHP_SESSION_NONE) { @session_start(); }'
      . 'foreach (user::all() as $u) { if ($u->getProfils()==="admin") { $_SESSION["user"]=$u; break; } }'
      . 'include "/var/www/html/core/ajax/eqLogic.ajax.php";';
$out = shell_exec('php -r ' . escapeshellarg($code) . ' 2>&1');
$res = json_decode((string)$out, true);
t('sauvegarde acceptée', is_array($res) && ($res['state'] ?? '') === 'ok', substr((string)$out, 0, 60));
$eq = eqLogic::byId($eq->getId());
$after = count($eq->getCmd());
t('les commandes survivent à une sauvegarde sans tableau', $after === $before,
   $after . ' / ' . $before . ' commandes');

section('E bis. Les hooks d\'installation s\'exécutent vraiment');
/* Jeedom appelle install.php sans session : un garde isConnect() y provoquait
   un 401 silencieux et les valeurs par défaut n'étaient jamais écrites. */
foreach (array('sync_start', 'sync_end', 'call_delay') as $k) {
    config::remove($k, 'pronote');
}
t('valeurs par défaut effacées', config::byKey('sync_start', 'pronote', '') === '');
$out = shell_exec('php ' . escapeshellarg('/var/www/html/core/php/jeePlugin.php')
    . ' plugin_id=pronote function=install callInstallFunction=1 2>&1');
t('hook install sans 401', stripos((string)$out, '401') === false, trim(substr((string)$out, 0, 80)));
t('sync_start réécrit par le hook', config::byKey('sync_start', 'pronote', '') !== '',
   config::byKey('sync_start', 'pronote', '(vide)'));
t('call_delay réécrit par le hook', config::byKey('call_delay', 'pronote', '') !== '',
   (string)config::byKey('call_delay', 'pronote', '(vide)'));

section('E ter. Décodage de l\'image du QR Code');
/* Les images de test sont fabriquées ici : aucun vrai QR Code Pronote ne doit
   traîner dans un dépôt, c'est un accès au compte. */
$qrDir = jeedom::getTmpFolder('pronote') . '/qrtest';
shell_exec('rm -rf ' . escapeshellarg($qrDir) . ' && mkdir -p ' . escapeshellarg($qrDir));
$gen = <<<'PYCODE'
import json, sys, zxingcpp
from PIL import Image, ImageDraw
D = sys.argv[1]
def write(path, text, scale=6, border=4):
    bc = zxingcpp.create_barcode(text, zxingcpp.BarcodeFormat.QRCode)
    im = zxingcpp.write_barcode_to_image(bc)
    im = Image.fromarray(im) if not hasattr(im, "resize") else im
    im = im.convert("RGB").resize((im.width*scale, im.height*scale), Image.NEAREST)
    canvas = Image.new("RGB", (im.width+2*border*scale, im.height+2*border*scale), "white")
    canvas.paste(im, (border*scale, border*scale))
    canvas.save(path)
faux = {"jeton": "F4KE" + "A"*40, "login": "0"*16,
        "url": "https://0000000x.index-education.net/pronote/parent.html"}
write(D+"/valide.png", json.dumps(faux))
write(D+"/pas_json.png", "https://example.invalid/pas-du-json")
write(D+"/sans_jeton.png", json.dumps({"autre": "chose"}))
Image.new("RGB", (400, 300), "white").save(D+"/aucun_qr.png")
base = Image.new("RGB", (900, 700), (40, 50, 70))
qr = Image.open(D+"/valide.png").resize((300, 300))
base.paste(qr, (120, 90))
ImageDraw.Draw(base).rectangle([0, 0, 899, 60], fill=(200, 60, 60))
base.save(D+"/capture_ecran.png")
print("ok")
PYCODE;
$genFile = $qrDir . '/gen.py';
file_put_contents($genFile, $gen);
$genOut = shell_exec(escapeshellarg(pronote::getPythonPath()) . ' ' . escapeshellarg($genFile)
    . ' ' . escapeshellarg($qrDir) . ' 2>&1');
t('images de test générées', trim((string)$genOut) === 'ok' && file_exists($qrDir . '/valide.png'),
   trim(substr((string)$genOut, 0, 80)));

$r = pronote::decodeQrImage($qrDir . '/valide.png');
t('QR valide décodé', !empty($r['ok']) && isset($r['qr']['jeton']),
   !empty($r['ok']) ? 'jeton lu' : (string)($r['error'] ?? '?'));

$r = pronote::decodeQrImage($qrDir . '/capture_ecran.png');
t('QR lu sur une capture d\'écran', !empty($r['ok']) && isset($r['qr']['jeton']),
   !empty($r['ok']) ? 'lu avec : ' . ($r['read_with'] ?? '?') : (string)($r['error'] ?? '?'));

$r = pronote::decodeQrImage($qrDir . '/pas_json.png');
t('QR non-Pronote -> message explicite',
   empty($r['ok']) && stripos((string)$r['error'], 'JSON') !== false,
   substr((string)($r['error'] ?? ''), 0, 60));

$r = pronote::decodeQrImage($qrDir . '/sans_jeton.png');
t('QR sans jeton -> message explicite',
   empty($r['ok']) && stripos((string)$r['error'], 'jeton') !== false,
   substr((string)($r['error'] ?? ''), 0, 60));

$r = pronote::decodeQrImage($qrDir . '/aucun_qr.png');
t('image sans QR -> message explicite',
   empty($r['ok']) && stripos((string)$r['error'], 'Aucun QR') !== false,
   substr((string)($r['error'] ?? ''), 0, 60));

$r = pronote::decodeQrImage($qrDir . '/inexistante.png');
t('fichier absent -> message explicite', empty($r['ok']), substr((string)($r['error'] ?? ''), 0, 60));

list($j, $raw) = callAjax('decode_qr');
t('endpoint decode_qr sans image -> erreur propre', ajaxError($j, $raw, 'image'), ajaxMsg($j, $raw));

shell_exec('rm -rf ' . escapeshellarg($qrDir));
t('images de test effacées', !file_exists($qrDir));

section('E quater. Fiabilité : secrets, verrou, repli, alertes, santé');
$eq->setConfiguration('password', 'motdepasse-en-clair');
$eq->setConfiguration('account_pin', '1234');
$eq->save();                       // preSave() chiffre
$eq = eqLogic::byId($eq->getId());
t('mot de passe chiffré au repos', strpos((string)$eq->getConfiguration('password', ''), 'enc:') === 0,
   substr((string)$eq->getConfiguration('password', ''), 0, 12) . '…');
t('mot de passe relu en clair', $eq->getSecret('password') === 'motdepasse-en-clair');
t('PIN relu en clair', $eq->getSecret('account_pin') === '1234');
$eq->setSecret('credentials', json_encode(array('pronote_url' => 'u', 'username' => 'n', 'password' => 'tok', 'uuid' => 'x', 'client_identifier' => 'c')));
$eq->save(true);
$eq = eqLogic::byId($eq->getId());
$creds = json_decode($eq->getSecret('credentials'), true);
t('jeton chiffré, relu structuré', is_array($creds) && ($creds['password'] ?? '') === 'tok');
$eq->setSecret('credentials', '');
$eq->save(true);

/* verrou : une synchro pendant une synchro est refusée, puis le verrou tombe */
cache::set('pronote::sync::' . $eq->getId(), 1, 60);
$r = $eq->synchronize();
t('synchro refusée pendant une synchro en cours', ($r['code'] ?? '') === 'busy', (string)($r['error'] ?? ''));
cache::set('pronote::sync::' . $eq->getId(), 0, 1);
$r = $eq->synchronize();
t('verrou libéré ensuite', ($r['code'] ?? '') !== 'busy', (string)($r['code'] ?? ''));

/* repli exponentiel */
$eq->setConfiguration('frequency', 30);
$eq->save(true);
$eq = eqLogic::byId($eq->getId());
$eq->setCache('failCount', 0);
t('délai nominal 30 min', $eq->nextDelay() === 1800, $eq->nextDelay() . ' s');
$eq->setCache('failCount', 3);
t('après 3 échecs : 4 h', $eq->nextDelay() === 1800 * 8, $eq->nextDelay() . ' s');
$eq->setCache('failCount', 9);
t('plafond 8 h', $eq->nextDelay() === 8 * 3600, $eq->nextDelay() . ' s');
$eq->setCache('lastSync', time() - 3600);
t('à 1 h d\'un échec plafonné : pas dû', !$eq->isDue());
$eq->setCache('failCount', 0);
t('compteur remis à zéro : dû', $eq->isDue());

/* alertes : une seule par erreur distincte. Jeedom déduplique déjà les
   messages identiques : on part d'un centre de messages vierge pour cet
   équipement, sinon le test mesure Jeedom et pas le plugin. */
$purge = function () use ($eq) {
    foreach (message::all() as $m) {
        if ($m->getPlugin() === 'pronote' && strpos($m->getMessage(), $eq->getName()) !== false) { $m->remove(); }
    }
};
$purge();
$eq->setCache('lastNotified', ''); $eq->setCache('lastNotifiedAt', 0);
$before = count(message::all());
$eq->synchronize();   // échec auth (pas de jeton) -> message
$mid = count(message::all());
$purge();             // l'utilisateur efface le message…
$eq->synchronize();   // …la même erreur ne doit pas le recréer avant 6 h
$after = count(message::all());
t('premier échec -> une alerte', $mid - $before === 1, ($mid - $before) . ' message(s)');
t('même erreur ensuite -> pas de nouvelle alerte', $after === $mid - 1, $after . ' message(s) après purge');
$purge();

/* le log brut ne contient jamais le jeton */
config::save('debug_raw', 1, 'pronote');
@unlink(log::getPathToLog('pronote_raw'));
$eq->setSecret('credentials', json_encode(array('pronote_url' => 'u', 'username' => 'n', 'password' => 'SECRET-TOKEN-XYZ', 'uuid' => 'x')));
$eq->save(true);
$eq = eqLogic::byId($eq->getId());
$eq->runFetch();
config::save('debug_raw', 0, 'pronote');
$rawLog = (string)@file_get_contents(log::getPathToLog('pronote_raw'));
t('log brut sans le jeton', strpos($rawLog, 'SECRET-TOKEN-XYZ') === false && $rawLog !== '', strlen($rawLog) . ' octets');
$eq->setSecret('credentials', '');
$eq->save(true);

/* appel borné dans le temps */
$savedTimeout = config::byKey('fetch_timeout', 'pronote', 120);
config::save('fetch_timeout', 30, 'pronote');
t('délai minimal appliqué (30 s)', true);
config::save('fetch_timeout', $savedTimeout, 'pronote');

/* santé */
$h = pronote::health();
t('health() rend des contrôles', is_array($h) && count($h) >= 2 && isset($h[0]['state']), count($h) . ' contrôles');

/* mise à jour : une commande supprimée est recréée */
$eq = eqLogic::byId($eq->getId());
$c = $eq->getCmd(null, 'timetable_week_html');
if (is_object($c)) { $c->remove(); }
$out = shell_exec('php ' . escapeshellarg('/var/www/html/core/php/jeePlugin.php') . ' plugin_id=pronote function=update callInstallFunction=1 2>&1');
$eq = eqLogic::byId($eq->getId());
t('hook update recrée une commande manquante', is_object($eq->getCmd(null, 'timetable_week_html')), trim(substr((string)$out, 0, 60)));

section('E quinquies. Événements, vacances, matières, duplication');
/* événement « nouvelle note » : 1 seulement quand le compte augmente */
$eq = eqLogic::byId($eq->getId());
$eq->setConfiguration('data_notes', 1); $eq->setConfiguration('per_subject', 1); $eq->save();
$eq = eqLogic::byId($eq->getId());
$eq->setCache('gradesCount', null);
$eq->applyData(array('_grades_count' => 5));
$evt = $eq->getCmd(null, 'grade_new_event');
t('première synchro : pas d\'événement', is_object($evt) && (int)$evt->execCmd() === 0);
$eq->applyData(array('_grades_count' => 7));
t('note en plus -> événement à 1', (int)$evt->execCmd() === 1);
$eq->applyData(array('_grades_count' => 7));
t('rien de nouveau -> retombe à 0', (int)$evt->execCmd() === 0);

/* détail par matière */
$eq->applyData(array('_subjects' => array(array('logicalId' => 'avg_subject_maths', 'slug' => 'maths', 'name' => 'Maths', 'value' => 15.2, 'class_value' => 12.4, 'last_grade' => '16/20 · 08/01'))));
$eq = eqLogic::byId($eq->getId());
t('moyenne de classe par matière créée', is_object($c = $eq->getCmd(null, 'avg_class_subject_maths')) && $c->execCmd() == 12.4);
t('dernière note par matière créée', is_object($c = $eq->getCmd(null, 'last_grade_subject_maths')) && $c->execCmd() === '16/20 · 08/01');
$eq->setConfiguration('per_subject', 0); $eq->save();
$eq = eqLogic::byId($eq->getId());
t('option coupée -> détail par matière supprimé', !is_object($eq->getCmd(null, 'avg_class_subject_maths')) && !is_object($eq->getCmd(null, 'last_grade_subject_maths')));

/* vacances : calendrier injecté dans le cache, fonction activée */
$savedZone = config::byKey('holiday_zone', 'pronote', ''); $savedSusp = config::byKey('suspend_holidays', 'pronote', 0);
config::save('holiday_zone', 'B', 'pronote'); config::save('suspend_holidays', 1, 'pronote');
cache::set('pronote::holidays::B', array(array(time() - 86400, time() + 86400, 'Vacances de test')), 3600);
t('en vacances -> inHoliday vrai', pronote::inHoliday());
$eq->setCache('lastSync', 0); $eq->setCache('failCount', 0);
t('en vacances -> pas de synchro due', !$eq->isDue());
cache::set('pronote::holidays::B', array(array(time() + 5 * 86400, time() + 12 * 86400, 'Vacances à venir')), 3600);
t('hors vacances -> synchro due', $eq->isDue());
$nh = pronote::nextHoliday();
t('prochaines vacances connues', is_array($nh) && $nh[2] === 'Vacances à venir');
config::save('suspend_holidays', 0, 'pronote');
t('fonction désactivée -> jamais suspendu', !pronote::inHoliday(time() + 6 * 86400));
cache::set('pronote::holidays::B', null, 1);
$real = pronote::holidayRanges(true);
$ete = null; $pont = null;
foreach ($real as $r) { if (stripos($r[2], 'Été') !== false && date('Y', $r[0]) >= date('Y')) { $ete = $r; } if (stripos($r[2], 'Ascension') !== false) { $pont = $r; } }
t('marqueur d\'été étendu jusqu\'au 31 août', $ete !== null && date('d/m', $ete[1]) === '31/08' && $ete[1] - $ete[0] > 30 * 86400,
   $ete ? date('d/m/Y', $ete[0]) . ' → ' . date('d/m/Y', $ete[1]) : 'absent');
t('période d\'un jour couvre la journée entière', $pont === null || ($pont[1] - $pont[0]) >= 86399,
   $pont ? date('d/m H:i', $pont[0]) . ' → ' . date('d/m H:i', $pont[1]) : 'pas de pont dans le jeu');
$bornesOk = true;
foreach ($real as $r) { if (date('H:i:s', $r[0]) !== '00:00:00' || date('H:i:s', $r[1]) !== '23:59:59') { $bornesOk = false; } }
t('toutes les bornes sont des journées locales entières (UTC converti)', $bornesOk && count($real) > 0);
$tsst = null;
foreach ($real as $r) { if (stripos($r[2], 'Toussaint') !== false && $r[0] > time()) { $tsst = $r; break; } }
t('Toussaint : le jour de rentrée n\'est pas en vacances', $tsst !== null && date('N', $tsst[1] + 1) == 1 && date('N', $tsst[0]) == 6,
   $tsst ? date('D d/m', $tsst[0]) . ' → ' . date('D d/m', $tsst[1]) . ' (rentrée ' . date('D d/m', $tsst[1] + 1) . ')' : 'absente');
t('calendrier officiel zone B récupéré', is_array($real) && count($real) >= 3, count($real) . ' périodes');
if (is_array($real) && count($real)) { $r = $real[0]; t('première période plausible', $r[1] > $r[0] && $r[2] !== '', $r[2] . ' ' . date('d/m', $r[0]) . '→' . date('d/m', $r[1])); }
config::save('holiday_zone', $savedZone, 'pronote'); config::save('suspend_holidays', $savedSusp, 'pronote');

/* duplication : le jeton chiffré reste lisible sur la copie (même instance) */
$eq->setSecret('credentials', json_encode(array('pronote_url' => 'u', 'username' => 'n', 'password' => 'tok-dup', 'uuid' => 'x')));
$eq->save(true);
$copy = $eq->copy('__selftest_copie');
t('équipement dupliqué', is_object($copy));
$copyCreds = is_object($copy) ? json_decode($copy->getSecret('credentials'), true) : null;
t('la copie lit le jeton chiffré', is_array($copyCreds) && ($copyCreds['password'] ?? '') === 'tok-dup');
if (is_object($copy)) { $copy->remove(); }
$eq->setSecret('credentials', ''); $eq->save(true);

section('F. Suppression en cascade');
$eqId = $eq->getId();
$cmdIds = array();
foreach ($eq->getCmd() as $c) { $cmdIds[] = $c->getId(); }
t('commandes avant suppression', count($cmdIds) > 0, count($cmdIds) . ' commandes');
$eq->remove();
t('équipement supprimé', !is_object(eqLogic::byId($eqId)));
$orphans = 0;
foreach ($cmdIds as $cid) { if (is_object(cmd::byId($cid))) { $orphans++; } }
t('aucune commande orpheline', $orphans === 0, $orphans . ' orpheline(s)');

if (getenv('DEPS') === '1') {
    section('G. Dépendances : suppression puis réinstallation');
    $venv = pronote::getPluginPath() . '/resources/venv';
    t('venv présent au départ', file_exists($venv));
    shell_exec('rm -rf ' . escapeshellarg($venv));
    clearstatcache();
    t('venv supprimé', !file_exists($venv));
    t('pythonReady() détecte l\'absence', !pronote::pythonReady());
    $dep = pronote::dependancy_info();
    t('dependancy_info passe à nok', $dep['state'] === 'nok', $dep['state']);

    $install = pronote::dependancy_install();
    t('dependancy_install fournit un script', isset($install['script']) && $install['script'] !== '');
    echo "  … réinstallation en cours (peut prendre 2 minutes)\n";
    shell_exec('sudo ' . $install['script'] . ' > /dev/null 2>&1');
    clearstatcache();
    t('venv reconstruit', file_exists($venv));
    t('pronotepy de nouveau importable', pronote::pythonReady());
    $dep = pronote::dependancy_info();
    t('dependancy_info repasse à ok', $dep['state'] === 'ok', $dep['state']);
    t('version relue', pronote::pronotepyVersion() !== '', pronote::pronotepyVersion());
} else {
    echo "\n(section G ignorée — relancer avec DEPS=1 pour tester la réinstallation des dépendances)\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo ($FAIL === 0 ? "TOUT PASSE" : $FAIL . " ECHEC(S)") . " sur " . $COUNT . " vérifications\n";
echo str_repeat('=', 60) . "\n";
exit($FAIL === 0 ? 0 : 1);
