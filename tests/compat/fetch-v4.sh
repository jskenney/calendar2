#!/usr/bin/env bash
# Fetch calendar-core (v4) at the commit in tests/compat/V4_COMMIT into .v4/
# (or the directory given as $1).  Used by CI and for local compatibility runs.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
target="${1:-$here/../../.v4}"
commit="$(tr -d '[:space:]' < "$here/V4_COMMIT")"
if [ -f "$target/calendar_main.php" ] && [ "$(git -C "$target" rev-parse HEAD 2>/dev/null)" = "$commit" ]; then
  echo "calendar-core $commit already in $target"
  exit 0
fi
rm -rf "$target"
git init -q "$target"
git -C "$target" fetch -q --depth 1 https://github.com/jskenney/calendar-core.git "$commit"
git -C "$target" checkout -q FETCH_HEAD
echo "calendar-core $commit fetched into $target"
