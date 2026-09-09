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
