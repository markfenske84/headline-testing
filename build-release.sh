#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
SLUG="headline-testing"
VERSION="${1:-}"

if [[ -z "$VERSION" ]]; then
	MAIN="$ROOT/$SLUG.php"
	if [[ ! -f "$MAIN" ]]; then
		echo "Usage: $0 <version>   (or run from plugin root with a valid $SLUG.php header)" >&2
		exit 1
	fi
	VERSION="$(grep -m1 '^ \* Version:' "$MAIN" | sed 's/.*Version: //' | tr -d ' \r')"
fi

if [[ -z "$VERSION" ]]; then
	echo "Could not determine version." >&2
	exit 1
fi

OUT_DIR="$ROOT/dist/$SLUG"
ZIP_PATH="$ROOT/dist/$SLUG.zip"

rm -rf "$ROOT/dist"
mkdir -p "$OUT_DIR"

rsync -a \
	--exclude '.git' \
	--exclude 'dist' \
	--exclude '.DS_Store' \
	--exclude '*.zip' \
	--exclude 'build-release.sh' \
	"$ROOT/" "$OUT_DIR/"

rm -rf "$ZIP_PATH"
(cd "$ROOT/dist" && zip -r "$SLUG.zip" "$SLUG")

echo "Built $ZIP_PATH (v$VERSION)"
echo "Tag and publish:"
echo "  git tag v$VERSION && git push origin v$VERSION"
echo "  gh release create v$VERSION dist/$SLUG.zip --title v$VERSION"
