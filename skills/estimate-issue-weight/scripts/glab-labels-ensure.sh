#!/usr/bin/env bash
#
# glab-labels-ensure.sh <project-url-or-path> [--create]
#
# Ensures the "No Estimation Available" label exists in a project, created with
# color #cd5b45. This is the ONLY place label creation touches glab. The label
# marks issues that cannot be sized (too little information, or otherwise not
# estimatable).
#
# <project-url-or-path> is anything glab's -R accepts: a full project URL, an
# issues/board URL with a trailing "/-/..." part (stripped automatically), or an
# OWNER/REPO path.
#
# Two modes:
#   (no flag)   CHECK ONLY. Reports whether the label already exists or is MISSING,
#               then exits WITHOUT creating anything. Use this first and confirm with
#               the user before creating.
#   --create    Creates the label with color #cd5b45 if it is missing. An existing
#               label is left untouched (its color is not changed).
#
# Exit codes:
#   0  the label already exists (check or create), or --create created it
#   2  bad usage / missing dependency / could not read the project
#   3  check mode: the label is MISSING (nothing was created)
#   4  --create: label creation failed
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

COLOR="#cd5b45"
LABEL="No Estimation Available"

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

if printf '%s\n' "$EXISTING" | grep -Fxq "$LABEL"; then
  echo "EXISTS: $LABEL"
  echo "OK: the \"$LABEL\" label already exists."
  exit 0
fi

echo "MISSING: $LABEL"

# Check mode: report and stop. Creation only happens with an explicit --create.
if [ "$MODE" != "--create" ]; then
  echo "NOTE: \"$LABEL\" is missing. Re-run with --create to create it with color $COLOR." >&2
  exit 3
fi

# --create: create the missing label with the required color.
if glab label create -R "$REPO" --name "$LABEL" --color "$COLOR" >/dev/null 2>&1; then
  echo "CREATED: $LABEL ($COLOR)"
  echo "OK: created the \"$LABEL\" label with color $COLOR."
  exit 0
fi

echo "FAILED: could not create \"$LABEL\"." >&2
exit 4
