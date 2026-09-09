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
            if "Accès refusé" in message or "error from pronote: 3" in message.lower() or "acces refuse" in message.lower():
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


def collect(client, req):
    blocks = set(req.get("data") or [])
    data = {}
    now = datetime.datetime.now()
    data["last_sync"] = now.strftime("%d/%m/%Y %H:%M")
    data["_meta"] = student_meta(client)

    period = safe("current_period", lambda: client.current_period)

    if "notes" in blocks and period is not None:
        collect_notes(client, period, data, req)

    if "devoirs" in blocks:
        collect_homework(client, data, req, now)

    if "edt" in blocks:
        collect_timetable(client, data, now)

    if "absences" in blocks and period is not None:
        data["absences"] = safe("absences", lambda: round(sum(
            hours_to_float(getattr(a, "hours", None)) for a in period.absences), 2), 0)
        data["delays"] = safe("delays", lambda: len(period.delays), 0)

    if "punitions" in blocks and period is not None:
        data["punishments"] = safe("punishments", lambda: len(period.punishments), 0)

    if "vie" in blocks:
        data["new_messages"] = safe("messages", lambda: len(
            [d for d in client.discussions() if getattr(d, "unread", 0)]), 0)

    if "cantine" in blocks:
        data["menu_today"] = safe("menu", lambda: collect_menu(client, now), "") or ""
        data["menu_tomorrow"] = safe("menu demain", lambda: collect_menu(
            client, now + datetime.timedelta(days=1)), "") or ""

    if "competences" in blocks and period is not None:
        data["skills_html"] = safe("competences", lambda: collect_skills(period), "") or ""

    return data


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

    if req.get("per_subject"):
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
    homework = safe("homework", lambda: list(
        client.homework(today, today + datetime.timedelta(days=days))), []) or []

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
    # Un seul appel pour la semaine : pronotepy convertit la date de fin en
    # « ce jour à 00:00 », donc today+7 inclut exactement J..J+6. Surtout ne
    # jamais passer lessons(d, d) : ça ne rend rien.
    lessons = safe("lessons semaine", lambda: list(
        client.lessons(today, today + datetime.timedelta(days=horizon))), []) or []
    lessons = [l for l in lessons if getattr(l, "start", None)]
    lessons.sort(key=lambda l: l.start)

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


def collect_menu(client, now):
    menus = safe("menus", lambda: list(client.menus(now.date(), now.date())), []) or []
    if not menus:
        return ""
    parts = []
    for menu in menus:
        for attr in ("first_meal", "main_meal", "side_meal", "dessert"):
            dishes = getattr(menu, attr, None) or []
            for dish in dishes:
                name = getattr(dish, "name", None)
                if name:
                    parts.append(str(name))
    return ", ".join(parts[:10])


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

def selftest_payload():
    now = datetime.datetime.now()
    return {
        "ok": True,
        "credentials": None,
        "warnings": ["jeu d'essai : aucune connexion à Pronote"],
        "data": {
            "last_sync": now.strftime("%d/%m/%Y %H:%M"),
            "avg_general": 14.7, "avg_class": 12.9,
            "last_grade": "Mathématiques : 16/20", "new_grades": 1,
            "homework_count": 3, "homework_tomorrow": 1, "homework_tomorrow_pending": 1,
            "_grades_count": 21, "course_cancelled_tomorrow": 0,
            "menu_tomorrow": "Carottes râpées, lasagnes, fromage, compote",
            "homework_html": '<ul class="pronote-hw"><li class="urgent" data-date="2026-01-14"><b>Mathématiques</b><span class="d">demain</span>'
                             '<span class="t">Exercices 12 à 15 p. 84</span></li></ul>',
            "next_course": "Anglais LV1 — Salle A04 — Mme Rivet",
            "next_course_start": "09h30",
            "timetable_html": '<ul class="pronote-edt">'
                              '<li data-start="0830" data-end="0930" style="--c:hsl(200,55%,58%)"><span class="h"><span>08h30</span><span>09h30</span></span><span class="s"><b>Mathématiques</b><span class="i">M. Dubois · B12</span></span></li>'
                              '<li data-start="0930" data-end="1030" style="--c:hsl(30,55%,58%)"><span class="h"><span>09h30</span><span>10h30</span></span><span class="s"><b>Anglais LV1</b><span class="i">Mme Rivet · A04</span></span></li>'
                              '<li data-start="1330" data-end="1430" style="--c:hsl(120,55%,58%)" class="cancelled"><span class="h"><span>13h30</span><span>14h30</span></span><span class="s"><b>SVT</b><span class="i">Mme Cohen · Labo 2 · Prof. absent</span></span></li>'
                              '</ul>',
            "timetable_tomorrow_html": '<ul class="pronote-edt">'
                              '<li data-start="0810" data-end="0905" style="--c:hsl(280,55%,58%)"><span class="h"><span>08h10</span><span>09h05</span></span><span class="s"><b>Histoire-Géo</b><span class="i">M. Perrin · C21</span></span></li>'
                              '</ul>',
            "timetable_week_html":
                '<ul class="pronote-edt" data-date="2026-01-13" data-label="mardi 13 janv." data-rel="aujourd\'hui" data-today="1">'
                '<li data-start="0830" data-end="0930" style="--c:hsl(200,55%,58%)"><span class="h"><span>08h30</span><span>09h30</span></span><span class="s"><b>Mathématiques</b><span class="i">M. Dubois · B12</span></span></li>'
                '<li data-start="0930" data-end="1030" style="--c:hsl(30,55%,58%)"><span class="h"><span>09h30</span><span>10h30</span></span><span class="s"><b>Anglais LV1</b><span class="i">Mme Rivet · A04</span></span></li>'
                '</ul>'
                '<ul class="pronote-edt" data-date="2026-01-14" data-label="mercredi 14 janv." data-rel="demain">'
                '<li data-start="0810" data-end="0905" style="--c:hsl(280,55%,58%)"><span class="h"><span>08h10</span><span>09h05</span></span><span class="s"><b>Histoire-Géo</b><span class="i">M. Perrin · C21</span></span></li>'
                '</ul>'
                '<ul class="pronote-edt" data-date="2026-01-15" data-label="jeudi 15 janv." data-rel="jeudi"></ul>',
            "course_cancelled": 0,
            "absences": 2, "delays": 1, "punishments": 0, "new_messages": 1,
            "menu_today": "Salade, poulet rôti, purée, yaourt",
            "skills_html": "",
            "_meta": {"name": "Léa Martin", "class_name": "4E B", "establishment": "Collège Jean Moulin"},
            "_subjects": [
                {"logicalId": "avg_subject_mathematiques", "slug": "mathematiques", "name": "Mathématiques", "value": 15.2, "class_value": 12.4, "last_grade": "16/20 · 08/01"},
                {"logicalId": "avg_subject_francais", "slug": "francais", "name": "Français", "value": 13.8, "class_value": 12.9, "last_grade": "12/20 · 06/01"},
            ],
        },
    }


def main():
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
    data = collect(client, req)

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
