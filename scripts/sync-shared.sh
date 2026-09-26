#!/usr/bin/env bash
# includes/shared is shared with PushMails for WooCommerce and edited ONLY here.
# The WooCommerce plugin carries a generated copy (its own text domain), because
# each plugin ships as a standalone zip.
#
#   scripts/sync-shared.sh            # write the copy into ../WooCommerce
#   scripts/sync-shared.sh check      # fail if the copy is out of date
#
# WOOCOMMERCE_DIR overrides where the WooCommerce plugin lives.
set -euo pipefail
cd "$(dirname "$0")/.."

src=includes/shared
dst=${WOOCOMMERCE_DIR:-../WooCommerce}/includes/shared

render() {
	local tmp=$1
	rm -rf "$tmp" && mkdir -p "$tmp"
	cp -R "$src"/. "$tmp"/
	find "$tmp" -type f \( -name '*.php' -o -name '*.js' -o -name '*.json' \) -print0 |
		xargs -0 sed -i.bak "s/pushmails-for-wordpress/pushmails-for-woocommerce/g"
	find "$tmp" -name '*.bak' -delete
}

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
render "$tmp/shared"

case "${1:-write}" in
write)
	[[ -d "$(dirname "$dst")" ]] || { echo "no WooCommerce plugin at $(dirname "$(dirname "$dst")")" >&2; exit 1; }
	rm -rf "$dst" && cp -R "$tmp/shared" "$dst"
	echo "copied $src → $dst"
	;;
check)
	if ! diff -r "$tmp/shared" "$dst" >/dev/null 2>&1; then
		echo "$dst is out of date: run scripts/sync-shared.sh in pushmails-wordpress" >&2
		exit 1
	fi
	echo "shared code in sync"
	;;
*)
	echo "usage: $0 [write|check]" >&2
	exit 2
	;;
esac
