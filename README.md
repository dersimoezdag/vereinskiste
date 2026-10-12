# Vereinskiste

Kassenbuch fuer kleine Vereine (PHP 8.1, Slim 4, PostgreSQL 15). Lizenz: AGPL-3.0, siehe `LICENSE`.

## Entwicklung

Auf dem Entwicklungsrechner werden nur Docker und Docker Compose benoetigt
(kein PHP, Composer oder PostgreSQL). `docker-compose.dev.yml` und `Dockerfile.dev`
dienen nur der Entwicklung und sind nie Teil des Release.

Tests ausfuehren:

    docker compose -f docker-compose.dev.yml run --rm php sh -c "cd app && composer install && vendor/bin/phpunit"

Nur ein Teil, z. B. die Migrationstests:

    docker compose -f docker-compose.dev.yml run --rm php sh -c "cd app && vendor/bin/phpunit tests/Db"

Aufraeumen: `docker compose -f docker-compose.dev.yml down -v`
