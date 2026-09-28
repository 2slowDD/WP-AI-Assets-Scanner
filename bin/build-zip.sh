#!/usr/bin/env bash
# Builds dist/dr-speed-ai-assets-scanner.zip for WordPress.org from an explicit allowlist.
# Anything not listed here (tests, Composer/npm files, Markdown, dotfiles) is never shipped.
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG=dr-speed-ai-assets-scanner
SHIP=(dr-speed-ai-assets-scanner.php uninstall.php readme.txt LICENSE admin includes)

rm -rf dist
mkdir -p "dist/$SLUG"
for path in "${SHIP[@]}"; do
	cp -R "$path" "dist/$SLUG/"
done

# Plugin Check rejects hidden files and mixed line endings in a release.
if find "dist/$SLUG" -name '.*' | grep -q .; then
	echo "hidden file in release:" >&2
	find "dist/$SLUG" -name '.*' >&2
	exit 1
fi
if grep -rlI $'\r' "dist/$SLUG" | grep -q .; then
	echo "CRLF line endings in release:" >&2
	grep -rlI $'\r' "dist/$SLUG" >&2
	exit 1
fi

(cd dist && zip -qr "$SLUG.zip" "$SLUG")
echo "dist/$SLUG.zip"
