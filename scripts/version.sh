#!/usr/bin/env bash
# Version lives in four places that WordPress and GitHub each read on their own:
# the plugin header, the PHP constant, readme.txt's Stable tag and the top
# CHANGELOG entry. This script is the only thing allowed to change them.
#
#   scripts/version.sh check          # all four agree (and match the tag, if TAG is set)
#   scripts/version.sh bump 1.0.1     # rewrite all four, add a CHANGELOG heading
set -euo pipefail
cd "$(dirname "$0")/.."

MAIN=pushmails-for-wordpress.php

header()   { sed -nE 's/^ \* Version: +([0-9][^ ]*)$/\1/p' "$MAIN"; }
constant() { sed -nE "s/^define\( 'PUSHMAILS_WP_VERSION', '([^']+)' \);$/\1/p" "$MAIN"; }
stable()   { sed -nE 's/^Stable tag: +(.+)$/\1/p' readme.txt; }
changelog(){ sed -nE 's/^## \[([^]]+)\].*$/\1/p' CHANGELOG.md | head -1; }

case "${1:-}" in
check)
	h=$(header); c=$(constant); s=$(stable); l=$(changelog)
	if [[ "$h" != "$c" || "$h" != "$s" || "$h" != "$l" ]]; then
		echo "version mismatch: header=$h constant=$c readme=$s changelog=$l" >&2
		exit 1
	fi
	if [[ -n "${TAG:-}" && "v$h" != "$TAG" ]]; then
		echo "tag $TAG does not match version $h" >&2
		exit 1
	fi
	echo "$h"
	;;
bump)
	new=${2:?usage: version.sh bump X.Y.Z}
	[[ "$new" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "not a semantic version: $new" >&2; exit 1; }
	old=$(header)
	sed -i.bak -E "s/^( \* Version: +)$old$/\1$new/" "$MAIN"
	sed -i.bak -E "s/^define\( 'PUSHMAILS_WP_VERSION', '$old' \);$/define( 'PUSHMAILS_WP_VERSION', '$new' );/" "$MAIN"
	sed -i.bak -E "s/^Stable tag: $old$/Stable tag: $new/" readme.txt
	today=$(date -u +%Y-%m-%d)
	awk -v v="$new" -v d="$today" 'done==0 && /^## \[/ { print "## [" v "] — " d "\n\n- \n"; done=1 } { print }' CHANGELOG.md > CHANGELOG.md.tmp
	mv CHANGELOG.md.tmp CHANGELOG.md
	rm -f "$MAIN.bak" readme.txt.bak
	echo "$old → $new. Fill in CHANGELOG.md and readme.txt's changelog, then: git commit, git tag v$new"
	;;
*)
	echo "usage: $0 check | bump X.Y.Z" >&2
	exit 2
	;;
esac
