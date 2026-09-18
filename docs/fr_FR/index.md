# Plugin Pronote

> **Version bêta.** Une seule installation testée à ce jour. Sauvegarder Jeedom
> avant d'installer ; signaler les problèmes dans les *issues* du dépôt.

Remonte dans Jeedom les notes, devoirs, emploi du temps, absences et messages de
vie scolaire d'un ou plusieurs enfants.

## Avant de commencer

Pronote n'a **pas d'API publique**. Le plugin s'appuie sur `pronotepy`, qui rejoue
le protocole du client web. Conséquences à accepter :

- une montée de version de Pronote côté établissement peut casser la connexion ;
- les identifiants directs sont refusés par la plupart des établissements : le
  **QR Code** est le mode fiable ;
- interroger trop souvent expose à un blocage temporaire du compte.

## Installation

Après activation, aller dans la configuration du plugin et lancer l'installation
des dépendances. Elle crée un environnement Python isolé dans
`plugins/pronote/resources/venv` (obligatoire sur Debian 12 et suivants).

## Connexion par QR Code

1. Dans Pronote (application ou espace web) : **Mon compte › Autoriser un accès mobile**.
2. Choisir un code PIN à 4 chiffres, afficher le QR Code.
3. Dans Jeedom, coller le contenu du QR Code et le code PIN, puis **Enrôler**.

Le QR Code et son PIN expirent au bout de quelques minutes. Une fois l'enrôlement
réussi, Jeedom conserve un jeton qu'il renouvelle seul à chaque synchronisation :
il n'y a plus rien à refaire, sauf si le jeton est perdu.

## Vérifier l'installation sans Pronote

Le bouton **Jeu d'essai** remplit les commandes avec des valeurs fictives. Il
valide toute la chaîne Jeedom (commandes, historisation, widget) sans dépendre de
l'authentification Pronote — à utiliser en premier en cas de problème.

## Commandes

Les commandes sont créées automatiquement selon les cases cochées dans l'onglet
Équipement. Décocher un bloc supprime ses commandes.

Toujours présentes, sans connexion supplémentaire : période en cours (nom, fin,
avancement, jours restants), fin de l'année scolaire, prochaines vacances telles
que l'établissement les publie (nom, début, fin, jours restants).

Pour les scénarios, quatre binaires passent à 1 quand quelque chose arrive :
« Nouvelle note », « Moyenne en baisse » (une matière a perdu 0,5 point ou plus
depuis la synchro précédente — le détail est dans « Matières en baisse »),
« Cours annulé demain », « Devoir pour demain non fait ».

## La maison qui parle : briefings, réveil, événements

Quatre commandes texte sont produites à chaque synchronisation, quels que
soient les blocs cochés — pensées pour une enceinte (TTS), une notification
ou un écran :

- **Briefing du soir (demain)** : « Demain, Cléa commence à 8h10 par
  Histoire-Géo et finit à 16h30. Contrôle de Mathématiques. Il y a sport :
  penser à la tenue. Un devoir à faire pour demain : Français. À la cantine :
  lasagnes… ». Le vendredi soir, il annonce la reprise du lundi.
- **Briefing du matin (aujourd'hui)** : même chose pour la journée.
- **Dernier événement (texte)** : « Nouvelle note : Mathématiques 16/20 ·
  Devoir pour demain non fait » — à envoyer tel quel sur un téléphone.
- **Bilan de la semaine (texte)** : notes de la semaine, moyenne, absences non
  justifiées, contrôles à venir, devoirs à rendre.

Et pour les scénarios : **Heure de réveil demain** (premier cours moins
l'avance réglée dans la configuration, 75 min par défaut), **Premier cours /
Fin des cours demain**, **Pas de cours demain**, **Sport demain**, **Contrôle
demain**, **Prochain contrôle**, **Matières de demain**, **Tendance de la
moyenne** (30 jours, en points, depuis l'historique Jeedom).

Exemples de scénarios :

| Quand | Faire |
|---|---|
| Tous les jours à 19:30, si `Pas de cours demain` = 0 | TTS sur l'enceinte du salon : `#[Maison][Cléa][Briefing du soir (demain)]#` |
| Tous les jours à 6:00 | Programmer le réveil de la chambre à `#[Maison][Cléa][Heure de réveil demain]#` (calculé la veille) |
| Sur `Nouvelle note (événement)` = 1 | Notification : `#[Maison][Cléa][Dernier événement (texte)]#` |
| Sur `Cours annulé demain` = 1 | Notification aux deux parents |
| Sur `Sport demain` = 1, à 20:00 | TTS : « Tenue de sport à préparer » |
| Dimanche 18:00 | Message : `#[Maison][Cléa][Bilan de la semaine (texte)]#` |
| Sur `Moyenne en baisse (événement)` = 1 | Notification : `#[Maison][Cléa][Matières en baisse]#` |

## Cocher un devoir « fait » depuis Jeedom

Dans le panneau, chaque devoir a une case (administrateur, élève enrôlé).
La cocher **écrit dans Pronote** — c'est le même coche que dans l'application
— et rafraîchit les données de l'élève dans la foulée. Une connexion Pronote
par clic : à utiliser à la main, pas dans un scénario en boucle.

## Mode discret

Dans la fiche de l'élève, « Mode discret » masque la moyenne et les matières en
baisse sur les widgets (écran partagé dans le salon, visiteurs). Le panneau et
les commandes restent complets.

## Panneau

Configuration du plugin › Affichage › **Panneau « Pronote »** ajoute une page
sous le menu Accueil : un onglet par élève, chiffres clés, alertes, emploi du
temps sur deux semaines, devoirs jour par jour, moyennes par matière (barres
élève / classe), dernières notes, vie scolaire, messagerie, cantine et lien
d'abonnement agenda. Le bouton **Synchroniser** y déclenche une synchro de
l'élève affiché.

## Agenda (iCal)

Chaque élève a un lien d'abonnement, visible dans sa fiche (section « Agenda »)
et dans le panneau. À coller dans « S'abonner à un calendrier » de Google
Agenda, Apple Calendrier, Outlook ou Thunderbird : cours (les annulés sont
marqués), devoirs en journée entière, vacances de l'établissement. Rafraîchi
par l'agenda toutes les 6 h environ, à jour à chaque synchronisation.

Options en fin de lien : `&todo=1` (devoirs en tâches VTODO au lieu
d'événements), `&vacances=0` (sans les vacances).

Le lien contient la clé API du plugin : ne le partagez qu'avec les agendas de
la famille. Elle se régénère dans Réglages › Système › Configuration › API.

## Photo de profil

Option **Récupérer la photo de profil** (Configuration du plugin › Données),
désactivée par défaut. Si l'établissement la publie, elle remplace les
initiales dans les widgets, la page du plugin et le panneau. Elle est stockée
dans le dossier protégé du plugin, servie uniquement à un utilisateur connecté
et supprimée avec l'élève.

## Vacances : zone ou établissement

La pause pendant les vacances peut suivre le calendrier officiel de la zone
(A/B/C) ou celui que l'établissement publie dans Pronote (ponts, fermetures,
vacances propres). Par défaut, l'établissement prime quand il existe ; réglage
« Calendrier » dans la configuration du plugin.

## Tests

```
sudo -u www-data php plugins/pronote/tests/run_tests.php
```

80 vérifications sur l'installation réelle : création des commandes, blocs
activables, cron et délai entre élèves, chemins d'erreur, widgets, panneau,
vacances de l'établissement, matières en baisse. Ajouter `KEEP_TEST_EQ=1` pour
conserver l'équipement de test créé.

`tests/run_tests_integration.php` (113 vérifications, destructif, installation
de test seulement) couvre en plus les endpoints AJAX, l'export iCal en HTTP
réel, la photo, les `.htaccess`, le masquage des secrets et la suppression en
cascade. `pytest tests/test_fetch.py` teste le script Python seul.

Sécurité : voir `SECURITY.md`.

## Ce que le plugin ne fait pas

- **Le rang dans la classe** n'est pas remonté : pronotepy ne l'expose pas, et le
  recalculer à partir des moyennes donnerait un chiffre faux.
- L'authentification réelle n'a pas de test automatique : elle demande un compte
  Pronote. En cas de souci, cocher « conserver les réponses brutes » dans la
  configuration du plugin et lire le log `pronote_raw`.

## Passage sur un Jeedom de production

1. **Sauvegarde** Jeedom d'abord (Réglages › Système › Sauvegardes).
2. **Installer le plugin depuis GitHub**, directement dans Jeedom :
   - une seule fois : Réglages › Système › Configuration › onglet
     *Mises à jour/Market* › cocher **GitHub** (aucun token nécessaire, le
     dépôt est public) ;
   - Plugins › Gestion des plugins › ➕ › onglet **GitHub** :
     utilisateur `zucco-tech`, dépôt `jeedom-plugin-pronote`, branche `main`,
     identifiant du plugin `pronote` › **Installer**.

   Le bouton **Mettre à jour** du plugin récupère ensuite la dernière version
   du dépôt. (Alternatives : déposer le zip via « depuis un fichier », ou
   cloner le dépôt dans `plugins/pronote` puis
   `chown -R www-data:www-data plugins/pronote`.)
3. **Activer** le plugin, puis lancer l'installation des **dépendances** (venv
   Python, une à deux minutes). Vérifier « Dépendances OK · pronotepy x.y.z »
   sur la page du plugin.
4. **Configuration du plugin** : plage horaire, délai entre appels, vacances
   scolaires (zone), widget personnalisé — ces réglages ne se transportent pas.
5. **Créer l'élève** (compte, URL Pronote, données à récupérer, fréquence) et
   **enrôler un QR Code neuf**. Le jeton est chiffré avec la clé de l'instance :
   celui du Jeedom de test ne peut pas être copié.
6. **Synchroniser** une première fois depuis la fiche élève, puis vérifier la
   page **Santé** de Jeedom (Analyse › Santé) : dépendances, vacances, état de
   chaque élève.
7. Placer le widget sur le dashboard (objet parent), et créer les scénarios sur
   les commandes binaires (nouvelle note, cours annulé demain, devoir non
   fait).

Ne pas lancer `tests/run_tests_integration.php` en production : la suite est
destructive (désactive le plugin, supprime des équipements, réinstalle les
dépendances). `tests/run_tests.php` est sans danger.

### Mise à jour ultérieure

Plugins › Gestion des plugins › Pronote › **Mettre à jour** (source GitHub) :
le hook de mise à jour crée les commandes manquantes et chiffre les secrets
encore en clair. Si les dépendances passent en « non installées » après la mise
à jour, relancer leur installation.

## Signaler un problème

Ouvrir une *issue* sur le dépôt GitHub, avec le modèle proposé. **Ne jamais y
coller un jeton, le contenu d'un QR Code, un mot de passe, un code PIN ni le log
`pronote_raw`** : ce sont des accès au compte Pronote. Le log `pronote` suffit.

Pronote n'a pas d'API officielle et le plugin dépend de `pronotepy` : une mise à
jour de Pronote peut casser la connexion pour tous les utilisateurs à la fois.
Vérifier si une issue existe déjà avant d'en ouvrir une.
