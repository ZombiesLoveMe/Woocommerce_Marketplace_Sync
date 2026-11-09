#!/usr/bin/env bash
# Builds build/woo-etsy-sync.zip – the file you upload in WordPress (Plugins → Add New → Upload).
# Usage: bin/build-zip.sh [version] [owner/repo]
#   version     optional, e.g. 1.3.1 – written into the plugin header and readme
#   owner/repo  optional, e.g. callum/marketplace-sync – where the plugin checks for updates
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="${1:-}"
REPO="${2:-}"
SLUG="woo-etsy-sync"
rm -rf build && mkdir -p "build/$SLUG"
# Copy everything that belongs in the plugin (the same list .gitattributes keeps out of archives).
tar --exclude='./build' --exclude='./.git' --exclude='./.github' --exclude='./bin' \
    --exclude='./.gitattributes' --exclude='./.gitignore' --exclude='./README.md' --exclude='./docs' \
    --exclude='./node_modules' --exclude='*.zip' --exclude='.DS_Store' -cf - . | tar -xf - -C "build/$SLUG"
if [ -n "$VERSION" ]; then
  VERSION="${VERSION#v}"
  sed -i.bak -E "s/^( \* Version:[[:space:]]+).*/\1$VERSION/" "build/$SLUG/$SLUG.php"
  sed -i.bak -E "s/define\( 'WES_VERSION', '[^']*' \);/define( 'WES_VERSION', '$VERSION' );/" "build/$SLUG/$SLUG.php"
  sed -i.bak -E "s/^Stable tag: .*/Stable tag: $VERSION/" "build/$SLUG/readme.txt"
fi
if [ -n "$REPO" ]; then
  sed -i.bak "s#__GITHUB_REPO__#$REPO#g" "build/$SLUG/$SLUG.php"
fi
find "build/$SLUG" -name '*.bak' -delete
(cd build && zip -rq "$SLUG.zip" "$SLUG")
echo "Built build/$SLUG.zip"
