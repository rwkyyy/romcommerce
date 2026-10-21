#!/usr/bin/env bash
# Copies exactly what ships to .dist/romcommerce/, filtered through .distignore
# the same way 10up/action-wordpress-plugin-deploy filters the wp.org release.
# The wp-env test site mounts this copy, not the working tree, so Plugin Check,
# integration and browser tests all run against the real release contents.
#
# Also builds the latest released v* tag into .dist/romcommerce-previous/ for
# bin/upgrade-check.sh: the release before a version bump, or the current
# version's own release when the header hasn't moved since tagging. wp-env
# mounts it at start, so the directory must exist even when no tag is
# reachable (a shallow clone); the upgrade check then fails with a clear error.
set -euo pipefail

cd "$(dirname "$0")/.."

mkdir -p .dist/romcommerce
rsync -a --delete --exclude-from=.distignore ./ .dist/romcommerce/

previous="$(git tag --list 'v*' --sort=-version:refname | head -n1)"

# Synced in place, never removed: wp-env bind-mounts this directory, and a
# removed-and-recreated directory can't be deleted (or is no longer seen)
# while the container holds the mount.
mkdir -p .dist/romcommerce-previous
if [ -n "$previous" ]; then
	tmp="$(mktemp -d)"
	git archive "$previous" | tar -x -C "$tmp"
	rsync -a --delete --exclude-from=.distignore "$tmp"/ .dist/romcommerce-previous/
	rm -rf "$tmp"
	echo "$previous" > .dist/romcommerce-previous/.release-tag
else
	find .dist/romcommerce-previous -mindepth 1 -delete
fi
