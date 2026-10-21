#!/usr/bin/env bash
# Release gate for the bundled translations: fails if the committed POT, any
# locale's PO, or its compiled .mo / .l10n.php has fallen behind the source.
# Fix a failure by running `composer i18n`, translating any new strings in
# languages/*.po, running `composer i18n` again, and committing the result.
set -euo pipefail

cd "$(dirname "$0")/.."

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

fail=0

wp i18n make-pot . "$tmp/romcommerce.pot" --slug=romcommerce --domain=romcommerce \
	--exclude=vendor,tests,bin,.github,node_modules,.dist,artifacts,test-results,playwright-report --no-location --quiet

# POT-Creation-Date changes on every regeneration, so it can't count as drift.
if ! diff -q \
	<(grep -v '^"POT-Creation-Date:' languages/romcommerce.pot) \
	<(grep -v '^"POT-Creation-Date:' "$tmp/romcommerce.pot") >/dev/null; then
	echo "::error::languages/romcommerce.pot is out of date with the source strings. Run 'composer i18n'."
	fail=1
fi

for po in languages/*.po; do
	base="${po%.po}"

	if ! msgcmp "$po" "$tmp/romcommerce.pot" 2>"$tmp/msgcmp.log"; then
		echo "::error::$po is missing translations for current source strings:"
		cat "$tmp/msgcmp.log"
		fail=1
	fi

	stats="$(msgfmt --check --statistics -o /dev/null "$po" 2>&1)"
	if echo "$stats" | grep -qE 'fuzzy|untranslated'; then
		echo "::error::$po has fuzzy or untranslated entries: $stats"
		fail=1
	fi

	if [ ! -f "$base.mo" ]; then
		echo "::error::$base.mo is missing. Run 'composer i18n'."
		fail=1
	else
		# Compare decompiled message catalogs rather than bytes, so the check
		# doesn't depend on which compiler produced the committed .mo. Date
		# headers are dropped: GNU msgfmt strips POT-Creation-Date, WP-CLI keeps it.
		msgfmt -o "$tmp/fresh.mo" "$po"
		if ! diff -q \
			<(msgunfmt "$base.mo" | grep -vE '^"(POT-Creation|PO-Revision)-Date:') \
			<(msgunfmt "$tmp/fresh.mo" | grep -vE '^"(POT-Creation|PO-Revision)-Date:') >/dev/null; then
			echo "::error::$base.mo does not match $po. Run 'composer i18n'."
			fail=1
		fi
	fi

	if [ ! -f "$base.l10n.php" ]; then
		echo "::error::$base.l10n.php is missing. Run 'composer i18n'."
		fail=1
	else
		mkdir -p "$tmp/php"
		cp "$po" "$tmp/php/"
		wp i18n make-php "$tmp/php" --quiet
		if ! diff -q "$base.l10n.php" "$tmp/php/$(basename "$base").l10n.php" >/dev/null; then
			echo "::error::$base.l10n.php does not match $po. Run 'composer i18n'."
			fail=1
		fi
		rm -rf "$tmp/php"
	fi
done

exit "$fail"
