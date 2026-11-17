#!/usr/bin/env bash
# Prints the GitHub release notes for one version: its readme.txt changelog
# section, then the label legend from the top of == Changelog ==, so the notes
# read the same as on wp.org. Fails when the version has no changelog section.
# Usage: bin/release-notes.sh 0.9
set -euo pipefail

cd "$(dirname "$0")/.."

version="${1:?usage: bin/release-notes.sh <version>}"

trim_blank_edges() {
	awk 'NF { started = 1; for (; blanks > 0; blanks--) print ""; print; next } started { blanks++ }'
}

# Upgrade Notice repeats the "= X.Y =" headings, so matching stops at the end of
# == Changelog == rather than falling through to the notice.
section="$(tr -d '\r' < readme.txt | awk -v heading="= ${version} =" '
	/^== / { if (changelog) exit; changelog = ($0 == "== Changelog =="); next }
	changelog && /^= .* =$/ { if (inside) exit; inside = ($0 == heading); next }
	inside
' | trim_blank_edges)"

if [ -z "$section" ]; then
	echo "readme.txt has no changelog section \"= ${version} =\"." >&2
	exit 1
fi

legend="$(tr -d '\r' < readme.txt | awk '
	/^== Changelog ==$/ { inside = 1; next }
	inside && (/^= / || /^== /) { exit }
	inside
' | trim_blank_edges)"

printf '%s\n' "$section"
if [ -n "$legend" ]; then
	printf '\n---\n\n%s\n' "$legend"
fi
