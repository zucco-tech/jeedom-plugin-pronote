#!/bin/bash
# Installation des dépendances du plugin Pronote.
# Un venv est obligatoire : Debian 12+ refuse pip dans le Python système (PEP 668).

PROGRESS_FILE=$1
BASEDIR=$(cd "$(dirname "$0")" && pwd)

progress() { [ -n "$PROGRESS_FILE" ] && echo "$1" > "$PROGRESS_FILE"; }

echo "=== Dépendances Pronote : début ==="
progress 0

echo "--- Paquets système ---"
apt-get update
apt-get install -y python3 python3-venv python3-pip
progress 35

echo "--- Environnement virtuel : $BASEDIR/venv ---"
rm -rf "$BASEDIR/venv"
python3 -m venv "$BASEDIR/venv"
progress 55

echo "--- Bibliothèques Python ---"
"$BASEDIR/venv/bin/pip" install --upgrade pip
"$BASEDIR/venv/bin/pip" install -r "$BASEDIR/pronote/requirements.txt"
progress 95

echo "--- Vérification ---"
"$BASEDIR/venv/bin/python3" -c "import pronotepy; print('pronotepy', pronotepy.__version__)" \
  || { echo "ECHEC : pronotepy n'est pas importable"; exit 1; }

progress 100
[ -n "$PROGRESS_FILE" ] && rm -f "$PROGRESS_FILE"
echo "=== Dépendances Pronote : terminé ==="
