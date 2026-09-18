# Publier le plugin sur le Market Jeedom

Tutoriel pas à pas, écrit pour ce dépôt. Le Market est gratuit pour un plugin
gratuit ; un plugin **payant** passe par les CGU Développeur du Market (déjà
acceptées sur ton profil) — Jeedom encaisse et reverse, le prix se règle sur la
fiche. Le dépôt GitHub peut être **privé** : le Market le lit avec le jeton que
tu lui donnes et livre lui-même le zip aux acheteurs ; ta propre prod, installée
« depuis GitHub », se met à jour avec un jeton GitHub renseigné dans Jeedom
(Réglages › Système › Configuration › Mises à jour/Market › GitHub › token, ou
dans la fiche du plugin). Ce qui prend du temps, c'est la
validation du compte développeur par l'équipe Jeedom, pas la technique.

> Source : [doc.jeedom.com › Publication d'un plugin](https://doc.jeedom.com/fr_FR/dev/publication_plugin)
> et [structure de info.json](https://doc.jeedom.com/fr_FR/dev/structure_info_json).
> Les écrans du Market changent parfois ; si un libellé diffère, la logique reste la même.

## Comment ça marche

- Le Market **ne stocke pas le code** : il lit le dépôt GitHub (public ou privé)
  et associe une **branche** à chaque canal : *Beta* et *Stable*.
- Un plugin peut être **privé** (visible seulement de son auteur, installable
  depuis son propre Jeedom une fois connecté au Market) ou **public**.
- Le canal **Beta** est sous la responsabilité de l'auteur. Le canal **Stable**
  d'un plugin public est relu par l'équipe Jeedom avant d'apparaître.
- Le Market synchronise tout seul chaque jour vers 12 h 10, ou à la main depuis
  la fiche du plugin (« Test/Synchroniser »).

Plan raisonnable pour ce plugin : **Beta, privé** d'abord → test d'installation
depuis le Market sur le Jeedom de test → **Beta, public** → plus tard, quand
d'autres utilisateurs l'auront fait tourner, **Stable**.

## Ce qui est déjà prêt dans le dépôt

| Exigence du Market | État |
|---|---|
| `plugin_info/info.json` complet (`id`, `name`, `description` ≥ 80 caractères, `licence`, `author`, `require`, `category`, `documentation`, `changelog`, `*_beta`, `language`, `issue`) | ✅ |
| `id` sans accent ni `_`, commence par une lettre | ✅ `pronote` |
| `category` dans la nomenclature Jeedom | ✅ `organization` |
| Icône `plugin_info/pronote_icon.png` (128 × 128, PNG) | ✅ |
| Documentation utilisateur `docs/fr_FR/index.md` | ✅ |
| `changelog.md` | ✅ |
| Licence déclarée (`LICENSE`, propriétaire) | ✅ — un plugin payant demande le contrat développeur Jeedom (Market › CGU Développeur, déjà acceptées) |
| Branche `beta` (ce que le Market lira) | ✅ créée depuis `main` |
| Branche `stable` | ⏳ plus tard |
| Captures d'écran pour la fiche Market | ⏳ **à faire par toi** (voir plus bas) |
| Documentation en anglais `docs/en_US/index.md` | optionnel |

## Étape 1 — Devenir développeur (une seule fois)

**Tant que cette étape n'est pas validée, le bouton « Ajouter » du Market
n'existe pas et le Salon des développeurs n'accepte pas tes messages** : c'est
normal de « ne pas trouver où poster ».

1. Connecte-toi sur [market.jeedom.com](https://market.jeedom.com) avec ton
   compte Market (celui déjà lié à ton Jeedom).
2. Ouvre le formulaire d'inscription développeur :
   <https://market.jeedom.com/index.php?v=d&p=becomeDeveloper>
   (c'est aussi le lien « s'inscrire en tant que développeur » en haut de
   [doc.jeedom.com › dev](https://doc.jeedom.com/fr_FR/dev/)). Remplis-le,
   en indiquant ton **pseudo Community** : l'équipe en a besoin pour ouvrir
   l'accès au forum.
3. Attends la validation par l'équipe Jeedom. D'après les retours sur
   Community, compter **une à deux semaines**. Tu reçois un courriel disant que
   tes droits Market sont à jour.
4. Après validation :
   - sur le Market, **Mon profil › Pour les développeurs** permet de renseigner
     ton nom d'auteur (pseudo Community) ;
   - sur [community.jeedom.com](https://community.jeedom.com), le
     [Salon des Développeurs](https://community.jeedom.com/c/developpeur-developpeurs/5)
     devient accessible en écriture (un titre/« flair » développeur apparaît
     dans tes préférences).
5. Sans nouvelles après deux semaines : écrire à `partenaire@jeedom.com`, ou
   poster dans [Market Jeedom](https://community.jeedom.com/c/market-jeedom/72)
   (catégorie ouverte à tous) en rappelant ton pseudo Market. C'est ce qui a
   débloqué les autres développeurs.

## Étape 2 — Présenter le plugin sur Community

Avant la mise sur le Market, l'usage est de présenter le plugin dans
**Salon des développeurs › Présentation plugin**, avec des tags. Texte prêt à
coller (adapte les liens de captures) :

```
Titre : [Présentation] Pronote — suivi scolaire (notes, devoirs, emploi du temps)
Tags : python, dependance_install, cron, beta, gratuit

Bonjour,

Je présente **Pronote** (id : `pronote`), plugin en **bêta** (gratuit pendant la bêta, payant ensuite).

**Ce qu'il fait** : remonte dans Jeedom, pour un ou plusieurs enfants, les notes
et moyennes, les devoirs (avec échéance), l'emploi du temps sur 7 jours (cours
annulés compris), absences/retards/punitions, messages de vie scolaire et menus
de cantine. Widget « agenda » sur le dashboard, widget mobile dédié, commandes
binaires pour les scénarios (nouvelle note, cours annulé demain, devoir pour
demain non fait).

**Technique** : PHP côté Jeedom + un script Python (pronotepy) dans un venv
installé par les dépendances (`hasDependency`). Pas de démon : synchronisation
par le cron Jeedom, à heures fixes par défaut (4 fois par jour). Pas de panel.
Secrets chiffrés au repos ; jeton renouvelé automatiquement.

**À savoir** : Pronote n'a pas d'API officielle. Le plugin s'appuie sur pronotepy,
qui rejoue le protocole du client web ; une mise à jour de Pronote côté
établissement peut casser la connexion. La connexion par QR Code est le mode
recommandé. C'est pourquoi le plugin sort en bêta : une seule installation
testée à ce jour (Jeedom 4.6, Debian 13, compte Parents).

**Dépôt** : https://github.com/zucco-tech/jeedom-plugin-pronote (privé, licence propriétaire — accès lecture fourni au Market)
**Documentation** : https://github.com/zucco-tech/jeedom-plugin-pronote/blob/main/docs/fr_FR/index.md

Retours bienvenus, en particulier de parents dont l'établissement impose l'ENT.
```

## Étape 3 — Le jeton GitHub (à faire toi-même)

Le Market lit ton dépôt avec un jeton **que tu crées et colles toi-même** ; ne le
donne à personne, ne le mets ni dans le dépôt ni dans une conversation.

1. GitHub › Settings › Developer settings › **Personal access tokens** ›
   *Fine-grained tokens* › Generate new token.
2. Nom : `jeedom-market`. Expiration : *No expiration* (le Market ne prévient
   pas quand un jeton expire — la synchro s'arrête en silence).
3. Repository access : *Only select repositories* → `jeedom-plugin-pronote`.
4. Permissions › Repository › **Contents : Read-only** (rien d'autre).
5. Copie le jeton : il ne sera plus affiché.

Ne coche pas « le Market gère la traduction » : cette option donne au compte
`jeedom-market` un accès en écriture au dépôt ; inutile pour un plugin en
français seul.

## Étape 4 — Créer la fiche sur le Market

Market › **Mes plugins** (ou l'icône « market ») › **Ajouter**.

**Général**
- Prix : `0` (gratuit)
- Id : `pronote` — identique à `plugin_info/info.json`, sans espace
- Nom : `Pronote`
- Catégorie : *Organisation* (= `organization`)
- Privé : **oui** pour commencer

**Documentation et liens**
- Description : reprendre la section « Ce qu'il fait » ci-dessus. C'est le texte
  de la fiche, pas celui de `info.json`.
- Langues : Français
- Matériel compatible : celui que tu as testé (Debian / LXC). Ajouter Smart,
  Luna, RPi au fur et à mesure des retours.
- Note sur l'utilisation : « Nécessite un compte Pronote avec accès mobile
  autorisé (QR Code). Pronote n'a pas d'API officielle : voir la documentation. »

**GitHub**
- Token : celui de l'étape 3
- Nom d'utilisateur GitHub : `zucco-tech` — **sans espace avant ni après**
  (un espace parasite est une cause classique d'échec)
- Dépôt : `jeedom-plugin-pronote`

**Branches**
- Beta : `beta`
- Stable : laisser vide pour l'instant
- V3 : laisser vide

Cliquer **Valider**, puis seulement ensuite **Test/Synchroniser**. Le Market
va chercher `plugin_info/info.json`, l'icône, la doc et le changelog sur la
branche `beta`. En cas d'erreur, le message dit quel fichier lui manque.

**Images** : ajouter 3 à 4 captures sur la fiche (elles ne viennent pas du
dépôt) :
1. le widget agenda sur le dashboard,
2. la page du plugin avec la liste des élèves,
3. la fiche d'un élève (enrôlement QR),
4. le widget mobile.
Prends-les sur ta prod, **en masquant le nom de l'enfant et de l'établissement**
si tu ne veux pas les publier.

## Étape 5 — Tester l'installation depuis le Market

Sur le Jeedom de test : Plugins › Gestion des plugins › Market › chercher
`pronote` (un plugin privé n'apparaît que pour son auteur, connecté au Market
dans Réglages › Système › Configuration › Mises à jour/Market). Installer
la version **bêta**, activer, installer les dépendances, faire un **Jeu
d'essai**. C'est exactement ce que verra un utilisateur.

Si tout passe : Market › fiche du plugin › décocher **Privé**. Le plugin est
alors public, en bêta, et l'onglet « bêta » du Market le liste.

## Étape 6 — Passer en Stable (plus tard)

Quand plusieurs personnes l'auront installé sans casse :

1. `git push origin main:stable` (ou fusionner `beta` dans `stable`).
2. Sur la fiche Market, renseigner Stable : `stable`, Valider, Synchroniser.
3. Dans `info.json`, passer `version` en `1.0.0` et ajouter l'entrée de
   changelog. Retirer « BÊTA — » de la description et le bandeau bêta de
   l'interface.
4. L'équipe Jeedom relit le plugin avant de le rendre visible en stable :
   les échanges se font sur Community (Salon des développeurs). Points qu'elle
   regarde en général : que `install.php` et les dépendances n'abîment rien
   d'autre sur la machine, qu'aucun secret n'est journalisé, que le plugin ne
   martèle pas un service tiers, que la documentation existe. Tout cela est
   déjà traité ici — mentionner la limitation « API non officielle » d'emblée
   évite un aller-retour.

## Mettre à jour ensuite

Le cycle devient :

```bash
git push origin main:beta
```

puis « Test/Synchroniser » sur la fiche Market (ou attendre 12 h 10). Les
utilisateurs voient la mise à jour dans Gestion des plugins. Pense à ajouter
une entrée dans `changelog.md` à chaque poussée : c'est ce texte que le Market
affiche.

Ta prod, installée aujourd'hui « depuis GitHub », peut rester ainsi ou être
réinstallée depuis le Market une fois le plugin public — les équipements et
le jeton sont conservés, c'est le même id.

## Pièges connus

- Espace au début ou à la fin du nom d'utilisateur ou du dépôt → erreur d'URL.
- Champs de branche : **juste le nom** (`beta`), pas `origin/beta` ni une URL.
- `id` du Market ≠ `id` de `info.json` → le plugin s'installe sous un autre
  nom et ne trouve pas ses fichiers.
- Description de `info.json` < 80 caractères → refus à la synchro.
- Jeton GitHub expiré → la fiche ne se met plus à jour, sans message.
- Un plugin mis en public ne peut pas revenir vraiment « invisible » : les
  utilisateurs qui l'ont installé le gardent. Mieux vaut tester en privé.
