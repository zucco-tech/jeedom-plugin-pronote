<?php
try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    /* Photo de profil : lisible par tout utilisateur connecté (elle s'affiche
       sur le dashboard), jamais par une URL directe (dossier data fermé). */
    if (init('action') == 'photo') {
        if (!isConnect()) {
            http_response_code(401);
            exit;
        }
        $eqLogic = pronote::byId((int)init('id'));
        if (!is_object($eqLogic) || !$eqLogic->hasPhoto()) {
            http_response_code(404);
            exit;
        }
        $file = $eqLogic->photoFile();
        $head = (string)@file_get_contents($file, false, null, 0, 4);
        $mime = (substr($head, 0, 4) === "\x89PNG") ? 'image/png' : 'image/jpeg';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="photo.jpg"');
        readfile($file);
        exit;
    }

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();

    $student = function () {
        $eqLogic = pronote::byId((int)init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== 'pronote') {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        return $eqLogic;
    };

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
        $eqLogic = $student();
        $eqLogic->applyData($payload['data']);
        $eqLogic->setCache('lastSync', time());
        $eqLogic->setCache('lastError', '');
        ajax::success($payload);
    }

    if (init('action') == 'sync') {
        $eqLogic = $student();
        $payload = $eqLogic->synchronize();
        if (!isset($payload['ok']) || $payload['ok'] !== true) {
            throw new Exception($payload['error']);
        }
        unset($payload['credentials']);
        ajax::success($payload);
    }

    /* Écriture dans Pronote : cocher / décocher un devoir. Une connexion,
       qui rafraîchit aussi toutes les données de l'élève. */
    if (init('action') == 'homework_done') {
        $eqLogic = $student();
        $hwId = trim((string)init('homework'));
        if ($hwId === '' || strlen($hwId) > 64 || !preg_match('/^[A-Za-z0-9#_\-]+$/', $hwId)) {
            throw new Exception(__('Identifiant de devoir invalide', __FILE__));
        }
        $payload = $eqLogic->synchronize(array('action' => array(
            'type' => 'homework_done', 'id' => $hwId, 'done' => (init('done', 1) == 1))));
        if (!isset($payload['ok']) || $payload['ok'] !== true) {
            $err = (string)($payload['error'] ?? '');
            /* Pronote refuse : l'établissement réserve la case « fait » au compte
               de l'élève. On s'en souvient pour ne plus proposer les cases. */
            if (preg_match('/refus|acc[eè]s|autoris|interdit|error from pronote/iu', $err)) {
                $eqLogic->setCache('hwWriteDenied', 1);
                throw new Exception(__('Pronote refuse : cet établissement réserve la case « fait » au compte de l\'élève. Les cases sont retirées du panneau.', __FILE__));
            }
            throw new Exception($err);
        }
        $eqLogic->setCache('hwWriteDenied', 0);
        ajax::success(array('done' => (init('done', 1) == 1)));
    }

    if (init('action') == 'enroll') {
        $eqLogic = $student();
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
        // Vérification par le contenu, pas par l'extension ni le type annoncé.
        $info = @getimagesize($_FILES['image']['tmp_name']);
        if ($info === false || !in_array($info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_BMP))) {
            throw new Exception(__('Le fichier envoyé n\'est pas une image (JPEG, PNG, GIF, WebP ou BMP attendu)', __FILE__));
        }
        $tmp = jeedom::getTmpFolder('pronote') . '/qr_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.img';
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $tmp)) {
            throw new Exception(__('Impossible de lire le fichier envoyé', __FILE__));
        }
        @chmod($tmp, 0600);
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

    throw new Exception(__('Aucune méthode correspondante', __FILE__));

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
