# Pronote (ce plugin) et ProJote : comparaison

> **Mise à jour 18/09/2026, version 1.1.0-beta.1.** La plupart des manques
> relevés ci-dessous ont été comblés le jour même : panneau multi-élèves,
> export iCal, période en cours et vacances publiées par l'établissement,
> matières en baisse, messagerie et informations, labels de cantine, photo
> de profil, compatibilité Python 3.9, CI GitHub. Le tableau original est
> conservé tel quel comme point de départ ; la section « Où en est-on » en
> fin de document fait le bilan.

Établie le 18/09/2026 à partir du code de
[ProJote 1.4.1](https://github.com/Aldarande/ProJote) (dépôt public, AGPL v3,
auteur Aldarande, stable sur le Market depuis mai 2026, actif depuis mai 2024)
et de Pronote 1.0.0-beta.1. Les deux s'appuient sur `pronotepy` ; les
descriptions se ressemblent forcément. Ce qui diffère, c'est l'architecture et
le parti pris.

## En une phrase

**ProJote** est un plugin complet et mûr (panneau, statistiques, iCal, photo,
messagerie, webhooks, deux ans d'historique). **Pronote** est un plugin léger,
sans démon, conçu pour se connecter le moins possible et pour afficher la
journée sous forme d'agenda.

## Tableau

| | ProJote 1.4.1 | Pronote 1.0.0-beta.1 |
|---|---|---|
| **Maturité** | 2,5 ans, stable Market, 12 versions, audit sécurité publié, CI GitHub, tests pytest | Bêta, 1 installation testée, 142 vérifications PHP, pas de CI |
| **Architecture** | Démon Python permanent (socket, port 55369) + `hasOwnDeamon` | Script Python lancé par le cron Jeedom, aucun processus permanent |
| **Connexion** | QR Code (décodage **côté navigateur**, jsQR), identifiants, ENT/CAS ; « jeton de secours » sur disque ; couche de compatibilité PRONOTE 2026.2.5 | QR Code (décodage **côté serveur**, image supprimée aussitôt), identifiants, ENT ; PIN 2FA et nom d'appareil rejoués à chaque connexion |
| **Fréquence** | Toutes les heures de 4 h à 22 h (18 cycles), **7 authentifications par cycle** (40 avant 1.4.1) → ≈ 125 connexions/jour/élève | **Heures fixes** 06:30 · 12:00 · 16:30 · 20:00, 1 authentification par synchro → **4 connexions/jour/élève** |
| **Protection contre le blocage IP** | Fenêtre nocturne | Fenêtre horaire, gel de 30 min si « IP suspended », repli exponentiel après échec (plafond 8 h), verrou par élève, **pause pendant les vacances** (calendrier officiel data.education.gouv.fr, zone A/B/C) |
| **Secrets** | AES-256-CBC maison, clé dérivée de l'apikey (documenté « non authentifié » dans leur audit) | `utils::encrypt` du core Jeedom ; jamais renvoyés à l'interface ; log brut expurgé |
| **Notes** | Liste, dernière note, moyennes générale/classe par période, **historique + courbes**, matières en baisse | Moyennes générale/classe, dernière note, option moyenne + moyenne classe + dernière note **par matière** (commandes historisées), événement « nouvelle note » |
| **Emploi du temps** | Jour, J+1 à J+4, cours annulés, **export iCalendar** (cours + devoirs) | 7 jours glissants en **un seul appel**, cours annulés, prochain cours, événement « cours annulé demain » ; pas d'iCal |
| **Devoirs** | Liste, lendemain, état fait, événement `nouveau_devoir` | Liste avec échéance relative, lendemain, « devoir pour demain non fait » (événement), rattachés au jour dans l'agenda |
| **Vie scolaire** | Absences, retards, punitions, **notifications, messagerie**, compétences | Absences, retards, punitions, compteur de messages, compétences (HTML) |
| **Cantine** | Menus avec allergènes/labels | Menu du jour et de demain |
| **Périodes / vacances** | Période en cours (trimestre/semestre), bornes de l'année, prochaines vacances **depuis Pronote** | Vacances depuis le calendrier officiel (indépendant de l'établissement), utilisées pour suspendre la synchro |
| **Affichage** | **Panneau desktop** multi-élèves (onglets identité/stats/EDT/notes/devoirs), widgets par commande, photo de profil (Pronote ou manuelle), onglet statistiques, centre d'alertes | Widget **agenda** (bandeau des jours, grille horaire proportionnelle, trait « maintenant », devoirs sur leur jour), widget mobile dédié ; pas de panneau |
| **Scénarios** | `nouvelle_note`, `nouveau_devoir`, webhooks | `grade_new_event`, `course_cancelled_tomorrow`, `homework_tomorrow_pending` |
| **Multi-enfants** | Compte parent, sélection d'enfant | Compte parent, champ « Enfant », duplication d'équipement sans ré-enrôlement |
| **Inter-plugins** | — | `readersOf()` : affiche quels plugins utilisent un élève (busscolaires) |
| **Compatibilité annoncée** | Smart, Luna, Atlas, RPi, Docker, DIY ; Python 3.9 géré | Debian 11+, testé DIY/LXC uniquement |
| **Documentation** | Site GitHub Pages, FAQ, 6 exemples de scénarios | `docs/fr_FR/index.md`, README, tutoriel Market |
| **Licence** | AGPL v3 | Propriétaire depuis 1.1.0 (GPL-3.0 avant) |

## Ce que ProJote fait mieux

- Couverture fonctionnelle : panneau, statistiques historisées, iCal, photo,
  messagerie, webhooks, allergènes. Pronote n'a rien de tout ça.
- Recul : deux ans de corrections face aux évolutions de Pronote (KeyError
  `onload`, challenge 2026.2.5, Debian bullseye). Une compatibilité qui se
  paie en versions successives — Pronote n'a pas encore vécu une rentrée.
- Processus : audit de sécurité publié, CI, ruff, tests unitaires Python.
- Doc utilisateur riche, avec des scénarios prêts à l'emploi.

## Ce que Pronote fait mieux

- **Frugalité des connexions** : 4 par jour contre ≈ 125. C'est la différence
  la plus concrète — Index Éducation suspend les IP trop bavardes, et ce
  plugin a été conçu après avoir subi la suspension. À nuancer : ProJote
  passe par le démon et peut réutiliser sa session ; le chiffre de 7
  authentifications par cycle vient de leur propre commentaire de code.
- **Aucun démon** : rien à surveiller, pas de port, pas de redémarrage ; le
  cron Jeedom fait tout. Sur une machine modeste, c'est un processus de
  moins.
- **Résilience** : repli exponentiel, gel après suspension, verrou, pause
  vacances — quatre mécanismes que ProJote n'a pas.
- **Secrets** : chiffrement par le core Jeedom plutôt qu'un AES maison, QR
  décodé côté serveur et image supprimée (côté navigateur, le contenu du QR
  — un jeton — transite par le DOM).
- **Widget agenda** : lecture immédiate de la journée, devoirs rattachés au
  jour ; ProJote affiche des listes.
- Détail par matière en commandes historisables (courbes Jeedom natives sans
  onglet dédié).

## Ce que ça veut dire

Pour un utilisateur qui choisit aujourd'hui : **ProJote est le choix
raisonnable** — stable, complet, documenté. Pronote vaut le coup si l'on veut
un plugin simple, sans démon, économe avec Pronote, et un affichage agenda ;
et il faut accepter d'essuyer les plâtres.

Pour le Market : les deux peuvent coexister (le Market accepte plusieurs
plugins sur un même service), mais la fiche doit annoncer la différence
d'emblée — « léger, sans démon, 4 connexions par jour, agenda » — sinon la
relecture Jeedom le verra comme un doublon.

Trois pistes, non exclusives :

1. **Assumer la niche** : rester « Pronote léger », ne pas courir après le
   panneau et l'iCal, soigner l'agenda et la robustesse. Mettre à jour la
   description du plugin et de `info.json` en ce sens.
2. **Contribuer à ProJote** : proposer à Aldarande la synchro à heures fixes,
   le gel après suspension et la pause vacances — ce sont des idées
   transposables, et ça profite à plus de monde.
3. **Emprunter à ProJote** ce qui manque le plus ici et coûte peu : l'export
   iCal (une route PHP), la période en cours et les vacances telles que
   publiées par Pronote (déjà dans `listeJoursFeries`), le gestionnaire de
   Python 3.9 pour les Raspberry sous bullseye.

## Où en est-on (1.1.0-beta.1)

| Manque relevé | État |
|---|---|
| Panneau desktop multi-élèves | ✅ `desktop/php/panel.php`, deux semaines de cours, devoirs, matières, vie scolaire, messagerie, cantine |
| Export iCalendar | ✅ cours + devoirs + vacances, VEVENT ou VTODO, clé du plugin |
| Période en cours, bornes de l'année | ✅ commandes, barre d'avancement |
| Vacances publiées par Pronote | ✅ commandes + pause de synchro sur ce calendrier (source auto/établissement/zone) |
| Statistiques / matières en baisse | ✅ « Matières en baisse » + événement ; courbes : historisation native Jeedom des moyennes (générale, classe, par matière) |
| Messagerie, notifications | ✅ objets, expéditeurs, non lus ; informations et sondages |
| Allergènes / labels cantine | ✅ labels alimentaires, menus de la semaine |
| Photo de profil | ✅ option, off par défaut, servie par AJAX authentifié |
| Python 3.9 (Debian 11) | ✅ CI sur 3.9 / 3.11 / 3.13 |
| CI, tests unitaires Python | ✅ workflow GitHub, `pytest` |
| Audit de sécurité | ✅ `SECURITY.md` + durcissements (masquage des secrets, fichiers 0600, `.htaccess`, validations) |
| Jeton de secours | ✅ autrement : le jeton renouvelé est renvoyé et sauvegardé même si la collecte échoue |
| Webhooks | ✗ volontairement : les scénarios Jeedom font ce travail |
| Rang dans la classe | ✗ pronotepy ne l'expose pas (ni ProJote) |

Ce que Pronote fait et que ProJote ne fait pas du tout : **briefings en
français pour la voix** (soir, matin, bilan hebdo, dernier événement), **heure
de réveil et faits de demain** (sport, contrôle, pas de cours), **écriture dans
Pronote** (cocher un devoir fait), **tendance de la moyenne** et courbe,
**mode discret**.

Ce qui reste propre à Pronote et absent de ProJote : aucun démon, 4 connexions
par jour, gel après suspension d'IP, pause vacances, widget agenda, décodage du
QR côté serveur, secrets masqués côté navigateur, lecteurs inter-plugins.
