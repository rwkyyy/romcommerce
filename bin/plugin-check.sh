#!/usr/bin/env bash
# Runs wp.org's Plugin Check against the built release copy inside wp-env and
# fails on any error or warning not listed in bin/plugin-check-allowlist.txt
# (an error only with an explicit ERROR entry there).
# Needs a running wp-env (`npm run env:start`).
set -euo pipefail

cd "$(dirname "$0")/.."

bash bin/build-dist.sh

results="$(npx wp-env run cli -- wp plugin check romcommerce \
	--format=strict-csv --fields=file,line,type,code,message --include-experimental 2>/dev/null \
	| grep -vE '^(ℹ|✔)' || true)"

python3 - "$results" <<'PY'
import csv, io, sys

rows = list(csv.DictReader(io.StringIO(sys.argv[1])))
allowed = {}
for raw in open("bin/plugin-check-allowlist.txt", encoding="utf-8"):
    entry = raw.split("#", 1)[0].strip()
    if entry:
        allowed[entry] = False

failures = []
for row in rows:
    key = f"{row['file']},{row['code']}"
    if row["type"] == "ERROR":
        key = "ERROR " + key
    if key in allowed:
        allowed[key] = True
        continue
    failures.append(f"{row['type']} {row['file']}:{row['line']} {row['code']}\n    {row['message']}")

stale = [key for key, seen in allowed.items() if not seen]

for failure in failures:
    print(f"::error::{failure}")
for key in stale:
    print(f"::error::Allowlist entry no longer matches anything, remove it: {key}")

accepted = sum(allowed.values())
print(f"Plugin Check: {len(failures)} blocking, {accepted} accepted, {len(stale)} stale allowlist entries.")
sys.exit(1 if failures or stale else 0)
PY
