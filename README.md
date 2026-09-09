# Plugin Pronote pour Jeedom

Remonte dans Jeedom les notes, devoirs, emploi du temps, absences, vie scolaire
et menus d'un ou plusieurs enfants, depuis Pronote (via `pronotepy`).

- Connexion par **QR Code** (recommandée), ENT ou identifiants ; jeton renouvelé
  automatiquement, secrets chiffrés au repos.
- Widget **agenda** sur le dashboard (grille horaire, bandeau des jours, cours en
  cours), widget mobile dédié.
- Commandes typées pour les scénarios : nouvelle note, cours annulé demain,
  devoir pour demain non fait.
- Synchronisation par le cron Jeedom (pas de démon), repli après échec,
  suspension pendant les vacances scolaires (calendrier officiel).

Documentation : [docs/fr_FR/index.md](docs/fr_FR/index.md) — historique :
[changelog.md](changelog.md).

## Installation

Jeedom 4.4+, PHP 8, Python 3.

Dans Jeedom : Réglages › Système › Configuration › *Mises à jour/Market* ›
cocher **GitHub**, puis Plugins › Gestion des plugins › ➕ › onglet **GitHub** :
`zucco-tech` / `jeedom-plugin-pronote` / branche `main` / identifiant `pronote`.
Activer le plugin et lancer l'installation des dépendances (venv Python dans
`resources/venv`, obligatoire sur Debian 12+). Les mises à jour se font ensuite
par le bouton **Mettre à jour** du plugin.

## Tests

```bash
sudo -u www-data php plugins/pronote/tests/run_tests.php
sudo -u www-data php plugins/pronote/tests/run_tests_integration.php   # destructif : installation de test uniquement
```

Les suites ne contactent jamais Pronote ; l'authentification réelle se vérifie
avec un compte, par le bouton **Enrôler**.
