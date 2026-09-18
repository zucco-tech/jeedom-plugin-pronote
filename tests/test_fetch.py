"""Tests unitaires du script Python, sans Jeedom ni Pronote.

    pytest -q tests/test_fetch.py
"""
import datetime
import importlib.util
import json
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
SCRIPT = os.path.join(HERE, "..", "resources", "pronote", "pronote_fetch.py")

spec = importlib.util.spec_from_file_location("pronote_fetch", SCRIPT)
pf = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pf)


def test_num_accepts_pronote_formats():
    assert pf.num("14,50") == 14.5
    assert pf.num("15.5") == 15.5
    assert pf.num(12) == 12.0
    assert pf.num("Abs") is None
    assert pf.num("") is None
    assert pf.num(None) is None


def test_hours_to_float_formats():
    assert pf.hours_to_float("2h00") == 2.0
    assert pf.hours_to_float("1h30") == 1.5
    assert pf.hours_to_float("2") == 2.0
    assert pf.hours_to_float(None) == 0.0
    assert pf.hours_to_float("n'importe quoi") == 0.0


def test_pronote_date_shapes():
    assert pf.pronote_date({"_T": 7, "V": "18/10/2026 00:00:00"}) == datetime.date(2026, 10, 18)
    assert pf.pronote_date("18/10/2026") == datetime.date(2026, 10, 18)
    assert pf.pronote_date({"V": ""}) is None
    assert pf.pronote_date(None) is None


def test_html_builders_escape_user_content():
    """Ce qui vient de Pronote est affiché dans Jeedom : jamais de balise brute."""
    evil = pf._Fake(start=datetime.datetime(2026, 9, 18, 8, 30), end=datetime.datetime(2026, 9, 18, 9, 30),
                    subject=pf._Fake(name='<script>alert(1)</script>'), teacher_name='"><img src=x onerror=alert(1)>',
                    classroom="B12", canceled=False, status="", test=False)
    html = pf.lessons_html([evil])
    assert "<script>" not in html
    assert "onerror" not in html or "&lt;img" in html
    assert "&lt;script&gt;" in html


def test_menu_text_with_labels():
    bio = pf._Fake(name="bio")
    dish = pf._Fake(name="Poulet rôti", labels=[bio])
    plain = pf._Fake(name="Purée", labels=[])
    menu = pf._Fake(first_meal=[], main_meal=[dish], side_meal=[plain], cheese=None, dessert=[], other_meal=None)
    assert pf.menu_text(menu) == "Poulet rôti (bio), Purée"


def test_selftest_is_complete_and_dated_today():
    out = subprocess.run([sys.executable, SCRIPT, "--selftest"], capture_output=True, text=True, check=True).stdout
    d = json.loads(out)
    assert d["ok"] is True and d["credentials"] is None
    data = d["data"]
    for key in ("_lessons", "_homework", "_grades", "_holidays", "_subjects", "period_name",
                "next_holiday_start", "grades_html", "messages_html", "infos_html", "absences_html",
                "menu_week_html", "timetable_week_html"):
        assert key in data, key
    today = datetime.date.today().isoformat()
    assert any(l["date"] == today for l in data["_lessons"]) or datetime.date.today().weekday() >= 5
    assert 'data-today="1"' in data["timetable_week_html"]
    assert data["_holidays"][0]["kind"] == "vacances"


def test_fail_never_leaks_credentials(capsys):
    """Le chemin d'erreur ne renvoie que code + message."""
    try:
        pf.fail("auth", "refusé")
    except SystemExit:
        pass
    out = json.loads(capsys.readouterr().out)
    assert out == {"ok": False, "code": "auth", "error": "refusé", "warnings": pf.WARNINGS}
