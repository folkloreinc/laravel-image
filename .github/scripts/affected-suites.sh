#!/usr/bin/env bash
# Reads the files a pull request changes, one per line, and prints which test
# suites they affect, in the GITHUB_OUTPUT format:
#
#   php=true|false   the PHP suite (run-tests.yml)
#   js=true|false    the JS suite (js.yml)
#
# A file that isn't listed below affects the PHP suite, so a new kind of file
# runs the tests instead of skipping them.

set -euo pipefail

php=false
js=false

while IFS= read -r file; do
    [ -z "$file" ] && continue

    case "$file" in
        js/* | package.json | package-lock.json | babel.config.cjs | eslint.config.mjs | \
            rollup.config.js | .prettierrc.json | .browserslistrc | .github/workflows/js.yml)
            js=true
            ;;
        # Shared by the PHP and JS URL generators.
        tests/fixture/urls.json | .github/scripts/*)
            js=true
            php=true
            ;;
        *.md | LICENSE* | .editorconfig | .gitignore | .git-blame-ignore-revs | \
            .github/dependabot.yml | .github/workflows/pint.yml | .github/workflows/phpstan.yml)
            ;;
        *)
            php=true
            ;;
    esac
done

echo "php=$php"
echo "js=$js"
