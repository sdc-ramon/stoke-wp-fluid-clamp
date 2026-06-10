#!/usr/bin/env bash
#
# build.sh — Package the plugin into stoke-fluid-clamp.zip
#
# Zips the contents of the root folder into stoke-fluid-clamp.zip,
# excluding version control, the build script itself, and any
# previously generated archive.

set -euo pipefail

# Resolve the directory this script lives in, so it works from anywhere.
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

ZIP_NAME="stoke-fluid-clamp.zip"

# Remove a stale archive so we always get a clean build.
rm -f "$ZIP_NAME"

zip -r "$ZIP_NAME" . \
  -x "*.git*" \
  -x "$ZIP_NAME" \
  -x "build.sh" \
  -x "*.DS_Store"

echo "Created $ROOT_DIR/$ZIP_NAME"
