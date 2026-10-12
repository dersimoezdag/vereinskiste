#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
php "$root/scripts/verify-release.php" "${1:?Usage: verify-release.sh ZIP_FILE}"
