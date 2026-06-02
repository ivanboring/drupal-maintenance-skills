#!/usr/bin/env bash
#
# glab-issues-unprioritized.sh <project-url-or-path>
#
# Lists the OPEN issues in a project that have NO scoped "priority::" label, so a
# caller can prioritize a whole project's backlog in one pass. This is the ONLY
# place issue listing touches glab.
#
# <project-url-or-path> is anything glab's -R accepts: a full project URL
# (https://gitlab.example.com/group/project), an issues/board URL with a trailing
# "/-/..." part (stripped automatically), or an OWNER/REPO path.
#
# Output:
#   One issue web URL per line on stdout, ready to feed to
#   glab-issue-prioritize.sh. A one-line summary count goes to stderr.
#   If every open issue is already prioritized, stdout is empty.
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
[ -n "$PROJECT" ] || die "usage: glab-issues-unprioritized.sh <project-url-or-path>"

# Accept a bare project URL, an issues page, or a board URL: drop any "/-/..." tail
# and a trailing slash so what is left is the project glab's -R expects.
REPO="${PROJECT%%/-/*}"
REPO="${REPO%/}"

# Walk every page of OPEN issues (default state). Filter each batch as it arrives so
# we never hold the whole backlog in memory, and emit the web URL of any issue that
# carries no "priority::" scoped label.
PER_PAGE=100
PAGE=1
SCANNED=0
EMITTED=0

while :; do
  BATCH="$(glab issue list -R "$REPO" --output json --per-page "$PER_PAGE" --page "$PAGE" 2>/dev/null)" \
    || die "Could not list issues. Check the project URL and that glab is authenticated."
  [ -n "$BATCH" ] || BATCH="[]"

  N="$(printf '%s' "$BATCH" | jq 'length')"
  [ "$N" -eq 0 ] && break
  SCANNED=$((SCANNED + N))

  URLS="$(printf '%s' "$BATCH" | jq -r --arg repo "$REPO" '
    .[]
    | select(
        ([.labels[]? | if type=="object" then .name else . end]
         | map(select(startswith("priority::"))) | length) == 0
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

echo "SUMMARY: $EMITTED of $SCANNED open issue(s) have no priority label." >&2
