#!/usr/bin/env bash
# Checks that CI is green on a commit: every required check run has completed
# successfully. Needs the gh CLI, with GH_TOKEN set in GitHub Actions.
#
#   .github/scripts/check-green.sh <owner/repo> <sha>

set -euo pipefail

repo="${1:?Usage: check-green.sh <owner/repo> <sha>}"
sha="${2:?Usage: check-green.sh <owner/repo> <sha>}"

# The jobs of run-tests.yml, phpstan.yml and pint.yml that must pass.
required=("Tests passed" "phpstan" "pint")

runs="$(gh api --paginate "repos/${repo}/commits/${sha}/check-runs" \
    --jq '.check_runs[] | [.name, .status, (.conclusion // "")] | @tsv')"

failed=0
for name in "${required[@]}"; do
    # A check can run more than once (re-runs): one success is enough.
    if printf '%s\n' "$runs" | awk -F '\t' -v name="$name" '$1 == name && $2 == "completed" && $3 == "success" { found = 1 } END { exit !found }'; then
        echo "✓ ${name}"
    else
        states="$(printf '%s\n' "$runs" | awk -F '\t' -v name="$name" '$1 == name { printf "%s %s; ", $2, $3 }')"
        echo "✗ ${name}: ${states:-no run on ${sha}}" >&2
        failed=1
    fi
done

if [ "$failed" -ne 0 ]; then
    echo "CI isn't green on ${sha}: tag a commit whose checks have all passed." >&2
    exit 1
fi
