#!/usr/bin/env bash
#
# glab-issue-show.sh <issue-url>
#
# Reads a GitLab issue and its comments and prints a clean, tool-agnostic text
# block. This is the ONLY place issue reading touches glab; callers consume the
# printed format below and never see glab's own output shape.
#
# Output format:
#   TITLE: ...
#   STATE: opened|closed
#   LABELS: a, b, c
#   PRIORITY: none | minor | normal | major | critical
#   DESCRIPTION:
#   <body>
#   COMMENTS:
#   <full comment thread>
#
# PRIORITY is derived from any existing scoped "priority::<value>" label.
#
# Portable to macOS stock bash 3.2: no bash-4 / GNU-only features.
# External dependency: jq.

set -eu

die() { echo "ERROR: $*" >&2; exit 1; }

command -v glab >/dev/null 2>&1 || die "glab is not installed or not on PATH."
command -v jq   >/dev/null 2>&1 || die "jq is not installed or not on PATH."

URL="${1:-}"
[ -n "$URL" ] || die "usage: glab-issue-show.sh <issue-url>"

# Parse host+project and issue IID from the URL so glab targets the right instance.
# glab's `view` honors the host in a URL but `update`/`note` do not, so every command
# is issued with an explicit -R. Inputs without "/-/issues/" (e.g. OWNER/REPO#42) are
# passed through unchanged.
case "$URL" in
  *"/-/issues/"*)     REPO="${URL%%/-/issues/*}";     IID="${URL##*/-/issues/}" ;;
  *"/-/work_items/"*) REPO="${URL%%/-/work_items/*}"; IID="${URL##*/-/work_items/}" ;;
  *)                  REPO="";                        IID="$URL" ;;
esac
IID="${IID%%[/?#]*}"

gl() {
  if [ -n "$REPO" ]; then glab "$@" -R "$REPO"; else glab "$@"; fi
}

# Structured fields as JSON.
JSON="$(gl issue view "$IID" -F json 2>/dev/null)" \
  || die "Could not read issue. Check the URL and that glab is authenticated."
[ -n "$JSON" ] || die "Empty response from issue lookup. Check the URL."

TITLE="$(printf '%s' "$JSON" | jq -r '.title // ""')"
STATE="$(printf '%s' "$JSON" | jq -r '.state // ""')"
LABELS="$(printf '%s' "$JSON" | jq -r '[.labels[]? | if type=="object" then .name else . end] | join(", ")')"
DESCRIPTION="$(printf '%s' "$JSON" | jq -r '.description // ""')"

# Extract the value of the first scoped "priority::" label, if any.
PRIORITY_RAW="$(printf '%s' "$JSON" \
  | jq -r '[.labels[]? | if type=="object" then .name else . end] | map(select(startswith("priority::"))) | (.[0] // "")' \
  | sed 's/^priority:://')"
if [ -z "$PRIORITY_RAW" ]; then
  PRIORITY="none"
else
  PRIORITY="$PRIORITY_RAW"
fi

# Comment thread (text). Best-effort: an issue with no comments still succeeds.
COMMENTS="$(gl issue view "$IID" --comments 2>/dev/null || true)"

printf 'TITLE: %s\n' "$TITLE"
printf 'STATE: %s\n' "$STATE"
printf 'LABELS: %s\n' "$LABELS"
printf 'PRIORITY: %s\n' "$PRIORITY"
printf 'DESCRIPTION:\n%s\n' "$DESCRIPTION"
printf 'COMMENTS:\n%s\n' "$COMMENTS"
