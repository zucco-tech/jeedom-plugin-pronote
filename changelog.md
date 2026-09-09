# Changelog

## 1.0.0
- Première version.
- Connexion par QR Code (mode recommandé), ENT ou identifiants directs.
- Jeton conservé et renouvelé automatiquement à chaque synchronisation ; le PIN
  2FA du compte, absent du jeton exporté, est stocké à part et renvoyé à chaque
  connexion.
- Blocs de données activables : notes, devoirs, emploi du temps, absences,
  vie scolaire, punitions, cantine, compétences. Les commandes suivent les cases
  cochées.
- Option « moyenne par matière » : une commande créée par matière suivie.
- Synchronisation par le cron Jeedom (pas de démon), avec fréquence par élève,
  plage horaire globale et délai entre deux élèves.
- Bouton « Jeu d'essai » : remplit les commandes sans contacter Pronote, pour
  valider l'installation indépendamment de l'authentification.

### Limites connues
- Le rang dans la classe n'est pas remonté : pronotepy ne l'expose pas.

### Corrections après tests sur installation réelle
- La page équipement ne dépend plus de `$plugin` : Jeedom ne le définit que si
  l'URL porte le paramètre `m`, sinon la page levait « getId() on null ».
- `plugin_info/install.php` n'a plus de garde `isConnect()` : Jeedom exécute ce
  fichier sans session, le garde renvoyait 401 et les valeurs par défaut
  n'étaient jamais écrites à l'activation.
- La plage horaire gère le passage de minuit (22:00 → 06:00) ; auparavant une
  telle plage était silencieusement ignorée et le plugin tournait 24 h/24.

### Connexion par QR Code
- L'image du QR Code se dépose directement dans l'interface : le contenu est
  décodé côté serveur (zxing-cpp, wheel autonome — pas de libzbar à installer)
  et remplit le champ. Fonctionne aussi sur une capture d'écran.
- Le champ « contenu JSON » reste disponible pour un collage manuel.
- L'image envoyée est supprimée du disque aussitôt décodée : elle porte un jeton
  d'accès au compte.

### Emploi du temps
- **Les cours du jour n'apparaissaient jamais.** `lessons(today, today)` : pronotepy
  convertit une date de fin en « ce jour à 00:00 », le filtre ne gardait rien.
  L'appel se fait sans borne de fin (fin de journée implicite).
- `timetable_html` et `homework_html` rendent un balisage structuré (heures de
  début/fin, salle, annulé, date) tout en restant des listes HTML valides.
- Nouvelles commandes `timetable_tomorrow_html` et `timetable_week_html`
  (7 jours glissants, un `<ul>` daté par jour), récupérées en **un seul appel**
  Pronote au lieu d'un par jour.
- Le widget est un **agenda** (direction choisie sur maquette) : bandeau des
  jours de la semaine en haut (aujourd'hui marqué, point sous les jours qui ont
  cours, week-end masqué s'il est vide), grille horaire dessous où chaque cours a
  la hauteur de sa durée, trait rouge à l'heure courante, cours en cours mis en
  avant, cours passés estompés, cours annulés en rouge barré. Les bornes de la
  grille suivent le premier et le dernier cours de la semaine. Il s'ouvre sur le
  prochain jour de cours quand la journée est finie. Tout est construit côté
  navigateur depuis la commande « 7 jours » et rafraîchi chaque minute.
- `next_course` bascule sur le premier cours à venir (« Demain : … »,
  « jeudi : … ») quand la journée est finie.
- Devoirs : échéance relative (« demain », « jeudi ») et mise en avant des
  devoirs pour le lendemain.
- Rendu du widget : barre de couleur stable par matière (comme Pronote), plage
  horaire début/fin, professeur et salle sous la matière, statut Pronote sur les
  cours annulés, intitulés datés (« Aujourd'hui · mercredi 9 sept. »). Cours
  passés estompés, cours en cours mis en avant — calculé à l'affichage avec
  l'heure du navigateur. Heure seule dans le pied quand la synchro date du jour.

### Devoirs dans l'agenda
- Sur le dashboard, les devoirs ne sont plus une liste sous la grille : chaque
  devoir est rattaché au **jour où il est à rendre** (bloc « À rendre demain ·
  2 » sous le bandeau des jours), et un point orange marque ces jours dans le
  bandeau. Le widget mobile garde la liste.

### Événements, vacances, matières, mobile, cantine
- **Commandes binaires pour les scénarios** : « Nouvelle note (événement) »
  (à 1 quand le nombre de notes a augmenté depuis la synchro précédente, 0
  sinon), « Cours annulé demain », « Devoir pour demain non fait ». Un scénario
  se déclenche sur le passage à 1.
- **Vacances scolaires** : suspension optionnelle depuis le calendrier officiel
  (data.education.gouv.fr, zone A/B/C, cache 7 jours, une ligne par académie
  dédoublonnée). Les marqueurs d'un seul jour du jeu de données sont
  normalisés : « Début des Vacances d'Été » court jusqu'au 31 août, un pont
  couvre sa journée entière. Calendrier indisponible = pas de suspension. Bandeau dans le
  widget et ligne sur la page Santé.
- **Notes détaillées par matière** (option « moyenne par matière ») : moyenne
  de la classe (historisée) et dernière note avec barème et date, en plus de
  la moyenne de l'élève.
- **Widget mobile dédié** : chiffres, prochain cours, devoirs, cantine — sans
  l'agenda, inadapté à un petit écran.
- **Deuxième enfant** : dupliquer l'équipement et renseigner « Enfant » — le
  jeton chiffré reste lisible sur la copie, pas de nouvel enrôlement.
- **Cantine** : menu du jour et de demain (nouvelle commande « Menu de
  demain »), affichés sur les widgets dès que Pronote les publie.

### Fiabilité (préparation à la production)
- **Secrets chiffrés au repos** (`utils::encrypt`) : jeton, mot de passe, PIN
  2FA. Migration transparente à la mise à jour ; le jeton n'est jamais renvoyé
  à l'interface, et le log brut l'expurge.
- **Verrou par élève** : une synchronisation manuelle ne chevauche jamais le
  cron — deux connexions simultanées avec le même jeton l'invalideraient.
- **Repli exponentiel** après échec : fréquence doublée à chaque échec
  consécutif, plafond 8 h, remise à zéro au premier succès. Insister toutes les
  30 min sur un jeton mort expose au blocage du compte.
- **Alertes sans spam** : un message par erreur distincte, au plus un toutes
  les 6 h, uniquement pour les erreurs qui demandent une action
  (authentification, dépendances, configuration). Les échecs de synchro sont
  journalisés en `warning` : Jeedom promeut tout log `error` en message
  (`addMessageForErrorLog`), ce qui aurait doublé l'alerte à chaque tentative.
- **Appel borné** (`timeout`, 120 s par défaut, réglable) : un Pronote qui ne
  répond plus ne bloque jamais le cron. Fichier de requête en 0600, supprimé
  quoi qu'il arrive.
- **Mise à jour du plugin** : les commandes manquantes sont créées sur les
  équipements existants, les secrets encore en clair sont chiffrés.
- **Page Santé de Jeedom** : `health()` — dépendances et état de chaque élève.
- **Compte Parents à plusieurs enfants** : champ « Enfant » ; sans lui le
  premier enfant est pris, avec un avertissement dans le log listant les noms.
- Contrôle des dépendances mis en cache 10 min (il était relancé à chaque
  affichage de page).

### Interface du plugin
- Nouvelle icône (toque et carnet, fond bleu-teal).
- Page du plugin : une **vignette par élève** — avatar aux initiales (teinte
  stable par nom), classe et établissement, état de synchronisation (à enrôler,
  synchronisé à HH:MM, erreur, désactivé).
- Page élève : **fiche en tête** (avatar, nom éditable, classe · établissement,
  puces jeton et dernière synchro, boutons Synchroniser / Jeu d'essai, bandeau
  d'erreur si la dernière synchro a échoué) ; **enrôlement QR en trois étapes**
  dont l'état suit l'avancement ; **données à récupérer en cartes** avec icône et
  description ; sections aérées, toujours sur les champs `eqLogicAttr` standard.
- La classe et l'établissement sont remontés de Pronote à chaque synchronisation
  (sur un compte Parents, depuis l'enfant sélectionné).

### Menu Plugins
- `info.json` ne déclare plus `display`/`filepath`/`index` : Jeedom déduit la
  page de l'id, et ces clés fabriquaient l'URL `pronote.php.php` depuis le menu.

### Widget du dashboard
- Tuile compacte activée par défaut, calquée sur la maquette : moyenne, devoirs,
  absences en chiffres, pastilles uniquement quand quelque chose mérite
  l'attention (devoirs pour demain, nouvelle note, message, cours annulé,
  retard), prochain cours, liste des devoirs, date de synchronisation, bandeau
  d'erreur si le jeton expire.
- Couleurs prises dans les variables du thème Jeedom (`--txt-color`,
  `--al-warning-color`…) : rendu correct en sombre comme en clair.
- Sans aucune note, la moyenne s'affiche « — » au lieu d'un faux « 0/20 ». Le
  script n'écrit alors rien pour la moyenne — Jeedom transforme une valeur vide
  en 0 sur une commande numérique — et `last_grade` vide sert de signal.
- La tuile standard reste disponible en décochant « Widget personnalisé ».

### Enregistrement de l'appareil (2FA Pronote)
- Certains comptes exigent que l'appareil soit enregistré : pronotepy lève alors
  « A device identifier is required for this account ». L'identifiant vient du
  paramètre `device_name`, qui n'était pas transmis. Il l'est désormais, avec
  « Jeedom » par défaut et un champ pour le personnaliser.
- Comme le PIN 2FA, `device_name` est absent de `export_credentials()` : il est
  stocké dans l'équipement et renvoyé à chaque connexion, pas seulement à
  l'enrôlement.
- Messages d'erreur d'authentification réécrits en clair (appareil non
  enregistré, PIN refusé).

### Corrections après essais dans le navigateur
- **`sendVarToJS('eqType', ...)` manquait.** `plugin.template.js` s'appuie sur
  cette variable globale pour sauvegarder, supprimer et configurer : sans elle,
  `eqType is not defined` et tous ces boutons restaient muets, y compris ceux du
  cœur. Tous les plugins Jeedom la déclarent ; celui-ci le fait maintenant, et un
  test vérifie qu'elle est bien émise.
- **JavaScript réécrit pour l'API Jeedom 4.6.** Le fichier visait l'API 4.0 :
  `jeedom.cmd.addAttribute()` n'existe plus, l'exception coupait le chargement de
  l'équipement et la sauvegarde ne prenait plus. Désormais DOM natif,
  `setJeeValues()`, `jeeValue()` et `jeedomUtils.showAlert()`, sans jQuery.
- **Une sauvegarde ne vide plus les commandes.** Jeedom supprime à la sauvegarde
  toute commande absente du tableau envoyé ; `dontRemoveCmd()` ne protégeait que
  deux commandes, l'équipement tombait de 18 à 2. Toutes les commandes générées
  sont maintenant protégées : seul le décochage d'un bloc en supprime.
- Les suites de tests utilisent des identifiants préfixés `__selftest_` pour ne
  jamais écraser un équipement réel.

### Tests
`sudo -u www-data php plugins/pronote/tests/run_tests.php` — 37 vérifications :
commandes, blocs activables, cron, délai entre élèves, chemins d'erreur, widgets.
La suite est rejouable et supprime ses équipements ; `KEEP_TEST_EQ=1` les conserve.
Elle ne contacte jamais Pronote : l'authentification réelle demande un compte.

`tests/run_tests_integration.php` — 39 vérifications supplémentaires,
**destructives, à réserver à une installation de test** : endpoints AJAX,
commande d'action, valeurs longues, cycle d'activation du plugin, suppression en
cascade, et avec `DEPS=1` la destruction puis reconstruction du venv Python.
