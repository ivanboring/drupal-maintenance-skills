#!/usr/bin/env bash
#
# glab-issue-no-estimate.sh <issue-url> <comment>
#
# Marks an issue as not estimatable: adds the "No Estimation Available" label and
# posts an explanatory comment together. Use this when an issue cannot be sized
# (too little information, or otherwise not estimatable) instead of setting a weight.
#
# The "No Estimation Available" label must already exist in the project; create it
# first with glab-labels-ensure.sh. This script does NOT create labels.
#
# Refuses to overwrite: if the issue already has a weight, it writes NOTHING and
# exits non-zero. This guard is authoritative even if a caller skips the read step.
#
# Exit codes:
#   0  label set and comment posted
#   2  bad usage / missing dependency / unreadable issue
#   3  refused: a weight already exists
#   4  partial failure: label or comment write failed
#
# Portable to macOS stock bash 3.2. External dependency: jq.

set -eu

die() { echo "ERROR: $*" >&2; exit 2; }

command -v glab >/dev/null 2>&1 || die "glab is not installed or not on PATH."
command -v jq   >/dev/null 2>&1 || die "jq is not installed or not on PATH."

URL="${1:-}"
COMMENT="${2:-}"

[ -n "$URL" ] && [ -n "$COMMENT" ] \
  || die "usage: glab-issue-no-estimate.sh <issue-url> <comment>"

LABEL="No Estimation Available"

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

# Authoritative guard: re-read the current weight straight from the source.
JSON="$(gl issue view "$IID" -F json 2>/dev/null)" \
  || die "Could not read issue. Check the URL and that glab is authenticated."
CURRENT="$(printf '%s' "$JSON" | jq -r '.weight // empty')"

# 0 counts as unset (drupal.org migration default), so only a positive weight blocks.
if [ -n "$CURRENT" ] && [ "$CURRENT" != "null" ] && [ "$CURRENT" != "0" ]; then
  echo "REFUSED: issue already has weight $CURRENT" >&2
  exit 3
fi

# Set the label first, then comment. Report partial failures clearly.
if ! gl issue update "$IID" --label "$LABEL" >/dev/null 2>&1; then
  echo "FAILED: could not set the \"$LABEL\" label; no comment was posted." >&2
  exit 4
fi

if ! gl issue note "$IID" -m "$COMMENT" >/dev/null 2>&1; then
  echo "PARTIAL: \"$LABEL\" label set, but posting the comment failed." >&2
  exit 4
fi

echo "OK: set \"$LABEL\" label and added comment"
