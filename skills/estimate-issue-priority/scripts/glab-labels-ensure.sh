#!/usr/bin/env bash
#
# glab-labels-ensure.sh <project-url-or-path> [--create]
#
# Ensures the four scoped priority labels exist in a project:
#   priority::minor  priority::normal  priority::major  priority::critical
# all created with color #ff5353. This is the ONLY place label creation touches glab.
#
# <project-url-or-path> is anything glab's -R accepts: a full project URL, an
# issues/board URL with a trailing "/-/..." part (stripped automatically), or an
# OWNER/REPO path.
#
# Two modes:
#   (no flag)   CHECK ONLY. Reports which of the four labels already exist and which
#               are MISSING, then exits WITHOUT creating anything. Use this first and
#               confirm with the user before creating.
#   --create    Creates every missing label with color #ff5353. Existing labels are
#               left untouched (their color is not changed).
#
# Exit codes:
#   0  all four labels already exist (check or create), or --create created the rest
#   2  bad usage / missing dependency / could not read the project
#   3  check mode: one or more labels are MISSING (nothing was created)
#   4  --create: at least one label creation failed
#
# Portable to macOS stock bash 3.2: no bash-4 / GNU-only features.
# External dependency: jq.

set -eu

die() { echo "ERROR: $*" >&2; exit 2; }

command -v glab >/dev/null 2>&1 || die "glab is not installed or not on PATH."
command -v jq   >/dev/null 2>&1 || die "jq is not installed or not on PATH."

PROJECT="${1:-}"
MODE="${2:-}"
[ -n "$PROJECT" ] || die "usage: glab-labels-ensure.sh <project-url-or-path> [--create]"

case "$MODE" in
  ""|--create) ;;
  *) die "second argument, if given, must be --create; got: $MODE" ;;
esac

# Accept a bare project URL, an issues page, or a board URL.
REPO="${PROJECT%%/-/*}"
REPO="${REPO%/}"

COLOR="#ff5353"
LABELS="priority::minor priority::normal priority::major priority::critical"

# Collect every existing label name (all pages) into a newline-separated list.
EXISTING=""
PER_PAGE=100
PAGE=1
while :; do
  BATCH="$(glab label list -R "$REPO" -F json --per-page "$PER_PAGE" --page "$PAGE" 2>/dev/null)" \
    || die "Could not list labels. Check the project URL and that glab is authenticated."
  [ -n "$BATCH" ] || BATCH="[]"
  N="$(printf '%s' "$BATCH" | jq 'length')"
  [ "$N" -eq 0 ] && break
  NAMES="$(printf '%s' "$BATCH" | jq -r '.[].name')"
  EXISTING="${EXISTING}${NAMES}
"
  [ "$N" -lt "$PER_PAGE" ] && break
  PAGE=$((PAGE + 1))
done

label_exists() {
  printf '%s\n' "$EXISTING" | grep -Fxq "$1"
}

# Work out which of the four are present and which are missing.
MISSING=""
for L in $LABELS; do
  if label_exists "$L"; then
    echo "EXISTS: $L"
  else
    echo "MISSING: $L"
    MISSING="${MISSING}${L}
"
  fi
done

MISSING="$(printf '%s' "$MISSING" | grep -c . || true)"

if [ "$MISSING" -eq 0 ]; then
  echo "OK: all four priority labels already exist."
  exit 0
fi

# Check mode: report and stop. Creation only happens with an explicit --create.
if [ "$MODE" != "--create" ]; then
  echo "NOTE: $MISSING label(s) missing. Re-run with --create to create them with color $COLOR." >&2
  exit 3
fi

# --create: create each missing label with the required color.
FAILED=0
for L in $LABELS; do
  label_exists "$L" && continue
  if glab label create -R "$REPO" --name "$L" --color "$COLOR" >/dev/null 2>&1; then
    echo "CREATED: $L ($COLOR)"
  else
    echo "FAILED: could not create $L" >&2
    FAILED=$((FAILED + 1))
  fi
done

if [ "$FAILED" -ne 0 ]; then
  echo "FAILED: $FAILED label(s) could not be created." >&2
  exit 4
fi

echo "OK: created all missing priority labels with color $COLOR."
