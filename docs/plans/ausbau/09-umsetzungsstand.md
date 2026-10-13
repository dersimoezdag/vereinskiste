# Umsetzungsstand

## Implementierte Grundlagen

- Vereinsprofil unter Einstellungen → Verein: Stammdaten, Logo, HTTPS-Dokumentlinks,
  Sprache als Voreinstellung, Geschäftsjahresbeginn und Farbe als gespeicherte Einstellung.
  Berichte verwenden das Geschäftsjahr als voreingestellten Zeitraum.
- Finanzkontakte mit Suche, Anlage, Änderung, Archivierung, Dublettenhinweis und CSV-Export.
  CSV enthält alle Kontakte, ohne Bankdaten und interne Notizen.
- Optionale IBAN/BIC bei Konten und Kontakten; Format-/Prüfsummenprüfung.
- Kontaktzuordnung bei Buchungen mit historischem Gegenpartei-Namen.
- Pflichtgrund, private Änderungshistorie und Schutz vor überschriebenen Änderungen.
- Gemeinsame Datei- und Uploadprüfung statt doppelter Belegimplementierungen;
  bestehende Belege behalten ihre Dateien und Downloadwege.
- Dateiregistrierung, Referenzprüfung für Downloads und sichere XML-Grundprüfung.
- Gemeinsamer Job-Runner mit Priorität, Lease, Wiederaufnahme und begrenzten Versuchen;
  Passwort-Reset-Mail, Registrierung alter Belege und Bereinigung unregistrierter Uploads.
- Erweiterte Finanzrechte an den vorhandenen Rollen und geschützte neue Routen.
- Mitgliederverwaltung (Migration `0011_members.sql`): Mitgliederverzeichnis mit Suche und Filter,
  Stammdaten, zeitliche Mitgliedschaftsperioden (Eintritt/Austritt/Wiedereintritt ohne Überlappungen),
  Vereinsrollen (`club_roles` und `member_club_roles` für Funktionen wie Vorsitz/Kassenprüfer und
  Mitgliedsarten wie Aktiv/Fördermitglied mit automatischer Synchronisation) sowie optionale
  1-zu-1-Verknüpfung zwischen Systembenutzer und Mitglied.
- Online-Mitgliedsantrag per Einladungs-Link (`/antrag/{token}`): Erstellung geschützter Einmal-Links
  mit Gültigkeitsdauer, optionalem Passwortschutz (Argon2id) und konfigurierbarer Abfrage von
  Bankverbindung & SEPA-Lastschriftmandat (`require_sepa`). Prüfung und Genehmigung mit
  automatischer Mitglieder- und Periodenerstellung.

## Betrieb nach Veröffentlichung

Vor einem Upgrade Datenbank, Konfiguration und gesamte private Dateiablage sichern.
Die Migrationen 0008/0009 ergänzen die vorhandenen Tabellen; der Vereinsname wird übernommen.
Der vorhandene Cron-Aufruf bleibt bestehen. Pro Lauf werden höchstens 50 alte Belege
registriert, ohne Dateien zu verschieben. Neue Belege erhalten ihre Referenz sofort.
Nur unregistrierte Dokument-Uploads, die älter als 24 Stunden sind, werden bereinigt.
Registrierte Originale werden dabei nicht gelöscht. Logos liegen privat unter
`app/storage/documents`; dieses Verzeichnis muss in Datei-Backups enthalten sein.

## Noch offen

Paket 1 wird mit den jeweiligen Fachmodulen erweitert: Rechnungs-/Mitgliederdokumentrechte,
fachliche E-Rechnungsvalidierung, Statusabfragen für längere Importjobs und deren
idempotente Fachhandler. Die Sprach- und Farbeinstellung ist noch keine Übersetzung
oder vollständige Theme-Umschaltung. Beitrags-/Abteilungsseiten entstehen mit ihren Modulen.

Der CSV-Teil von Paket 2 ist inzwischen ebenfalls implementiert:
[Umfang und Bedienung](10-csv-import.md). CAMT/MT940 folgen mit geprüften Bankbeispielen.
Rechnungsimport einschließlich PDF/Bild und E-Rechnungen folgt in Paket 3;
optionale OCR benötigt die im Plan vorgesehene Anbieterentscheidung.


## Anträge, Datenschutz und Eingangsrechnungen

Die Module wurden um SMTP-Outbox und Entscheidungsvorschau, versionierte
Bestätigungen, Datenschutz-Einzelauskunft und bestätigte Anonymisierung sowie
Eingangsrechnungsimport mit manueller Prüfung und UBL/CII-Feldvorschlägen erweitert.
Der genaue Umfang und die Grenzen stehen in [Paketübersicht](11-antraege-datenschutz-rechnungen.md).
Die früheren Hinweise „Rechnungsimport folgt“ beziehen sich auf die noch offenen
PDF-/OCR-/ZUGFeRD-Auslesung und den Zahlungsabgleich, nicht auf die neue manuelle
Rechnungsverwaltung.
