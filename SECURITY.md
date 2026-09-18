# Sécurité du plugin Pronote

Ce plugin manipule l'accès au compte Pronote d'un enfant. Voici ce qui est
protégé, comment, et ce qui reste à la charge de l'utilisateur. Revue complète
du code faite le 18/09/2026 (version 1.1.0-beta.1).

## Ce que le plugin détient

| Donnée | Où | Protection |
|---|---|---|
| Jeton Pronote, mot de passe, PIN 2FA | configuration de l'équipement (base Jeedom) | chiffrés avec `utils::encrypt` (clé propre à l'installation), préfixe `enc:` ; **masqués** (`••••••••`) dans tout ce que Jeedom envoie au navigateur ou à l'API JSON-RPC (`toArray()`), la sauvegarde reconnaît le masque |
| Contenu du QR Code | jamais stocké | l'image envoyée est vérifiée (contenu image), décodée, puis supprimée ; le JSON n'est utilisé qu'une fois pour l'enrôlement |
| Requête au script Python (porte le jeton) | fichier temporaire Jeedom | créé en 0600 **avant** écriture, nom aléatoire, supprimé quoi qu'il arrive (`finally`) |
| Cours, devoirs, notes, vacances (14 jours) | `data/student_<id>.json` | 0600, dossier fermé par `.htaccess`, supprimé avec l'élève |
| Photo de profil (option, off par défaut) | `data/photo_<id>.jpg` | 0600 (`umask 077` côté Python), servie seulement par l'AJAX à un utilisateur **connecté** (`isConnect()`), jamais par URL directe ; format vérifié (JPEG/PNG) |
| Logs | `pronote` / `pronote_raw` | aucun secret dans `pronote` ; `pronote_raw` (option debug) expurge `credentials` |

## Surface exposée

- `core/ajax/pronote.ajax.php` : toutes les actions exigent `isConnect('admin')`,
  sauf `photo` (`isConnect()`, car le dashboard l'affiche). Chaque action charge
  l'équipement par id **et vérifie son type** `pronote`. Liste blanche
  d'actions ; message d'erreur générique (rien de l'entrée n'est reflété).
- `core/php/ical.php` : exige la **clé API du plugin** (`jeedom::apiAccess(..., 'pronote')`) —
  la clé du core ne suffit pas ; id casté en entier ; type vérifié ; sortie
  échappée selon RFC 5545 ; `X-Content-Type-Options: nosniff`. Le lien contient
  la clé : à ne confier qu'aux agendas de la famille. Régénérable dans
  Réglages › Système › Configuration › API.
- Panneau et page du plugin : `isConnect()` / `isConnect('admin')` ; tout texte
  venant de Pronote ou de la base passe par `htmlspecialchars` ; les blocs HTML
  (`*_html`) sont construits par le script Python avec échappement `& < >`
  (test unitaire dédié contre l'injection).
- Dossiers : `data/`, `resources/`, `tests/` → `Deny from all` ; `plugin_info/`
  → seule l'icône est servie.

## Appels sortants

- Pronote : uniquement l'URL configurée pour l'élève (HTTPS obligatoire, vérifié
  à la sauvegarde) via `pronotepy`, dans un venv isolé. Fréquence bornée (heures
  fixes), gel 30 min après « IP suspended », repli exponentiel, verrou par élève.
- `data.education.gouv.fr` : calendrier des vacances (lecture seule, cache 7 j,
  optionnel — et ignoré si l'établissement publie le sien).
- Rien d'autre : pas de télémétrie, pas de serveur intermédiaire.

## Exécution de commandes

Une seule forme : `timeout N <venv>/python3 <script> --request <fichier>` (et
`--decode-qr`, `--selftest`), chaque argument passé par `escapeshellarg`.
Aucun paramètre utilisateur n'entre dans une ligne de commande. Le script ne
lit que le fichier de requête et n'écrit que la photo (chemin fourni par PHP,
dans `data/`).

## Validation des entrées

- PIN d'enrôlement : 4 chiffres (client et serveur) ; PIN 2FA : 4 à 6 chiffres.
- URL Pronote : `https://hôte/chemin` seulement.
- Image du QR Code : taille ≤ 8 Mo, type vérifié par `getimagesize`.
- Identifiants d'équipement : `(int)`.
- Données du fichier JSON : réécrites à chaque synchro, valeurs ré-échappées à
  l'affichage — un fichier altéré ne peut pas injecter de HTML.

## Ce qui reste à votre charge

- Un administrateur Jeedom voit et peut modifier les équipements : le masque
  protège des fuites (captures d'écran, API, sauvegardes de pages), pas d'un
  administrateur malveillant.
- La clé de chiffrement est celle de Jeedom : une sauvegarde Jeedom contient
  les secrets chiffrés **et** la clé. Protégez vos sauvegardes.
- Le lien iCal vaut la clé API du plugin.
- Pronote n'a pas d'API officielle : le plugin rejoue le protocole du client
  web. Utilisez un appareil dédié (« Jeedom ») dans Pronote › Mon compte, et
  révoquez-le si vous désinstallez le plugin.

## Points connus, acceptés

- Les valeurs des commandes (`*_html`) sont en clair dans la base Jeedom, comme
  pour tout plugin : elles ne contiennent pas de secret.
- Le fichier `data/student_<id>.json` survit à une mise à jour du plugin (voulu)
  et disparaît avec l'élève ou le plugin.
