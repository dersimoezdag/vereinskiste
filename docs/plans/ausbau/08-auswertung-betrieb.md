# Paket 8: Auswertungen, Sprachen, Veranstaltungen und Releases

Stufenweise ergänzen, sobald das jeweilige Fachmodul verfügbar ist.

## E1 – Auswertungen und zusätzliche Exporte

- [ ] Mitglieder: Stichtag, Ein-/Austritte, Beitragsart, Abteilung und CSV.
- [ ] Verträge/Beiträge: Forderungen, Teilzahlungen, offene Beträge und Guthaben;
  klare Trennung von Forderungsstatistik und tatsächlich gebuchten Einnahmen.
- [ ] Finanzen: unzugeordnete Bankumsätze, offene/überfällige Rechnungen,
  Differenzen zum Bankauszug und nachvollziehbare Zuordnungsketten.
- [ ] Bestehendes Buchungs-CSV und PDF erweitern, gemeinsame Filter/Export-
  Helfer nutzen; Geld-/Datumsdarstellung und CSV-Formelinjektionsschutz erhalten.
- [ ] Große Exporte streamend bzw. als begrenzter Job erzeugen; Ergebnisdatei
  befristet, privat und berechtigungsgebunden herunterladen.

## E2 – Optionale Steuerkonten

- [ ] Ein Kontenrahmen ist abschaltbar. Freie Kategorien funktionieren weiter.
- [ ] `tax_accounts`: Nummer, Bezeichnung, Typ, Aktivstatus und Rahmenversion.
  Mapping zu Kategorien optional; Buchung speichert tatsächlich angewandte Zuordnung.
- [ ] Einnahmen-/Ausgabenübersicht je Steuerkonto zusätzlich zum bisherigen
  Kategorienbericht; keine automatische Umstellung auf doppelte Buchführung.
- [ ] Datenbestand/Quellen/Lizenz vor Übernahme eines konkreten Kontenrahmens
  dokumentieren. Keine pauschale steuerrechtliche Eignungszusage durch die Software.
- [ ] Nicht zugeordnete Buchungen ausdrücklich ausweisen, nicht aus der Gesamtsumme verlieren.

## E3 – Mehrsprachigkeit

- [ ] Bestehendes `Text/Message` als gemeinsame Schnittstelle behalten;
  Kataloge nach Modul/Sprache aufteilen, statt `de.php` unbegrenzt zu vergrößern.
- [ ] Deutsch bleibt Standard, Englisch als erste zusätzliche Sprache;
  konfigurierter Fallback und Sprachwahl für Benutzer/öffentliche Formulare/Mails.
- [ ] Übersetzbare Profil-/Tariftexte mit neutralen IDs; keine zweite Tariflogik
  pro Sprache. Kein Float-Parsing abhängig von Browsersprache.
- [ ] Exporte, Fehlermeldungen, Statusnamen und Benachrichtigungen vollständig
  auf fehlende Schlüssel prüfen; sprachneutrale DB-Werte erhalten.

## E4 – Veranstaltungen als späteres eigenes Fachpaket

- [ ] `events`: Titel, Datum/Zeitraum, Ort, Notiz, Aktiv-/Abschlussstatus.
- [ ] Optionale Veranstaltung an Buchung referenzieren; bisherige Buchungen
  bleiben ohne Veranstaltung gültig. Einnahmen/Ausgaben je Veranstaltung auswerten.
- [ ] Teilnehmerliste als Verknüpfung zu Mitgliedern plus optionalen Gästen;
  Änderungen nachvollziehbar, Export berechtigungsgebunden.
- [ ] Veranstaltungsgebühren als Forderungen mit gemeinsamer Zahlungszuordnung,
  keine zweite Zahlungs-/Saldoverwaltung.
- [ ] Einfache interne Veranstaltungspflege zuerst. Ticketverkauf, öffentliche
  Anmeldung und Online-Zahlungsanbieter sind gesonderte spätere Entscheidungen.

## E5 – Betrieb und Updateprüfung vor neuen Modul-Releases

- [ ] Aktuelle pauschale GitHub-Fehleranzeige verbessern: HTTP 403/429,
  Timeout/TLS, deaktivierter Streamzugriff und ungültige Daten getrennt und
  ohne Zugangsdaten anzeigen. cURL-Transport als Hosting-Alternative einplanen.
- [ ] GitHub-Metadaten kurzzeitig gemeinsam cachen, keine Abfrage bei jedem
  Seitenaufruf; gesperrte/offline Quelle verhindert keine lokale Benutzung.
- [ ] Neue Migrationsdateien, VERSION, Release-Notizen und vollständiges ZIP
  zusammen aus einem eingefrorenen Quellstand bauen. Parallel arbeitende Chats
  dürfen keinen Dateibaum während einer Abnahme austauschen.
- [ ] Bei jedem größeren Modul: Neuinstallation, Update von 0.1.1, vorhandene
  Buchungen/Belege, einmalige Migration und Fehler-Rollback prüfen.
- [ ] Backup/Restore umfasst jetzt auch Originalimporte, Rechnungen,
  Mitgliederdokumente und Jobstände. Nach Restore öffentliche Tokens, Sitzungen
  und ausstehende Mail-/OCR-Aufträge kontrolliert verwerfen/pausieren.
- [ ] Adminseite zeigt Speicherverbrauch, Job-/Mailfehler und Importfortschritt;
  keine öffentlich erreichbaren Diagnose-/Cron-Endpunkte.

## Umsetzungspakete und Prüfbefehle

Für jedes Fachpaket erst fokussierte Tests, danach passende Regressionen;
vor Modul-Release vollständige Suite gegen eigene lokale Testdatenbank.
Die folgenden Befehle sind geplant und wurden für diesen Plan nicht ausgeführt:

```powershell
docker compose -f docker-compose.dev.yml run --rm php sh -c 'cd app && composer validate --strict && composer audit --locked && vendor/bin/phpunit'
pwsh -NoProfile -File scripts/check-backup-restore.ps1
docker compose -f docker-compose.dev.yml run --rm php sh -c 'sh scripts/build-release.sh VERSION'
```

Testdatenbank je Chat getrennt konfigurieren; niemals Produktivdaten zurücksetzen.
Für neue Imports anonymisierte Dateien mit erwarteten Ergebnissen beilegen.
Negative Fälle: beschädigte Datei, sehr große Struktur, unbekanntes Profil,
Fremdwährung, doppelte Zahlung, parallele Bestätigung, fehlendes Recht,
abgeschlossener Zeitraum und unterbrochener Hintergrundjob.

## Auslieferungsreihenfolge

- Finanz-Ausbau I: Profil/Kontakte + Bankimport-Vorschau.
- Finanz-Ausbau II: Rechnungsimport + bestätigter Zahlungsabgleich.
- Mitglieder-Ausbau: Personen/Verträge/Gruppen/Einwilligungen/Export.
- Beitrags-Ausbau: Tarife/Forderungen/Zahlungszuordnung.
- Beitritts-Ausbau: öffentliches Formular und Benachrichtigungen.
- Danach optionale OCR-Integration, weitere Bankvarianten, Steuerkonten,
  zusätzliche Sprachen und Veranstaltungsfunktionen nach eigener Abnahme.

Konkrete Versionsnummern erst pro freigegebenem Umfang vergeben. Ein
Versionssprung ist kein Ersatz für den dokumentierten Prüfstand.
