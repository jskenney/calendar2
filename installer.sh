#!/bin/bash
# Create a new Cal5 course in the current, empty directory:
#
#   mkdir SI460 && cd SI460
#   wget https://raw.githubusercontent.com/jskenney/calendar2/main/installer.sh
#   bash installer.sh
#
# It gets Cal5 (calendar-core/, with the usual calendar -> calendar-core/ link) and runs
# "php calendar/bin/cal5 init", which asks a few questions and writes the course.  Options
# are passed on to init (php calendar/bin/cal5 help); --source=DIR uses a Cal5 you already
# have instead of cloning it.  To move an existing v4 course to Cal5, see docs/migration.md.
set -euo pipefail

REPO="https://github.com/jskenney/calendar2.git"
SOURCE=""
ARGS=()
for arg in "$@"; do
  case "$arg" in
    --source=*) SOURCE="${arg#--source=}" ;;
    *) ARGS+=("$arg") ;;
  esac
done

if [ -e calendar.php ] || [ -e calendar.ini.php ]; then
  echo "This directory is already a course. To move a v4 course to Cal5, see docs/migration.md." >&2
  exit 1
fi
if ! command -v php > /dev/null; then
  echo "Cal5 needs PHP 8.3 or later (php was not found)." >&2
  exit 1
fi
if ! php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then
  echo "Cal5 needs PHP 8.3 or later (this is PHP $(php -r 'echo PHP_VERSION;'))." >&2
  exit 1
fi

if [ ! -e calendar-core ]; then
  if [ -n "$SOURCE" ]; then
    ln -s "$SOURCE" calendar-core
  else
    git clone --depth 1 "$REPO" calendar-core
  fi
fi
[ -e calendar ] || ln -s calendar-core/ calendar

php calendar/bin/cal5 init ${ARGS[@]+"${ARGS[@]}"}
