# Plugin Pronote pour Jeedom — bêta

> **Statut : bêta.** Testé sur une seule installation (Jeedom 4.6.1, Debian 13,
> PHP 8.4), un seul compte Parents, un seul établissement, en septembre 2026.
> Attendez-vous à des surprises : faites une sauvegarde Jeedom avant
> d'installer, et ne bâtissez pas dessus quelque chose de critique. Les retours
> passent par les *issues*.

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
[changelog.md](changelog.md) — licence : [GPL-3.0](LICENSE).

## Signaler un problème

Les *issues* de ce dépôt sont ouvertes à tous. Deux choses à savoir avant d'en
ouvrir une :

- **Ne collez jamais un jeton, le contenu d'un QR Code, un mot de passe, un
  code PIN ni le log `pronote_raw`.** Ce sont des accès au compte Pronote — le
  vôtre et celui de votre enfant. Le log `pronote` suffit, il ne contient aucun
  secret.
- **Pronote n'a pas d'API officielle.** Le plugin s'appuie sur
  [pronotepy](https://github.com/bain3/pronotepy), qui rejoue le protocole du
  client web. Une mise à jour de Pronote côté établissement peut casser la
  connexion pour tout le monde en même temps : c'est désagréable, mais ça se
  voit vite, et chez plusieurs personnes — regardez si une issue existe déjà
  avant d'en ouvrir une.

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
