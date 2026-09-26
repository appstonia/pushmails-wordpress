# PushMails for WordPress
#
#   make check          versions agree, PHP files parse (if php is installed)
#   make zip            dist/pushmails-for-wordpress-<version>.zip
#   make bump V=1.0.1   rewrite the version everywhere
#   make sync           copy includes/shared into ../WooCommerce (edit shared code here only)
#
# Releasing: make bump V=x.y.z → edit changelogs → commit → git tag vx.y.z →
# git push --tags. The release workflow builds the zip from the tag and
# attaches it to GitHub Releases; the PushMails panel links to the latest one.

SLUG    := pushmails-for-wordpress
VERSION  = $(shell scripts/version.sh check)

.PHONY: check zip bump sync clean

check:
	@scripts/version.sh check >/dev/null && echo "version $$(scripts/version.sh check) consistent"
	@if command -v php >/dev/null 2>&1 && php -v >/dev/null 2>&1; then \
		find . -name '*.php' -not -path './build/*' -not -path './dist/*' -print0 | xargs -0 -n1 php -l >/dev/null && echo "php syntax ok"; \
	else echo "php not available: syntax check skipped"; fi
	@if [ -d ../WooCommerce/includes ]; then scripts/sync-shared.sh check; fi

# The zip holds a single top-level folder named after the slug: that is what
# WordPress expects from "Upload Plugin", and what keeps updates in place.
zip: check
	@rm -rf build/$(SLUG) && mkdir -p build/$(SLUG) dist
	@rsync -a --exclude-from=.distignore ./ build/$(SLUG)/
	@cd build && rm -f ../dist/$(SLUG)-$(VERSION).zip && zip -qr ../dist/$(SLUG)-$(VERSION).zip $(SLUG)
	@echo "dist/$(SLUG)-$(VERSION).zip"

bump:
	@scripts/version.sh bump $(V)

sync:
	@scripts/sync-shared.sh

clean:
	rm -rf build dist
