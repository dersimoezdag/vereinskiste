#!/bin/sh
set -eu
version=${1:?Usage: build-release.sh VERSION}
case "$version" in ''|*[!a-zA-Z0-9._-]*) echo 'Invalid version' >&2; exit 1;; esac
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
node "$root/scripts/vendor-browser-ocr.cjs"
node "$root/scripts/vendor-document-editor.cjs"
stage=$(mktemp -d)
trap 'rm -rf -- "$stage"' EXIT HUP INT TERM
mkdir -p "$stage/app/config" "$stage/app/storage" "$stage/public" "$root/dist"
cp "$root/app/composer.json" "$root/app/composer.lock" "$root/app/bootstrap.php" "$root/app/cron.php" "$stage/app/"
cp "$root/app/update-bootstrap.php" "$root/app/update-trust.json" "$stage/app/"
printf '%s\n' "$version" > "$stage/app/VERSION"
cp -R "$root/app/src" "$root/app/templates" "$root/app/migrations" "$stage/app/"
cp -R "$root/public/." "$stage/public/"
cp "$root/LICENSE" "$root/README.md" "$stage/"
mkdir -p "$stage/docs"
cp "$root/docs/rollen-und-identitaet.md" "$stage/docs/"
cp "$root/docs/dokumente.md" "$stage/docs/"
cp "$root/docs/hosting.md" "$stage/docs/"
cp "$root/docs/automatic-updates.md" "$stage/docs/"
mkdir -p "$stage/docs/plans/ausbau"
cp "$root/docs/plans/ausbau/09-umsetzungsstand.md" "$root/docs/plans/ausbau/10-csv-import.md" "$root/docs/plans/ausbau/11-antraege-datenschutz-rechnungen.md" "$stage/docs/plans/ausbau/"
composer install --working-dir="$stage/app" --no-dev --prefer-dist --no-interaction --optimize-autoloader
php "$root/scripts/package-release.php" "$stage" "$root/dist/vereinskiste-$version.zip"
php "$root/scripts/verify-release.php" "$root/dist/vereinskiste-$version.zip"
case "$version" in
  *[!0-9.]*|'') ;;
  *) if [ "${2:-signed}" != "unsigned" ]; then php "$root/scripts/sign-release.php" "$root/dist/vereinskiste-$version.zip" "$version"; fi ;;
esac
