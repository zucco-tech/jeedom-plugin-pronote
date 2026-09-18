#!/usr/bin/env python3
"""Récupération des données Pronote pour le plugin Jeedom.

Contrat : lit une requête JSON (--request fichier), écrit une réponse JSON sur
stdout. Les diagnostics vont sur stderr, que le PHP redirige dans le log Jeedom.

Réponse :
  {"ok": true,  "credentials": {...}, "data": {...}, "warnings": [...]}
  {"ok": false, "code": "auth|deps|config|network|script", "error": "..."}

Le code est volontairement défensif : l'API de pronotepy bouge d'une version à
l'autre, et un attribut manquant sur un bloc ne doit pas faire perdre les autres.
"""

import argparse
import datetime
import json
import sys
import traceback

WARNINGS = []


def warn(msg):
    WARNINGS.append(str(msg))
    print("WARN  {}".format(msg), file=sys.stderr)


def fail(code, message):
    json.dump({"ok": False, "code": code, "error": str(message), "warnings": WARNINGS},
              sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")
    sys.exit(0)


def safe(label, fn, default=None):
    """Exécute fn ; en cas d'échec, journalise et rend default."""
    try:
        return fn()
    except Exception as exc:
        warn("{} : {}".format(label, exc))
        print(traceback.format_exc(), file=sys.stderr)
        return default


def num(value, digits=2):
    """Convertit une note Pronote ('14,50', '15.5', Decimal) en float."""
    if value is None:
        return None
    if isinstance(value, (int, float)):
        return round(float(value), digits)
    text = str(value).strip().replace(",", ".")
    if text in ("", "Abs", "Disp", "NE", "N.Not", "-"):
        return None
    try:
        return round(float(text), digits)
    except ValueError:
        return None


def hhmm(dt):
    return dt.strftime("%Hh%M") if dt else ""


def hours_to_float(value):
    """Pronote rend les heures manquées en texte : '2h00', '1h30', parfois '2'."""
    if value is None:
        return 0.0
    if isinstance(value, (int, float)):
        return float(value)
    text = str(value).strip().lower().replace(",", ".")
    if not text:
        return 0.0
    if "h" in text:
        head, _, tail = text.partition("h")
        try:
            hours = float(head) if head else 0.0
        except ValueError:
            return 0.0
        minutes = 0.0
        tail = tail.strip()
        if tail:
            try:
                minutes = float(tail)
            except ValueError:
                minutes = 0.0
        return round(hours + minutes / 60.0, 2)
    try:
        return float(text)
    except ValueError:
        return 0.0


# --------------------------------------------------------------------------
# Connexion
# --------------------------------------------------------------------------

def connect(req):
    try:
        import pronotepy
    except ImportError as exc:
        fail("deps", "pronotepy introuvable dans le venv : {}".format(exc))

    account = req.get("account", "eleve")
    ClientClass = pronotepy.ParentClient if account == "parent" else pronotepy.Client

    creds = req.get("credentials") or None
    mode = req.get("mode", "qr")

    # PIN 2FA du compte : absent de export_credentials(), il doit donc être
    # fourni à CHAQUE connexion, pas seulement à l'enrôlement.
    account_pin = req.get("account_pin") or None

    # Nom d'appareil : Pronote peut exiger l'enregistrement de l'appareil
    # (« A device identifier is required for this account »). pronotepy le passe
    # à _do_2fa() comme identifiant. Lui aussi est absent du jeton exporté : il
    # faut le renvoyer à chaque connexion.
    device_name = req.get("device_name") or "Jeedom"

    # 1) Jeton déjà enregistré : c'est le chemin normal, à chaque synchronisation.
    if creds:
        try:
            kw = dict(creds)
            if account_pin:
                kw["account_pin"] = account_pin
            kw["device_name"] = device_name
            client = ClientClass.token_login(**kw)
            if getattr(client, "logged_in", False):
                return client, pronotepy
            warn("token_login n'a pas ouvert de session, tentative de repli")
        except Exception as exc:
            if "suspended" in str(exc).lower():
                fail("suspended", "Pronote a suspendu temporairement cette adresse IP : trop de "
                                  "connexions. Le plugin espace ses tentatives ; ne pas relancer à la main.")
            warn("token_login a échoué : {}".format(exc))
            print(traceback.format_exc(), file=sys.stderr)

    # 2) Enrôlement par QR Code (première connexion, ou jeton perdu).
    if mode == "qr":
        qr = req.get("qr_json")
        pin = str(req.get("pin", ""))
        uuid = req.get("uuid") or "jeedom-pronote"
        if not qr:
            fail("auth", "Aucun jeton enregistré : importer un QR Code Pronote "
                         "(Mon compte > Autoriser un accès mobile)")
        if not pin.isdigit() or len(pin) != 4:
            fail("config", "Le code à 4 chiffres est manquant ou invalide : c'est celui choisi dans "
                           "Pronote au moment de générer CE QR Code. Rien n'a été envoyé à Pronote.")
        if not isinstance(qr, dict) or "jeton" not in qr:
            fail("config", "Le contenu du QR Code ne ressemble pas à un QR Pronote "
                           "(objet JSON contenant 'jeton' attendu, reçu : {})".format(
                               ", ".join(sorted(qr.keys()))[:120] if isinstance(qr, dict) else type(qr).__name__))
        try:
            client = ClientClass.qrcode_login(
                qr, pin, uuid, account_pin=account_pin, device_name=device_name)
        except Exception as exc:
            message = str(exc)
            if "suspended" in message.lower():
                fail("suspended", "Pronote a suspendu temporairement cette adresse IP : trop de "
                                  "connexions en peu de temps. Chaque nouvelle tentative prolonge "
                                  "la suspension. Attendre au moins 30 minutes, puis enrôler UNE fois "
                                  "avec un QR Code neuf.")
            if message.strip("'\"") == "dataSec" or "dataSec" in message:
                message = ("réponse inattendue de Pronote (pas de bloc de données). Presque toujours : code "
                           "à 4 chiffres faux ou vide, ou QR Code périmé/déjà utilisé. Générer un nouveau QR Code, "
                           "noter le code choisi, déposer la nouvelle image, saisir le code, enrôler une fois.")
            elif "Accès refusé" in message or "error from pronote: 3" in message.lower() or "acces refuse" in message.lower():
                message = ("Pronote a refusé ce QR Code (code 3, Accès refusé). Causes habituelles : le code "
                           "à 4 chiffres n'est pas celui choisi pour CE QR Code, ou le QR Code est expiré "
                           "(10 minutes) ou déjà utilisé. Générer un nouveau QR Code dans Pronote, noter le code "
                           "choisi, déposer la nouvelle image, puis enrôler une fois.")
            elif "device identifier" in message:
                message = ("Pronote exige l'enregistrement de l'appareil et a "
                           "refusé l'identifiant fourni ({}). Renseigner un « nom "
                           "d'appareil » dans la configuration de l'élève.".format(device_name))
            elif "Invalid PIN" in message:
                message = ("Code PIN refusé. Vérifier le code choisi lors de la "
                           "génération du QR Code, ou le PIN 2FA du compte.")
            fail("auth", "Connexion par QR Code refusée : {}".format(message))
        if not getattr(client, "logged_in", False):
            fail("auth", "Connexion par QR Code refusée (QR ou code PIN expiré)")
        return client, pronotepy

    # 3) ENT / identifiants directs.
    url = req.get("url", "").strip()
    if not url:
        fail("config", "URL de l'espace élève manquante")
    username = req.get("username", "")
    password = req.get("password", "")
    if not username or not password:
        fail("config", "Identifiant ou mot de passe manquant")

    kwargs = {}
    if mode == "ent":
        ent_name = req.get("ent", "")
        try:
            import pronotepy.ent as ent_module  # pronotepy.ent n'est pas exposé par le paquet
        except ImportError as exc:
            fail("deps", "module pronotepy.ent indisponible : {}".format(exc))
        ent_fn = getattr(ent_module, ent_name, None) if ent_name else None
        if ent_fn is None:
            available = [n for n in dir(ent_module) if not n.startswith("_")]
            fail("config", "ENT inconnu de pronotepy : '{}'. Valeurs possibles : {}".format(
                ent_name, ", ".join(sorted(available)[:15]) + "…"))
        kwargs["ent"] = ent_fn

    try:
        client = ClientClass(url, username=username, password=password, **kwargs)
    except Exception as exc:
        fail("auth", "Connexion refusée : {}".format(exc))
    if not getattr(client, "logged_in", False):
        fail("auth", "Connexion refusée (identifiants ou ENT)")
    return client, pronotepy


def select_child(client, req):
    """Compte Parents avec plusieurs enfants : choisir celui de l'équipement."""
    wanted = (req.get("child_name") or "").strip()
    children = getattr(client, "children", None)
    if not children:
        return
    names = [str(getattr(c, "name", "")) for c in children]
    if not wanted:
        if len(children) > 1:
            warn("plusieurs enfants sur ce compte ({}) : le premier est utilisé. "
                 "Renseigner « Enfant » dans l'équipement.".format(", ".join(names)))
        return
    for c in children:
        if wanted.lower() in str(getattr(c, "name", "")).lower():
            try:
                client.set_child(c)
            except Exception as exc:
                fail("config", "Impossible de sélectionner l'enfant « {} » : {}".format(wanted, exc))
            return
    fail("config", "Aucun enfant nommé « {} » sur ce compte. Enfants trouvés : {}".format(
        wanted, ", ".join(names)))


def export_credentials(client):
    fn = getattr(client, "export_credentials", None)
    if fn is None:
        warn("export_credentials absent de cette version de pronotepy : "
             "le jeton ne sera pas conservé")
        return None
    return safe("export_credentials", fn, None)


# --------------------------------------------------------------------------
# Extraction des données
# --------------------------------------------------------------------------

def student_meta(client):
    """Nom, classe, établissement — sur un compte Parents, c'est l'enfant
    sélectionné qui porte la classe, pas le parent."""
    who = getattr(client, "_selected_child", None) or safe("info", lambda: client.info)
    meta = {}
    for key, attr in (("name", "name"), ("class_name", "class_name"), ("establishment", "establishment")):
        val = safe("meta." + attr, lambda a=attr: getattr(who, a, None))
        if val is None and who is not None and attr != "name":
            val = safe("meta.info." + attr, lambda a=attr: getattr(client.info, a, None))
        if val:
            meta[key] = str(val).strip()
    return meta


def collect(client, req, data):
    """Remplit `data` bloc par bloc. Le dictionnaire est passé par l'appelant :
    si un bloc lève une exception inattendue, ce qui a déjà été collecté est
    conservé et renvoyé avec le jeton (voir main)."""
    blocks = set(req.get("data") or [])
    now = datetime.datetime.now()
    data["last_sync"] = now.strftime("%d/%m/%Y %H:%M")
    data["_meta"] = student_meta(client)

    period = safe("current_period", lambda: client.current_period)

    # Période en cours, bornes de l'année, vacances publiées par l'établissement :
    # tout est déjà dans la réponse de connexion, aucune requête supplémentaire.
    safe("periode", lambda: collect_period(client, period, data, now))

    if "notes" in blocks and period is not None:
        collect_notes(client, period, data, req)

    if "devoirs" in blocks:
        collect_homework(client, data, req, now)

    if "edt" in blocks:
        collect_timetable(client, data, now)

    if "absences" in blocks and period is not None:
        collect_absences(period, data, now)

    if "punitions" in blocks and period is not None:
        data["punishments"] = safe("punishments", lambda: len(period.punishments), 0)

    if "vie" in blocks:
        collect_messages(client, data)

    if "cantine" in blocks:
        collect_menus(client, data, now)

    if "competences" in blocks and period is not None:
        data["skills_html"] = safe("competences", lambda: collect_skills(period), "") or ""

    if req.get("photo_path"):
        safe("photo", lambda: collect_photo(client, req["photo_path"], data))

    return data


# --------------------------------------------------------------------------
# Période, année scolaire, vacances publiées par l'établissement
# --------------------------------------------------------------------------

def general_params(client):
    """Bloc « General » des paramètres reçus à la connexion (FonctionParametres)."""
    try:
        opts = getattr(client, "func_options", None) or {}
        return ((opts.get("dataSec") or {}).get("data") or {}).get("General") or {}
    except Exception:
        return {}


def pronote_date(value):
    """Une date Pronote arrive sous la forme {"_T": 7, "V": "jj/mm/aaaa hh:mm:ss"}
    ou directement en chaîne. Rend un datetime.date, ou None."""
    if isinstance(value, dict):
        value = value.get("V")
    if not value:
        return None
    text = str(value).strip()
    for fmt in ("%d/%m/%Y %H:%M:%S", "%d/%m/%Y %H:%M", "%d/%m/%Y"):
        try:
            return datetime.datetime.strptime(text, fmt).date()
        except ValueError:
            continue
    return None


def collect_period(client, period, data, now):
    today = now.date()
    if period is not None:
        name = getattr(period, "name", "") or ""
        start = getattr(period, "start", None)
        end = getattr(period, "end", None)
        data["period_name"] = str(name)
        if start and end:
            data["period_start"] = start.strftime("%d/%m/%Y")
            data["period_end"] = end.strftime("%d/%m/%Y")
            total = (end.date() - start.date()).days
            done = (today - start.date()).days
            data["period_progress"] = max(0, min(100, int(round(100.0 * done / total)))) if total > 0 else 0
            data["period_days_left"] = max(0, (end.date() - today).days)

    general = general_params(client)
    year_start = pronote_date(general.get("PremiereDate"))
    year_end = pronote_date(general.get("DerniereDate"))
    if year_start:
        data["school_year_start"] = year_start.strftime("%d/%m/%Y")
    if year_end:
        data["school_year_end"] = year_end.strftime("%d/%m/%Y")

    # Vacances et jours fériés : Pronote les publie ensemble dans listeJoursFeries.
    raw = general.get("listeJoursFeries")
    if isinstance(raw, dict):
        raw = raw.get("V")
    holidays = []
    for entry in raw or []:
        if not isinstance(entry, dict):
            continue
        end = pronote_date(entry.get("dateFin")) or pronote_date(entry.get("date"))
        start = pronote_date(entry.get("dateDebut")) or end
        if not end or not start:
            continue
        if end < start:
            start, end = end, start
        holidays.append({
            "name": str(entry.get("L", "") or "").strip(),
            "start": start.isoformat(),
            "end": end.isoformat(),
            # 3 jours et plus : des vacances ; en dessous, un jour férié ou un pont.
            "kind": "vacances" if (end - start).days >= 2 else "ferie",
        })
    holidays.sort(key=lambda h: h["start"])
    if holidays:
        data["_holidays"] = holidays

    upcoming = [h for h in holidays
                if h["kind"] == "vacances" and datetime.date.fromisoformat(h["end"]) >= today]
    if upcoming:
        nxt = upcoming[0]
        start = datetime.date.fromisoformat(nxt["start"])
        end = datetime.date.fromisoformat(nxt["end"])
        data["next_holiday_name"] = nxt["name"]
        data["next_holiday_start"] = start.strftime("%d/%m/%Y")
        data["next_holiday_end"] = end.strftime("%d/%m/%Y")
        data["days_to_holiday"] = max(0, (start - today).days)
    else:
        data["next_holiday_name"] = ""
        data["next_holiday_start"] = ""
        data["next_holiday_end"] = ""


def collect_notes(client, period, data, req):
    grades = safe("grades", lambda: list(period.grades), []) or []

    # Sans aucune note, Pronote rend 0 : l'afficher serait faux (« 0/20 » en
    # début d'année). Jeedom transforme une valeur vide en 0 sur une commande
    # numérique, donc on n'écrit RIEN pour la moyenne ; last_grade vide signale
    # au widget qu'il n'y a pas encore de note.
    if not grades:
        data["last_grade"] = ""
        data["new_grades"] = 0
    else:
        overall = safe("overall_average", lambda: num(period.overall_average))
        data["avg_general"] = overall if overall is not None else ""
        class_overall = safe("class_overall_average", lambda: num(period.class_overall_average))
        data["avg_class"] = class_overall if class_overall is not None else ""

    # Pas de rang : pronotepy 2.15 n'expose aucun classement, et l'inventer à
    # partir des moyennes donnerait un chiffre faux.

    if grades:
        def sort_key(g):
            return getattr(g, "date", None) or datetime.date.min
        latest = sorted(grades, key=sort_key)[-1]
        subject = safe("subject", lambda: latest.subject.name, "") or ""
        value = num(getattr(latest, "grade", None))
        out_of = num(getattr(latest, "out_of", None))
        if value is not None and out_of:
            data["last_grade"] = "{} : {:g}/{:g}".format(subject, value, out_of)

        since = datetime.date.today() - datetime.timedelta(days=1)
        data["new_grades"] = len([g for g in grades
                                  if (getattr(g, "date", None) or datetime.date.min) >= since])

    # Compteur de notes : le PHP le compare à la synchro précédente pour lever
    # l'événement « nouvelle note » (commande binaire pour les scénarios).
    data["_grades_count"] = len(grades)

    # Dernières notes, du plus récent au plus ancien : liste structurée pour le
    # panneau et l'export, et liste HTML pour le widget.
    recent = sorted(grades, key=lambda g: getattr(g, "date", None) or datetime.date.min, reverse=True)[:15]
    grade_rows = []
    grade_items = []
    for g in recent:
        subject = safe("g.subject", lambda x=g: x.subject.name, "") or ""
        value = num(getattr(g, "grade", None))
        out_of = num(getattr(g, "out_of", None))
        gdate = getattr(g, "date", None)
        if value is None or not out_of:
            continue
        item = {"date": gdate.isoformat() if gdate else "", "subject": subject,
                "value": value, "out_of": out_of,
                "class_avg": num(getattr(g, "average", None)),
                "coef": (getattr(g, "coefficient", "") or "").strip() if isinstance(getattr(g, "coefficient", ""), str) else "",
                "comment": (getattr(g, "comment", "") or "")[:120]}
        grade_items.append(item)
        # Sur 20, pour comparer d'un coup d'œil des barèmes différents.
        on20 = round(value * 20.0 / out_of, 1)
        grade_rows.append(
            '<li data-date="{}" data-on20="{}" style="--c:hsl({},55%,58%)"><b>{}</b>'
            '<span class="v">{:g}/{:g}</span><span class="i">{}{}</span></li>'.format(
                item["date"], on20, subject_hue(subject), escape(subject), value, out_of,
                gdate.strftime("%d/%m") if gdate else "",
                (" · classe {:g}".format(item["class_avg"]) if item["class_avg"] is not None else "")))
    if grade_items:
        data["_grades"] = grade_items
    data["grades_html"] = '<ul class="pronote-grades">' + "".join(grade_rows) + "</ul>" if grade_rows else ""

    # Moyennes par matière : toujours collectées (une requête) ; le PHP ne crée
    # les commandes par matière que si l'option est active, mais s'en sert dans
    # tous les cas pour détecter les matières en baisse et nourrir le panneau.
    if True:
        subjects = []
        latest_by_subject = {}
        for g in grades:
            sname = safe("g.subject", lambda x=g: x.subject.name, None)
            gdate = getattr(g, "date", None)
            if not sname:
                continue
            cur = latest_by_subject.get(sname)
            if cur is None or (gdate and cur[0] and gdate > cur[0]) or (gdate and not cur[0]):
                latest_by_subject[sname] = (gdate, g)
        averages = safe("averages", lambda: list(period.averages), []) or []
        for avg in averages:
            name = safe("subject.name", lambda a=avg: a.subject.name, None)
            value = num(getattr(avg, "student", None))
            if not name or value is None:
                continue
            slug = "".join(ch if ch.isalnum() else "_" for ch in name.lower())[:30]
            entry = {"logicalId": "avg_subject_" + slug, "slug": slug, "name": name, "value": value,
                     "class_value": num(getattr(avg, "class_average", None))}
            last = latest_by_subject.get(name)
            if last:
                gdate, g = last
                v = num(getattr(g, "grade", None)); out_of = num(getattr(g, "out_of", None))
                if v is not None and out_of:
                    entry["last_grade"] = "{:g}/{:g}".format(v, out_of) + (gdate.strftime(" · %d/%m") if gdate else "")
            subjects.append(entry)
        if subjects:
            data["_subjects"] = subjects


def collect_homework(client, data, req, now):
    days = int(req.get("homework_days", 7) or 7)
    today = now.date()
    # Une seule requête sur 14 jours au moins : la liste structurée (panneau,
    # export iCal) voit plus loin que le widget, borné à `homework_days`.
    horizon = max(days, 14)
    all_homework = safe("homework", lambda: list(
        client.homework(today, today + datetime.timedelta(days=horizon))), []) or []
    all_homework.sort(key=lambda h: getattr(h, "date", None) or datetime.date.max)
    items = []
    for h in all_homework:
        hdate = getattr(h, "date", None)
        items.append({
            "date": hdate.isoformat() if hdate else "",
            "subject": safe("hw.subject", lambda x=h: x.subject.name, "") or "",
            "description": (getattr(h, "description", "") or "").replace("\n", " ").strip()[:400],
            "done": bool(getattr(h, "done", False)),
        })
    if items:
        data["_homework"] = items
    homework = [h for h in all_homework
                if (getattr(h, "date", None) or today) <= today + datetime.timedelta(days=days)]

    pending = [h for h in homework if not getattr(h, "done", False)] \
        if req.get("skip_done") else homework

    data["homework_count"] = len(pending)
    tomorrow = today + datetime.timedelta(days=1)
    data["homework_tomorrow"] = len([h for h in pending
                                     if getattr(h, "date", None) == tomorrow])
    # Binaire pour les scénarios : au moins un devoir pour demain non coché « fait ».
    data["homework_tomorrow_pending"] = 1 if any(
        getattr(h, "date", None) == tomorrow and not getattr(h, "done", False) for h in homework) else 0

    rows = []
    for h in pending[:20]:
        subject = safe("hw.subject", lambda x=h: x.subject.name, "") or ""
        date = getattr(h, "date", None)
        label = jour_relatif(date, today) if date else ""
        urgent = ' class="urgent"' if (date and (date - today).days <= 1) else ""
        desc = (getattr(h, "description", "") or "").replace("\n", " ").strip()
        rows.append('<li{} data-date="{}"><b>{}</b><span class="d">{}</span><span class="t">{}</span></li>'.format(
            urgent, date.isoformat() if date else "", escape(subject), escape(label), escape(desc[:200])))
    data["homework_html"] = '<ul class="pronote-hw">' + "".join(rows) + "</ul>" if rows else ""


JOURS = ["lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi", "dimanche"]
MOIS = ["", "janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.", "août", "sept.", "oct.", "nov.", "déc."]


def jour_label(d):
    return "{} {} {}".format(JOURS[d.weekday()], d.day, MOIS[d.month])


def jour_relatif(d, today):
    delta = (d - today).days
    if delta == 0:
        return "aujourd'hui"
    if delta == 1:
        return "demain"
    if 1 < delta < 7:
        return JOURS[d.weekday()]
    return "{:02d}/{:02d}".format(d.day, d.month)


def subject_hue(name):
    """Teinte stable par matière, pour la barre de couleur (comme Pronote)."""
    h = 0
    for ch in name.lower():
        h = (h * 31 + ord(ch)) % 360
    return h


def lessons_html(lessons, attrs=""):
    """Liste HTML valide, balisée pour le widget : heures, couleur, salle, prof,
    annulation. Une liste vide reste rendue (avec ses attributs) pour que le
    widget puisse afficher « Pas de cours » pour ce jour."""
    rows = []
    for l in lessons:
        subject = safe("lesson.subject", lambda x=l: x.subject.name, "") or ""
        room = getattr(l, "classroom", "") or ""
        teacher = getattr(l, "teacher_name", "") or ""
        status = getattr(l, "status", "") or ""
        cancelled = bool(getattr(l, "canceled", False))
        end = getattr(l, "end", None)
        rows.append(
            '<li data-start="{}" data-end="{}" style="--c:hsl({},55%,58%)"{}>'
            '<span class="h"><span>{}</span><span>{}</span></span>'
            '<span class="s"><b>{}</b><span class="i">{}</span></span></li>'.format(
                l.start.strftime("%H%M"), end.strftime("%H%M") if end else "",
                subject_hue(subject), ' class="cancelled"' if cancelled else "",
                hhmm(l.start), hhmm(end),
                escape(subject),
                escape(" · ".join(p for p in (teacher, room, status if cancelled else "") if p))))
    if not rows and not attrs:
        return ""
    return '<ul class="pronote-edt"{}>'.format(attrs) + "".join(rows) + "</ul>"


def collect_timetable(client, data, now):
    today = now.date()
    horizon = 7
    # Deux semaines en un appel (pronotepy enchaîne les semaines dans la même
    # session) : le widget n'affiche que 7 jours, le panneau et l'export iCal
    # voient 14. pronotepy convertit la date de fin en « ce jour à 00:00 »,
    # donc today+14 inclut exactement J..J+13. Surtout ne jamais passer
    # lessons(d, d) : ça ne rend rien.
    lessons = safe("lessons", lambda: list(
        client.lessons(today, today + datetime.timedelta(days=14))), []) or []
    lessons = [l for l in lessons if getattr(l, "start", None)]
    lessons.sort(key=lambda l: l.start)

    items = []
    for l in lessons:
        end = getattr(l, "end", None)
        subject = safe("lesson.subject", lambda x=l: x.subject.name, "") or ""
        items.append({
            "date": l.start.date().isoformat(),
            "start": l.start.strftime("%H:%M"),
            "end": end.strftime("%H:%M") if end else "",
            "subject": subject,
            "room": getattr(l, "classroom", "") or "",
            "teacher": getattr(l, "teacher_name", "") or "",
            "status": getattr(l, "status", "") or "",
            "cancelled": bool(getattr(l, "canceled", False)),
            "test": bool(getattr(l, "test", False)),
        })
    if items:
        data["_lessons"] = items

    by_day = {}
    for l in lessons:
        by_day.setdefault(l.start.date(), []).append(l)

    today_lessons = by_day.get(today, [])
    tomorrow = today + datetime.timedelta(days=1)
    tomorrow_lessons = by_day.get(tomorrow, [])

    data["course_cancelled"] = 1 if any(getattr(l, "canceled", False) for l in today_lessons) else 0
    data["course_cancelled_tomorrow"] = 1 if any(getattr(l, "canceled", False) for l in tomorrow_lessons) else 0
    data["timetable_html"] = lessons_html(today_lessons)
    data["timetable_tomorrow_html"] = lessons_html(tomorrow_lessons)

    # Semaine glissante, un <ul> par jour, pour la navigation jour par jour.
    days = []
    for i in range(horizon):
        d = today + datetime.timedelta(days=i)
        attrs = ' data-date="{}" data-label="{}" data-rel="{}"{}'.format(
            d.isoformat(), jour_label(d), jour_relatif(d, today),
            ' data-today="1"' if i == 0 else "")
        days.append(lessons_html(by_day.get(d, []), attrs))
    data["timetable_week_html"] = "".join(days)

    def describe(l):
        subject = safe("lesson.subject", lambda: l.subject.name, "") or ""
        parts = [p for p in (getattr(l, "classroom", "") or "", getattr(l, "teacher_name", "") or "") if p]
        return "{} — {}".format(subject, " — ".join(parts)) if parts else subject

    upcoming = [l for l in lessons if l.start > now and not getattr(l, "canceled", False)]
    if upcoming:
        nxt = upcoming[0]
        if nxt.start.date() == today:
            data["next_course"] = describe(nxt)
            data["next_course_start"] = hhmm(nxt.start)
        else:
            rel = jour_relatif(nxt.start.date(), today)
            data["next_course"] = rel.capitalize() + " : " + describe(nxt)
            data["next_course_start"] = rel + " " + hhmm(nxt.start)
    else:
        data["next_course"] = "Aucun cours à venir cette semaine"
        data["next_course_start"] = ""


def menu_text(menu):
    """« Carottes râpées, Poulet rôti (bio), Yaourt » : plats du repas, avec les
    labels alimentaires de Pronote entre parenthèses (bio, végétarien, AOP…)."""
    parts = []
    for attr in ("first_meal", "main_meal", "side_meal", "cheese", "dessert", "other_meal"):
        for dish in getattr(menu, attr, None) or []:
            name = getattr(dish, "name", None)
            if not name:
                continue
            labels = [str(getattr(lb, "name", "")) for lb in (getattr(dish, "labels", None) or [])
                      if getattr(lb, "name", None)]
            parts.append(str(name) + (" ({})".format(", ".join(labels)) if labels else ""))
    return ", ".join(parts[:12])


def collect_menus(client, data, now):
    today = now.date()
    menus = safe("menus", lambda: list(client.menus(today, today + datetime.timedelta(days=7))), []) or []
    by_day = {}
    for m in menus:
        d = getattr(m, "date", None)
        if d is None:
            continue
        # Le déjeuner d'abord ; le dîner (internat) seulement s'il n'y a que lui.
        if d not in by_day or (getattr(m, "is_lunch", False) and not getattr(by_day[d], "is_lunch", False)):
            by_day[d] = m
    data["menu_today"] = menu_text(by_day[today]) if today in by_day else ""
    tomorrow = today + datetime.timedelta(days=1)
    data["menu_tomorrow"] = menu_text(by_day[tomorrow]) if tomorrow in by_day else ""
    rows = []
    for d in sorted(by_day):
        text = menu_text(by_day[d])
        if text:
            rows.append('<li data-date="{}"><b>{}</b><span class="t">{}</span></li>'.format(
                d.isoformat(), escape(jour_label(d)), escape(text)))
    data["menu_week_html"] = '<ul class="pronote-menu">' + "".join(rows) + "</ul>" if rows else ""


def collect_absences(period, data, now):
    absences = safe("absences", lambda: list(period.absences), []) or []
    delays = safe("delays", lambda: list(period.delays), []) or []
    data["absences"] = round(sum(hours_to_float(getattr(a, "hours", None)) for a in absences), 2)
    data["delays"] = len(delays)
    data["absences_unjustified"] = len([a for a in absences if not getattr(a, "justified", False)])

    rows = []
    for a in absences:
        start = getattr(a, "from_date", None)
        rows.append((start or datetime.datetime.min,
                     '<li class="abs{}"><b>Absence</b><span class="d">{}</span><span class="t">{}{}</span></li>'.format(
                         "" if getattr(a, "justified", False) else " nj",
                         escape(start.strftime("%d/%m %Hh%M") if start else ""),
                         escape(str(getattr(a, "hours", "") or "")),
                         escape((" · " + ", ".join(getattr(a, "reasons", None) or [])) if getattr(a, "reasons", None) else "")
                         + ("" if getattr(a, "justified", False) else " · non justifiée"))))
    for d in delays:
        start = getattr(d, "date", None)
        rows.append((start or datetime.datetime.min,
                     '<li class="delay{}"><b>Retard</b><span class="d">{}</span><span class="t">{} min{}</span></li>'.format(
                         "" if getattr(d, "justified", False) else " nj",
                         escape(start.strftime("%d/%m %Hh%M") if start else ""),
                         int(getattr(d, "minutes", 0) or 0),
                         "" if getattr(d, "justified", False) else " · non justifié")))
    rows.sort(key=lambda r: r[0], reverse=True)
    data["absences_html"] = '<ul class="pronote-abs">' + "".join(r[1] for r in rows[:10]) + "</ul>" if rows else ""


def collect_messages(client, data):
    """Discussions (messagerie) et informations/sondages. Ni l'une ni l'autre ne
    charge le contenu des messages : objet, expéditeur, état seulement."""
    discussions = safe("discussions", lambda: list(client.discussions()), []) or []
    data["new_messages"] = len([d for d in discussions if getattr(d, "unread", 0)])
    rows = []
    for d in discussions[:8]:
        rows.append('<li{}><b>{}</b><span class="i">{}</span></li>'.format(
            ' class="unread"' if getattr(d, "unread", 0) else "",
            escape(getattr(d, "subject", "") or "(sans objet)"),
            escape(getattr(d, "creator", None) or "moi")))
    data["messages_html"] = '<ul class="pronote-msg">' + "".join(rows) + "</ul>" if rows else ""

    infos = safe("informations", lambda: list(client.information_and_surveys()), []) or []
    infos.sort(key=lambda i: getattr(i, "creation_date", None) or datetime.datetime.min, reverse=True)
    data["new_infos"] = len([i for i in infos if not getattr(i, "read", True)])
    rows = []
    for i in infos[:8]:
        when = getattr(i, "creation_date", None)
        rows.append('<li{}><b>{}</b><span class="i">{}{}{}</span></li>'.format(
            ' class="unread"' if not getattr(i, "read", True) else "",
            escape(getattr(i, "title", None) or "(sans titre)"),
            escape(getattr(i, "author", "") or ""),
            escape(" · " + when.strftime("%d/%m") if when else ""),
            " · sondage" if getattr(i, "survey", False) else ""))
    data["infos_html"] = '<ul class="pronote-info">' + "".join(rows) + "</ul>" if rows else ""


def collect_photo(client, path, data):
    """Photo de profil, si l'établissement la publie. Écrite dans `path` (dossier
    protégé du plugin), remplacée à chaque synchronisation, jamais journalisée."""
    who = getattr(client, "_selected_child", None) or client.info
    picture = getattr(who, "profile_picture", None)
    if not picture:
        data["_photo"] = False
        return
    tmp = path + ".part"
    picture.save(tmp)
    with open(tmp, "rb") as fh:
        head = fh.read(4)
    # JPEG ou PNG uniquement : on ne sert jamais un fichier dont on ignore la nature.
    if head[:3] == b"\xff\xd8\xff" or head == b"\x89PNG":
        import os
        os.replace(tmp, path)
        os.chmod(path, 0o600)
        data["_photo"] = True
    else:
        import os
        os.unlink(tmp)
        data["_photo"] = False
        warn("photo de profil ignorée : format inattendu")


def collect_skills(period):
    evaluations = safe("evaluations", lambda: list(period.evaluations), []) or []
    rows = []
    for ev in evaluations[:20]:
        subject = safe("ev.subject", lambda e=ev: e.subject.name, "") or ""
        name = getattr(ev, "name", "") or ""
        rows.append("<li><b>{}</b> {}</li>".format(escape(subject), escape(name)))
    return "<ul>" + "".join(rows) + "</ul>" if rows else ""


def escape(text):
    return (str(text).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;"))


# --------------------------------------------------------------------------
# Décodage de l'image du QR Code
# --------------------------------------------------------------------------

def decode_qr(path):
    """Lit une image de QR Code Pronote et rend son contenu JSON.

    Les captures d'écran et les photos passent mal du premier coup : on retente
    en niveaux de gris puis agrandi avant d'abandonner.
    """
    try:
        from PIL import Image, ImageOps
    except ImportError as exc:
        fail("deps", "Pillow introuvable dans le venv : {}".format(exc))
    try:
        import zxingcpp
    except ImportError as exc:
        fail("deps", "zxing-cpp introuvable dans le venv : {}".format(exc))

    try:
        image = Image.open(path)
    except Exception as exc:
        fail("config", "Image illisible : {}".format(exc))

    if image.mode not in ("RGB", "L"):
        image = image.convert("RGB")

    attempts = [
        ("image d'origine", image),
        ("niveaux de gris", ImageOps.grayscale(image)),
        ("contraste augmenté", ImageOps.autocontrast(ImageOps.grayscale(image))),
        ("agrandie x2", image.resize((image.width * 2, image.height * 2))),
    ]

    text = None
    used = ""
    for label, candidate in attempts:
        try:
            results = zxingcpp.read_barcodes(candidate)
        except Exception as exc:
            warn("lecture ({}) : {}".format(label, exc))
            continue
        for res in results or []:
            if getattr(res, "text", ""):
                text = res.text
                used = label
                break
        if text:
            break

    if not text:
        fail("config", "Aucun QR Code trouvé dans l'image. Utiliser l'image "
                       "exportée par Pronote plutôt qu'une photo d'écran, ou "
                       "coller directement le contenu JSON.")

    try:
        payload = json.loads(text)
    except ValueError:
        fail("config", "Le QR Code a bien été lu mais son contenu n'est pas du "
                       "JSON — ce n'est probablement pas un QR Code Pronote. "
                       "Contenu : {}".format(text[:120]))

    if not isinstance(payload, dict) or "jeton" not in payload:
        fail("config", "QR Code lu, mais il ne contient pas de jeton Pronote "
                       "(clés trouvées : {}).".format(
                           ", ".join(sorted(payload.keys()))[:120]
                           if isinstance(payload, dict) else type(payload).__name__))

    json.dump({"ok": True, "qr": payload, "read_with": used, "warnings": WARNINGS},
              sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")


# --------------------------------------------------------------------------
# Jeu d'essai : valide toute la chaîne Jeedom sans toucher à Pronote.
# --------------------------------------------------------------------------

class _Fake(object):
    """Objet à attributs libres, pour rejouer les constructeurs HTML sur le jeu d'essai."""
    def __init__(self, **kw):
        for k, v in kw.items():
            setattr(self, k, v)


def selftest_payload():
    """Jeu d'essai daté d'aujourd'hui : il passe par les mêmes constructeurs
    HTML que la vraie collecte, ce qui les teste au passage."""
    now = datetime.datetime.now()
    today = now.date()
    D = datetime.timedelta

    def at(d, h, m):
        return datetime.datetime.combine(d, datetime.time(h, m))

    def lesson(d, h1, m1, h2, m2, subject, teacher, room, cancelled=False, status=""):
        return _Fake(start=at(d, h1, m1), end=at(d, h2, m2), subject=_Fake(name=subject),
                     teacher_name=teacher, classroom=room, canceled=cancelled, status=status, test=False)

    # Une semaine type, lundi→vendredi, posée sur les 14 prochains jours.
    week = {
        0: [(8, 30, 9, 30, "Mathématiques", "M. Dubois", "B12"), (9, 30, 10, 30, "Anglais LV1", "Mme Rivet", "A04"),
            (10, 45, 12, 45, "Français", "Mme Lenoir", "C03"), (14, 0, 16, 0, "Physique-Chimie", "M. Garnier", "Labo 1")],
        1: [(8, 30, 10, 30, "Histoire-Géo", "M. Perrin", "C21"), (10, 45, 11, 45, "Mathématiques", "M. Dubois", "B12"),
            (13, 30, 14, 30, "SVT", "Mme Cohen", "Labo 2"), (14, 30, 16, 30, "EPS", "M. Roux", "Gymnase")],
        2: [(8, 30, 9, 30, "Espagnol LV2", "Mme Ortiz", "A11"), (9, 30, 11, 30, "Français", "Mme Lenoir", "C03")],
        3: [(8, 10, 9, 5, "Histoire-Géo", "M. Perrin", "C21"), (9, 5, 10, 0, "Anglais LV1", "Mme Rivet", "A04"),
            (10, 15, 12, 15, "Mathématiques", "M. Dubois", "B12"), (14, 0, 15, 0, "Arts plastiques", "Mme Blanc", "D02")],
        4: [(8, 30, 10, 30, "Physique-Chimie", "M. Garnier", "Labo 1"), (10, 45, 12, 45, "Technologie", "M. Salah", "T1"),
            (14, 0, 15, 0, "Musique", "M. Faure", "D05")],
    }
    lessons = []
    # Un cours annulé le prochain jour de classe (demain si c'est un jour d'école).
    cancel_day = next(i for i in range(1, 8) if (today + D(days=i)).weekday() < 5)
    for i in range(14):
        d = today + D(days=i)
        for j, (h1, m1, h2, m2, subj, teacher, room) in enumerate(week.get(d.weekday(), [])):
            cancelled = (i == cancel_day and j == 1)
            lessons.append(lesson(d, h1, m1, h2, m2, subj, teacher, room, cancelled, "Prof. absent" if cancelled else ""))
    by_day = {}
    for l in lessons:
        by_day.setdefault(l.start.date(), []).append(l)

    days = []
    for i in range(7):
        d = today + D(days=i)
        attrs = ' data-date="{}" data-label="{}" data-rel="{}"{}'.format(
            d.isoformat(), jour_label(d), jour_relatif(d, today), ' data-today="1"' if i == 0 else "")
        days.append(lessons_html(by_day.get(d, []), attrs))

    def next_school_day(offset):
        d = today + D(days=offset)
        while d.weekday() >= 5:
            d += D(days=1)
        return d

    hw_dates = [next_school_day(1), next_school_day(2), next_school_day(4), next_school_day(8)]
    homework = [
        {"date": hw_dates[0].isoformat(), "subject": "Mathématiques", "description": "Exercices 12 à 15 p. 84", "done": False},
        {"date": hw_dates[0].isoformat(), "subject": "Anglais LV1", "description": "Apprendre le vocabulaire de l'unité 2", "done": True},
        {"date": hw_dates[1].isoformat(), "subject": "Français", "description": "Lire le chapitre 3 et répondre aux questions", "done": False},
        {"date": hw_dates[2].isoformat(), "subject": "Histoire-Géo", "description": "Fiche de révision : la Révolution française", "done": False},
        {"date": hw_dates[3].isoformat(), "subject": "SVT", "description": "Compte rendu de TP", "done": False},
    ]
    hw_rows = []
    for h in homework:
        if h["done"]:
            continue
        d = datetime.date.fromisoformat(h["date"])
        hw_rows.append('<li{} data-date="{}"><b>{}</b><span class="d">{}</span><span class="t">{}</span></li>'.format(
            ' class="urgent"' if (d - today).days <= 1 else "", h["date"], escape(h["subject"]),
            escape(jour_relatif(d, today)), escape(h["description"])))

    grades = [
        {"date": (today - D(days=2)).isoformat(), "subject": "Mathématiques", "value": 16.0, "out_of": 20.0, "class_avg": 12.4, "coef": "2", "comment": "Contrôle chapitre 2"},
        {"date": (today - D(days=4)).isoformat(), "subject": "Français", "value": 12.0, "out_of": 20.0, "class_avg": 12.9, "coef": "1", "comment": "Dictée"},
        {"date": (today - D(days=6)).isoformat(), "subject": "Anglais LV1", "value": 8.5, "out_of": 10.0, "class_avg": 7.2, "coef": "1", "comment": "Oral"},
        {"date": (today - D(days=9)).isoformat(), "subject": "Physique-Chimie", "value": 11.0, "out_of": 20.0, "class_avg": 13.1, "coef": "2", "comment": "TP"},
        {"date": (today - D(days=12)).isoformat(), "subject": "Histoire-Géo", "value": 14.5, "out_of": 20.0, "class_avg": 12.0, "coef": "1", "comment": ""},
    ]
    grade_rows = []
    for g in grades:
        grade_rows.append(
            '<li data-date="{}" data-on20="{}" style="--c:hsl({},55%,58%)"><b>{}</b><span class="v">{:g}/{:g}</span>'
            '<span class="i">{} · classe {:g}</span></li>'.format(
                g["date"], round(g["value"] * 20 / g["out_of"], 1), subject_hue(g["subject"]), escape(g["subject"]),
                g["value"], g["out_of"], datetime.date.fromisoformat(g["date"]).strftime("%d/%m"), g["class_avg"]))

    period_start = datetime.date(today.year if today.month >= 9 else today.year - 1, 9, 1)
    period_end = datetime.date(period_start.year, 12, 18)
    if today > period_end:
        period_start, period_end = datetime.date(period_start.year + 1, 1, 4), datetime.date(period_start.year + 1, 3, 26)
    total = (period_end - period_start).days
    hol_start = today + D(days=23)
    hol_end = hol_start + D(days=15)

    menus = {
        today: "Carottes râpées (bio), Poulet rôti, Purée, Yaourt",
        today + D(days=1): "Salade verte, Lasagnes (végétarien), Fromage, Compote",
        today + D(days=2): "Betteraves, Poisson pané, Riz, Fruit de saison (bio)",
        today + D(days=3): "Taboulé, Sauté de dinde, Haricots verts, Crème dessert",
    }
    menu_rows = "".join('<li data-date="{}"><b>{}</b><span class="t">{}</span></li>'.format(
        d.isoformat(), escape(jour_label(d)), escape(t)) for d, t in sorted(menus.items()) if d.weekday() < 5)

    data = {
        "last_sync": now.strftime("%d/%m/%Y %H:%M"),
        "_meta": {"name": "Léa Martin", "class_name": "4E B", "establishment": "Collège Jean Moulin"},
        "period_name": "Trimestre 1" if period_start.month == 9 else "Trimestre 2",
        "period_start": period_start.strftime("%d/%m/%Y"), "period_end": period_end.strftime("%d/%m/%Y"),
        "period_progress": max(0, min(100, int(round(100.0 * (today - period_start).days / total)))) if total else 0,
        "period_days_left": max(0, (period_end - today).days),
        "school_year_start": period_start.replace(month=9, day=1).strftime("%d/%m/%Y") if period_start.month == 9 else datetime.date(period_start.year - 1, 9, 1).strftime("%d/%m/%Y"),
        "school_year_end": datetime.date(period_start.year + (1 if period_start.month == 9 else 0), 7, 4).strftime("%d/%m/%Y"),
        "_holidays": [
            {"name": "Vacances (jeu d'essai)", "start": hol_start.isoformat(), "end": hol_end.isoformat(), "kind": "vacances"},
            {"name": "Jour férié (jeu d'essai)", "start": (today + D(days=40)).isoformat(), "end": (today + D(days=40)).isoformat(), "kind": "ferie"},
        ],
        "next_holiday_name": "Vacances (jeu d'essai)", "next_holiday_start": hol_start.strftime("%d/%m/%Y"),
        "next_holiday_end": hol_end.strftime("%d/%m/%Y"), "days_to_holiday": 23,

        "avg_general": 14.7, "avg_class": 12.9,
        "last_grade": "Mathématiques : 16/20", "new_grades": 1, "_grades_count": 21,
        "_grades": grades, "grades_html": '<ul class="pronote-grades">' + "".join(grade_rows) + "</ul>",
        "_subjects": [
            {"logicalId": "avg_subject_mathematiques", "slug": "mathematiques", "name": "Mathématiques", "value": 15.2, "class_value": 12.4, "last_grade": "16/20 · " + (today - D(days=2)).strftime("%d/%m")},
            {"logicalId": "avg_subject_francais", "slug": "francais", "name": "Français", "value": 13.8, "class_value": 12.9, "last_grade": "12/20 · " + (today - D(days=4)).strftime("%d/%m")},
            {"logicalId": "avg_subject_anglais_lv1", "slug": "anglais_lv1", "name": "Anglais LV1", "value": 16.5, "class_value": 13.6, "last_grade": "8.5/10 · " + (today - D(days=6)).strftime("%d/%m")},
            {"logicalId": "avg_subject_physique_chimie", "slug": "physique_chimie", "name": "Physique-Chimie", "value": 11.4, "class_value": 13.1, "last_grade": "11/20 · " + (today - D(days=9)).strftime("%d/%m")},
            {"logicalId": "avg_subject_histoire_geo", "slug": "histoire_geo", "name": "Histoire-Géo", "value": 14.0, "class_value": 12.0, "last_grade": "14.5/20 · " + (today - D(days=12)).strftime("%d/%m")},
        ],

        "homework_count": len(hw_rows), "homework_tomorrow": 1 if (hw_dates[0] - today).days == 1 else 0,
        "homework_tomorrow_pending": 1 if (hw_dates[0] - today).days == 1 else 0,
        "_homework": homework,
        "homework_html": '<ul class="pronote-hw">' + "".join(hw_rows) + "</ul>",

        "_lessons": [{"date": l.start.date().isoformat(), "start": l.start.strftime("%H:%M"), "end": l.end.strftime("%H:%M"),
                      "subject": l.subject.name, "room": l.classroom, "teacher": l.teacher_name, "status": l.status,
                      "cancelled": l.canceled, "test": False} for l in lessons],
        "timetable_html": lessons_html(by_day.get(today, [])),
        "timetable_tomorrow_html": lessons_html(by_day.get(today + D(days=1), [])),
        "timetable_week_html": "".join(days),
        "course_cancelled": 0,
        "course_cancelled_tomorrow": 1 if any(l.canceled for l in by_day.get(today + D(days=1), [])) else 0,
        "next_course": "Anglais LV1 — A04 — Mme Rivet", "next_course_start": "09h30",

        "absences": 2, "delays": 1, "absences_unjustified": 1, "punishments": 0,
        "absences_html": '<ul class="pronote-abs"><li class="abs nj"><b>Absence</b><span class="d">{}</span><span class="t">2h00 · non justifiée</span></li>'
                         '<li class="delay"><b>Retard</b><span class="d">{}</span><span class="t">10 min</span></li></ul>'.format(
                             (today - D(days=3)).strftime("%d/%m 08h30"), (today - D(days=8)).strftime("%d/%m 08h35")),
        "new_messages": 1, "new_infos": 1,
        "messages_html": '<ul class="pronote-msg"><li class="unread"><b>Sortie scolaire du 12</b><span class="i">Mme Lenoir</span></li>'
                         '<li><b>Réunion parents-professeurs</b><span class="i">Direction</span></li></ul>',
        "infos_html": '<ul class="pronote-info"><li class="unread"><b>Photo de classe</b><span class="i">Vie scolaire · {} · sondage</span></li>'
                      '<li><b>Menus de la semaine</b><span class="i">Intendance · {}</span></li></ul>'.format(
                          (today - D(days=1)).strftime("%d/%m"), (today - D(days=5)).strftime("%d/%m")),
        "menu_today": menus[today], "menu_tomorrow": menus[today + D(days=1)],
        "menu_week_html": '<ul class="pronote-menu">' + menu_rows + "</ul>",
        "skills_html": "",
    }
    return {"ok": True, "credentials": None, "warnings": ["jeu d'essai : aucune connexion à Pronote"], "data": data}


def main():
    # Tout fichier créé par ce script (photo, fichier temporaire) est privé.
    import os
    os.umask(0o077)
    parser = argparse.ArgumentParser(description="Récupération Pronote pour Jeedom")
    parser.add_argument("--request", help="fichier JSON de requête")
    parser.add_argument("--selftest", action="store_true",
                        help="renvoie un jeu d'essai sans contacter Pronote")
    parser.add_argument("--decode-qr", dest="decode_qr",
                        help="lit une image de QR Code et rend son contenu JSON")
    args = parser.parse_args()

    if args.decode_qr:
        decode_qr(args.decode_qr)
        return

    if args.selftest:
        json.dump(selftest_payload(), sys.stdout, ensure_ascii=False)
        sys.stdout.write("\n")
        return

    if not args.request:
        fail("script", "--request est obligatoire")

    try:
        with open(args.request, "r", encoding="utf-8") as fh:
            req = json.load(fh)
    except Exception as exc:
        fail("script", "requête illisible : {}".format(exc))

    client, _ = connect(req)
    select_child(client, req)
    creds = export_credentials(client)

    # Le jeton a tourné à la connexion : quoi qu'il arrive ensuite, il doit
    # repartir vers Jeedom, sinon la synchronisation suivante échoue et il
    # faut ré-enrôler. D'où la collecte dans un dictionnaire partagé et la
    # réponse « ok: false » qui porte quand même credentials et données partielles.
    data = {}
    try:
        collect(client, req, data)
    except Exception as exc:
        print(traceback.format_exc(), file=sys.stderr)
        json.dump({"ok": False, "code": "script", "error": "collecte interrompue : {}".format(exc),
                   "credentials": creds, "data": data, "warnings": WARNINGS},
                  sys.stdout, ensure_ascii=False)
        sys.stdout.write("\n")
        return

    json.dump({"ok": True, "credentials": creds, "data": data, "warnings": WARNINGS},
              sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")


if __name__ == "__main__":
    try:
        main()
    except SystemExit:
        raise
    except Exception as exc:
        print(traceback.format_exc(), file=sys.stderr)
        fail("script", exc)
