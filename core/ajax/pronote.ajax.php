<?php
try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();

    if (init('action') == 'selftest') {
        $py = pronote::getPythonPath();
        if (!file_exists($py)) {
            $py = 'python3'; // le jeu d'essai ne dépend pas de pronotepy
        }
        $cmd = escapeshellarg($py) . ' ' . escapeshellarg(pronote::getScriptPath()) . ' --selftest 2>&1';
        $out = shell_exec($cmd);
        $payload = json_decode((string)$out, true);
        if (!is_array($payload)) {
            throw new Exception(__('Sortie illisible : ', __FILE__) . substr((string)$out, 0, 300));
        }
        $eqLogic = pronote::byId(init('id'));
        if (is_object($eqLogic)) {
            $eqLogic->applyData($payload['data']);
            $eqLogic->setCache('lastSync', time());
            $eqLogic->setCache('lastError', '');
        }
        ajax::success($payload);
    }

    if (init('action') == 'sync') {
        $eqLogic = pronote::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        $payload = $eqLogic->synchronize();
        if (!isset($payload['ok']) || $payload['ok'] !== true) {
            throw new Exception($payload['error']);
        }
        unset($payload['credentials']);
        ajax::success($payload);
    }

    if (init('action') == 'enroll') {
        $eqLogic = pronote::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        $qr = json_decode(init('qr'), true);
        if (!is_array($qr)) {
            throw new Exception(__('Contenu du QR Code illisible (JSON attendu)', __FILE__));
        }
        if (!preg_match('/^\d{4}$/', trim((string)init('pin')))) {
            throw new Exception(__('Le code à 4 chiffres est obligatoire : celui choisi dans Pronote pour ce QR Code. Rien n\'a été envoyé à Pronote.', __FILE__));
        }
        $payload = $eqLogic->runFetch(array(
            'mode' => 'qr',
            'qr_json' => $qr,
            'pin' => init('pin'),
            'uuid' => 'jeedom-' . $eqLogic->getId(),
            'credentials' => null,
        ));
        if (!isset($payload['ok']) || $payload['ok'] !== true) {
            throw new Exception($payload['error']);
        }
        if (isset($payload['credentials']) && is_array($payload['credentials'])) {
            $eqLogic->setSecret('credentials', json_encode($payload['credentials']));
            $eqLogic->save(true);
        }
        $eqLogic->setCache('failCount', 0);
        $eqLogic->setCache('lastError', '');
        $eqLogic->applyData($payload['data']);
        // Le jeton ne doit pas sortir du serveur, même vers l'IHM.
        unset($payload['credentials']);
        ajax::success($payload);
    }

    if (init('action') == 'decode_qr') {
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception(__('Aucune image reçue', __FILE__));
        }
        if ($_FILES['image']['size'] > 8 * 1024 * 1024) {
            throw new Exception(__('Image trop volumineuse (8 Mo maximum)', __FILE__));
        }
        $tmp = jeedom::getTmpFolder('pronote') . '/qr_' . getmypid() . '_' . time() . '.img';
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $tmp)) {
            throw new Exception(__('Impossible de lire le fichier envoyé', __FILE__));
        }
        $payload = pronote::decodeQrImage($tmp);
        // L'image porte un jeton d'accès : on ne la garde pas sur le disque.
        @unlink($tmp);
        if (!isset($payload['ok']) || $payload['ok'] !== true) {
            throw new Exception($payload['error']);
        }
        ajax::success($payload['qr']);
    }

    if (init('action') == 'health') {
        ajax::success(array(
            'python' => file_exists(pronote::getPythonPath()),
            'pronotepy' => pronote::pronotepyVersion(),
            'ready' => pronote::pythonReady(),
        ));
    }

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
