#!/usr/bin/env bash
#
# glab-issues-unweighted.sh <project-url-or-path> [--include-closed]
#
# Lists the issues in a project that still need a weight estimate, so a caller can
# size a whole backlog in one pass. This is the ONLY place issue listing touches glab.
#
# An issue "still needs estimating" when it has BOTH:
#   - no weight (weight is unset; a stored 0 counts as unset, the drupal.org migration
#     default), AND
#   - no "No Estimation Available" label.
# In other words an issue drops off this list once it is either weighted or explicitly
# marked as not estimatable. Issues that already have one of those are skipped.
#
# <project-url-or-path> is anything glab's -R accepts: a full project URL
# (https://gitlab.example.com/group/project), an issues/board URL with a trailing
# "/-/..." part (stripped automatically), or an OWNER/REPO path.
#
# State:
#   (no flag)         OPEN issues only (default).
#   --include-closed  Look through closed issues as well as open ones.
#
# Output:
#   One issue web URL per line on stdout, ready to feed to glab-issue-estimate.sh or
#   glab-issue-no-estimate.sh. A one-line summary count goes to stderr. If every issue
#   is already weighted or marked, stdout is empty.
#
# Exit codes:
#   0  listing succeeded (zero or more URLs printed)
#   1  bad usage / missing dependency / could not read the project
#
# Portable to macOS stock bash 3.2: no bash-4 / GNU-only features. All pages are
# fetched so the result is complete, not just the first page.
# External dependency: jq.

set -eu

die() { echo "ERROR: $*" >&2; exit 1; }

command -v glab >/dev/null 2>&1 || die "glab is not installed or not on PATH."
command -v jq   >/dev/null 2>&1 || die "jq is not installed or not on PATH."

PROJECT="${1:-}"
FLAG="${2:-}"
[ -n "$PROJECT" ] || die "usage: glab-issues-unweighted.sh <project-url-or-path> [--include-closed]"

# Default to open issues; --include-closed widens the scan to all states.
STATE_ARGS=""
STATE_DESC="open"
case "$FLAG" in
  "") ;;
  --include-closed) STATE_ARGS="--all"; STATE_DESC="open and closed" ;;
  *) die "second argument, if given, must be --include-closed; got: $FLAG" ;;
esac

# Accept a bare project URL, an issues page, or a board URL: drop any "/-/..." tail
# and a trailing slash so what is left is the project glab's -R expects.
REPO="${PROJECT%%/-/*}"
REPO="${REPO%/}"

LABEL="No Estimation Available"

# Walk every page. Filter each batch as it arrives so we never hold the whole backlog
# in memory, and emit the web URL of any issue that is neither weighted nor marked as
# not estimatable. A stored weight of 0 is treated as unset (drupal.org default).
PER_PAGE=100
PAGE=1
SCANNED=0
EMITTED=0

while :; do
  BATCH="$(glab issue list -R "$REPO" $STATE_ARGS --output json --per-page "$PER_PAGE" --page "$PAGE" 2>/dev/null)" \
    || die "Could not list issues. Check the project URL and that glab is authenticated."
  [ -n "$BATCH" ] || BATCH="[]"

  N="$(printf '%s' "$BATCH" | jq 'length')"
  [ "$N" -eq 0 ] && break
  SCANNED=$((SCANNED + N))

  URLS="$(printf '%s' "$BATCH" | jq -r --arg repo "$REPO" --arg label "$LABEL" '
    .[]
    | select((.weight // 0) == 0)
    | select(
        ([.labels[]? | if type=="object" then .name else . end]
         | map(select(. == $label)) | length) == 0
      )
    | (.web_url // ($repo + "/-/issues/" + (.iid|tostring)))
  ')"

  if [ -n "$URLS" ]; then
    printf '%s\n' "$URLS"
    EMITTED=$((EMITTED + $(printf '%s\n' "$URLS" | grep -c .)))
  fi

  [ "$N" -lt "$PER_PAGE" ] && break
  PAGE=$((PAGE + 1))
done

echo "SUMMARY: $EMITTED of $SCANNED $STATE_DESC issue(s) still need a weight estimate." >&2
