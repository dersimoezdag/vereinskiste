# Lokaler Entwicklungstest. Erstellt nur eigene, zufällig benannte Datenbanken.
$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$id = [Guid]::NewGuid().ToString('N')
$source = "vk_restore_${id}_source"
$restored = "vk_restore_${id}_restored"
$directory = "/work/dist/restore-$id"
$dump = "/tmp/vk_restore_$id.dump"
$created = @()
Push-Location $root
try {
    foreach ($database in @($source, $restored)) {
        docker compose -f docker-compose.dev.yml exec -T db createdb -U vk $database
        if ($LASTEXITCODE -ne 0) { throw 'Isolierte Datenbank konnte nicht angelegt werden.' }
        $created += $database
    }
    docker compose -f docker-compose.dev.yml run --rm -e "VK_TEST_DSN=pgsql:host=db;dbname=$source" php php app/tests/Support/backup_probe.php seed $directory
    if ($LASTEXITCODE -ne 0) { throw 'Testdaten konnten nicht erzeugt werden.' }
    docker compose -f docker-compose.dev.yml exec -T db pg_dump -U vk --format=custom --file=$dump $source
    if ($LASTEXITCODE -ne 0) { throw 'Dump fehlgeschlagen.' }
    docker compose -f docker-compose.dev.yml exec -T db pg_restore -U vk --exit-on-error --no-owner --dbname=$restored $dump
    if ($LASTEXITCODE -ne 0) { throw 'Restore fehlgeschlagen.' }
    docker compose -f docker-compose.dev.yml run --rm -e "VK_TEST_DSN=pgsql:host=db;dbname=$restored" php php app/tests/Support/backup_probe.php check $directory
    if ($LASTEXITCODE -ne 0) { throw 'Restore-Prüfung fehlgeschlagen.' }
} finally {
    foreach ($database in $created) { docker compose -f docker-compose.dev.yml exec -T db dropdb -U vk $database }
    docker compose -f docker-compose.dev.yml exec -T db rm -f -- $dump
    Pop-Location
}
