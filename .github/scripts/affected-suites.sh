#!/usr/bin/env bash
# Reads the files a pull request changes, one per line, and prints whether they
# affect the PHP suite (run-tests.yml), in the GITHUB_OUTPUT format:
#
#   php=true|false
#
# A file that isn't listed below affects the PHP suite, so a new kind of file
# runs the tests instead of skipping them.

set -euo pipefail

php=false

while IFS= read -r file; do
    [ -z "$file" ] && continue

    case "$file" in
        *.md | LICENSE* | .editorconfig | .gitignore | .git-blame-ignore-revs | \
            .github/dependabot.yml | .github/workflows/pint.yml | .github/workflows/phpstan.yml)
            ;;
        *)
            php=true
            ;;
    esac
done

echo "php=$php"
