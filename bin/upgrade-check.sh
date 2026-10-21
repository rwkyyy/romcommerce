#!/usr/bin/env bash
# Simulates a real plugin update from the previous release and fails if
# anything a merchant would notice changed without being approved: a module
# switching on/off, or RomCommerce markup appearing/disappearing on the home,
# product, cart or checkout page (including checkout field names).
#
# For each scenario (see tests/upgrade/scenario.php): wipe RomCommerce's
# options, activate the previous release, record a snapshot, then swap the
# active plugin to the current build the way an update does (files replaced,
# no activation hook), run due cron, record again and compare.
#
# Intended differences go in bin/upgrade-check-allowlist.txt with a reason,
# so every behaviour change for existing stores is a decision, not an
# accident. Entries are scoped to the release being upgraded from; ones that
# no longer match anything fail as stale.
#
# Runs on the wp-env *tests* site (port 8889), which PHPUnit owns anyway.
# Needs a running wp-env started after bin/build-dist.sh built the previous
# release (`npm run env:start`), and the git tags (fetch-depth: 0 in CI).
set -euo pipefail

cd "$(dirname "$0")/.."

bash bin/build-dist.sh

previous_tag="$(cat .dist/romcommerce-previous/.release-tag 2>/dev/null || true)"
if [ -z "$previous_tag" ]; then
	echo "::error::No previous release tag reachable; fetch tags (git fetch --tags, or fetch-depth: 0 in CI)."
	exit 1
fi

wpc() {
	npx wp-env run tests-cli -- wp "$@" 2>/dev/null | grep -vE '^(ℹ|✔)' || true
}

if ! npx wp-env run tests-cli -- wp plugin is-installed romcommerce-previous >/dev/null 2>&1; then
	echo "::error::romcommerce-previous isn't mounted in wp-env; restart it with: npm run env:start"
	exit 1
fi

restore() {
	wpc plugin deactivate romcommerce-previous >/dev/null
	wpc plugin activate romcommerce >/dev/null
}
trap restore EXIT

# The integration suite shares this site and leaves WooCommerce inactive, so
# don't rely on wp-env's fresh-start state.
wpc plugin activate woocommerce romcommerce >/dev/null
wpc eval-file wp-content/romcommerce-tests/e2e/fixtures/store.php storefront >/dev/null

work="${UPGRADE_CHECK_KEEP:-$(mktemp -d)}"

capture() {
	local scenario="$1" phase="$2" json urls_dir="$work/$1-$2"
	mkdir -p "$urls_dir"
	json="$(wpc eval-file wp-content/romcommerce-tests/upgrade/snapshot.php | sed -n 's/^SNAPSHOT://p')"
	echo "$json" > "$urls_dir/snapshot.json"

	local jar="$urls_dir/cookies" url
	for page in home product cart checkout; do
		url="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["urls"][sys.argv[2]])' "$urls_dir/snapshot.json" "$page")"
		if [ "$page" = "cart" ]; then
			local product_id
			product_id="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["add_to_cart"])' "$urls_dir/snapshot.json")"
			curl -s -L -c "$jar" -b "$jar" "$url?add-to-cart=$product_id" -o /dev/null
		fi
		curl -s -L -c "$jar" -b "$jar" "$url" -o "$urls_dir/$page.html"
	done
}

for scenario in untouched configured; do
	wpc plugin deactivate romcommerce >/dev/null
	wpc eval 'global $wpdb; foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"romcommerce\\_%\"" ) as $o ) { delete_option( $o ); }' >/dev/null
	wpc plugin activate romcommerce-previous >/dev/null
	wpc eval-file wp-content/romcommerce-tests/upgrade/scenario.php "$scenario" >/dev/null
	capture "$scenario" before

	wpc eval '$p = (array) get_option( "active_plugins", array() ); $i = array_search( "romcommerce-previous/romcommerce.php", $p, true ); if ( false !== $i ) { $p[ $i ] = "romcommerce/romcommerce.php"; update_option( "active_plugins", array_values( $p ) ); }' >/dev/null
	wpc cron event run --due-now >/dev/null
	capture "$scenario" after
done

python3 - "$work" "$previous_tag" <<'PY'
import json, re, sys

work, previous_tag = sys.argv[1], sys.argv[2]
token = re.compile(r'^(romcommerce|rc-)', re.I)

def markers(html, page):
    found = set()
    for attr in re.findall(r'\b(?:class|id)\s*=\s*"([^"]*)"', html):
        for t in attr.split():
            if token.match(t):
                found.add(t)
    if page == "checkout":
        for name in re.findall(r'<(?:input|select|textarea)\b[^>]*\bname\s*=\s*"([^"]+)"', html):
            found.add("field:" + name)
    return found

def snapshot(scenario, phase):
    base = f"{work}/{scenario}-{phase}"
    data = json.load(open(f"{base}/snapshot.json"))
    out = {"modules": set(data["modules"])}
    for page in ("home", "product", "cart", "checkout"):
        out[page] = markers(open(f"{base}/{page}.html", encoding="utf-8", errors="replace").read(), page)
    return data["plugin"], out

diffs = []
plugins = set()
for scenario in ("untouched", "configured"):
    p_before, before = snapshot(scenario, "before")
    p_after, after = snapshot(scenario, "after")
    plugins.add((p_before, p_after))
    for area in before:
        for item in sorted(before[area] - after[area]):
            diffs.append(f"{scenario} {area} -{item}")
        for item in sorted(after[area] - before[area]):
            diffs.append(f"{scenario} {area} +{item}")

if plugins != {("romcommerce-previous", "romcommerce")}:
    print(f"::error::Expected the previous release before and this build after, got {plugins}; the update swap didn't take effect.")
    sys.exit(1)

allowed = {}
for raw in open("bin/upgrade-check-allowlist.txt", encoding="utf-8"):
    entry = raw.split("#", 1)[0].strip()
    if entry:
        allowed[entry] = False

failures = []
for d in diffs:
    key = f"{previous_tag} {d}"
    if key in allowed:
        allowed[key] = True
    else:
        failures.append(key)

stale = [k for k, seen in allowed.items() if not seen]

for f in failures:
    print(f"::error::Unapproved change on update: {f}")
for k in stale:
    print(f"::error::Allowlist entry no longer matches anything, remove it: {k}")

print(f"Upgrade check from {previous_tag}: {len(failures)} unapproved, {sum(allowed.values())} approved, {len(stale)} stale allowlist entries.")
sys.exit(1 if failures or stale else 0)
PY
