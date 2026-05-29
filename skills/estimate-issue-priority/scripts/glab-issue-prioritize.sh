#!/usr/bin/env bash
#
# glab-issue-prioritize.sh <issue-url> <priority>
#
# Sets an issue's scoped "priority::<value>" label. This is the ONLY place issue
# priority writing touches glab.
#
# <priority> must be one of: minor | normal | major | critical
#
# Refuses to overwrite: if the issue already has any "priority::" label, it writes
# NOTHING and exits non-zero. This guard is authoritative even if a caller skips
# the read step.
#
# Exit codes:
#   0  priority label set
#   2  bad usage / missing dependency / unreadable issue
#   3  refused: a priority label already exists
#   4  failure: the label write failed
#
# Portable to macOS stock bash 3.2. External dependency: jq.

set -eu

die() { echo "ERROR: $*" >&2; exit 2; }

command -v glab >/dev/null 2>&1 || die "glab is not installed or not on PATH."
command -v jq   >/dev/null 2>&1 || die "jq is not installed or not on PATH."

URL="${1:-}"
PRIORITY="${2:-}"

[ -n "$URL" ] && [ -n "$PRIORITY" ] \
  || die "usage: glab-issue-prioritize.sh <issue-url> <priority>"

case "$PRIORITY" in
  minor|normal|major|critical) ;;
  *) die "priority must be one of: minor, normal, major, critical; got: $PRIORITY" ;;
esac

# Parse host+project and issue IID from the URL so glab targets the right instance.
# glab's `view` honors the host in a URL but `update` does not, so every command is
# issued with an explicit -R. Inputs without "/-/issues/" (e.g. OWNER/REPO#42) are
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

# Authoritative guard: re-read the current labels straight from the source.
JSON="$(gl issue view "$IID" -F json 2>/dev/null)" \
  || die "Could not read issue. Check the URL and that glab is authenticated."

CURRENT="$(printf '%s' "$JSON" \
  | jq -r '[.labels[]? | if type=="object" then .name else . end] | map(select(startswith("priority::"))) | (.[0] // "")')"

if [ -n "$CURRENT" ]; then
  echo "REFUSED: issue already has priority label $CURRENT" >&2
  exit 3
fi

# Set the scoped priority label. GitLab scoped labels are mutually exclusive within
# their scope, but we only reach here when none exists.
if ! gl issue update "$IID" --label "priority::$PRIORITY" >/dev/null 2>&1; then
  echo "FAILED: could not set priority label." >&2
  exit 4
fi

echo "OK: set priority::$PRIORITY"
