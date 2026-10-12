#!/bin/sh
set -eu
version=${1:?Usage: build-release.sh VERSION}
case "$version" in ''|*[!a-zA-Z0-9._-]*) echo 'Invalid version' >&2; exit 1;; esac
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
stage=$(mktemp -d)
trap 'rm -rf -- "$stage"' EXIT HUP INT TERM
mkdir -p "$stage/app/config" "$stage/app/storage" "$stage/public" "$root/dist"
cp "$root/app/composer.json" "$root/app/composer.lock" "$root/app/bootstrap.php" "$root/app/cron.php" "$stage/app/"
printf '%s\n' "$version" > "$stage/app/VERSION"
cp -R "$root/app/src" "$root/app/templates" "$root/app/migrations" "$stage/app/"
cp -R "$root/public/." "$stage/public/"
cp "$root/LICENSE" "$root/README.md" "$stage/"
mkdir -p "$stage/docs"
cp "$root/docs/hosting.md" "$stage/docs/"
composer install --working-dir="$stage/app" --no-dev --prefer-dist --no-interaction --optimize-autoloader
php "$root/scripts/package-release.php" "$stage" "$root/dist/vereinskiste-$version.zip"
php "$root/scripts/verify-release.php" "$root/dist/vereinskiste-$version.zip"
