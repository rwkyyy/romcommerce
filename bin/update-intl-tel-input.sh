#!/usr/bin/env bash
# Refreshes the bundled intl-tel-input (phone validation) in
# assets/lib/intl-tel-input from npm when a newer release exists. Leaves the
# directory untouched when it's already current, so the monthly workflow only
# opens a PR for a real update. The plugin never fetches it at runtime.
#
# Usage: bash bin/update-intl-tel-input.sh   (needs npm and tar)
set -euo pipefail

cd "$(dirname "$0")/.."

target="assets/lib/intl-tel-input"
current="$(cat "$target/VERSION" 2>/dev/null || true)"
latest="$(npm view intl-tel-input version)"

if [ -z "$latest" ]; then
	echo "::error::Could not read the latest intl-tel-input version from npm." >&2
	exit 1
fi

if [ "$current" = "$latest" ]; then
	echo "intl-tel-input $current is current."
	exit 0
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
(cd "$work" && npm pack "intl-tel-input@$latest" --silent >/dev/null && tar xzf intl-tel-input-*.tgz)

package="$work/package"
for file in dist/js/intlTelInputWithUtils.js dist/css/intlTelInput.css dist/img/flags.webp dist/img/flags@2x.webp LICENSE; do
	if [ ! -f "$package/$file" ]; then
		echo "::error::intl-tel-input $latest no longer ships $file; update this script and the module." >&2
		exit 1
	fi
done

rm -rf "$target"
mkdir -p "$target/js" "$target/css" "$target/img"
cp "$package/dist/js/intlTelInputWithUtils.js" "$target/js/"
cp "$package/dist/css/intlTelInput.css" "$target/css/"
# wp.org Plugin Check rejects "@" in file names, so the retina sprite is
# renamed and the stylesheet pointed at the new name.
cp "$package/dist/img/flags.webp" "$target/img/flags.webp"
cp "$package/dist/img/flags@2x.webp" "$target/img/flags-2x.webp"
sed -i.bak 's/flags@2x\.webp/flags-2x.webp/g' "$target/css/intlTelInput.css" && rm "$target/css/intlTelInput.css.bak"
if grep -q '@2x' "$target/css/intlTelInput.css"; then
	echo "::error::intlTelInput.css still references an @2x file; update the rename above." >&2
	exit 1
fi
cp "$package/LICENSE" "$target/"
echo "$latest" > "$target/VERSION"

echo "intl-tel-input updated: ${current:-none} -> $latest"
