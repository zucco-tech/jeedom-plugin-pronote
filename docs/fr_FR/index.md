# Plugin Pronote

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

## Tests

```
sudo -u www-data php plugins/pronote/tests/run_tests.php
```

37 vérifications sur l'installation réelle : création des commandes, blocs
activables, cron et délai entre élèves, chemins d'erreur, rendu des widgets.
Ajouter `KEEP_TEST_EQ=1` pour conserver l'équipement de test créé.

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
