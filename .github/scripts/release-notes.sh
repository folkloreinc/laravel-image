#!/usr/bin/env bash
# Prints the section of CHANGELOG.md for a version, without its heading:
#
#   .github/scripts/release-notes.sh 2.0.0-alpha.1 [CHANGELOG.md]
#
# Fails if the changelog has no section for the version, or an empty one.

set -euo pipefail

version="${1:?Usage: release-notes.sh <version> [changelog]}"
changelog="${2:-CHANGELOG.md}"

notes="$(awk -v heading="## [${version}]" '
    index($0, heading) == 1 { found = 1; next }
    found && /^## \[/ { exit }
    found && /^\[[^]]+\]: / { exit }
    found { print }
' "$changelog")"

# Trim the blank lines around the section.
notes="$(printf '%s\n' "$notes" | sed -e '/./,$!d' | sed -e ':a' -e '/^\n*$/{$d;N;ba' -e '}')"

if [ -z "$notes" ]; then
    echo "CHANGELOG.md has no section for ${version}: add a \"## [${version}] - YYYY-MM-DD\" section first." >&2
    exit 1
fi

printf '%s\n' "$notes"
