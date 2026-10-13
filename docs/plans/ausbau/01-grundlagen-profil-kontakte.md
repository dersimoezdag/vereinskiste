# Paket 1: Grundlagen, Vereinsprofil und Finanzkontakte

Abhängigkeit: vorhandene Vereinskiste. Ziel: gemeinsame Bausteine für alle Erweiterungen.

## G1 – Rollen und Navigation

- [ ] `Permissions` um `imports.read/write`, `invoices.read/write`,
  `contacts.read/write`, `contributions.read/write` und später `events.read/write` ergänzen.
- [ ] Admin/Vorstand erhalten sämtliche Rechte. Kassenwart: Finanzimporte,
  Rechnungen, Kontakte und Beiträge bearbeiten; Mitglieder lesen.
  Kassenprüfer: Finanzen/Imports/Rechnungen/Beiträge lesen, keine Mutationen.
  Schriftführer: Mitglieder/Anträge/Abteilungen pflegen; sensible Bankdaten
  und Finanzdokumente nur mit dem dazugehörigen Finanzrecht.
- [ ] Anträge verwenden `members.write`; Tarif-/Gruppenkonfiguration `settings.manage`.
- [ ] Neue Rechte getrennt an Services und Routen durchsetzen; ausgeblendete Links allein reichen nicht.
- [ ] Übersicht, Finanzen, Mitglieder und Einstellungen als klar gegliederte Navigation.
  Einstellungen: Version & Updates, Verein, Beiträge, Abteilungen, Protokoll.

## G2 – Gemeinsame Dokumente ohne Bruch bestehender Belege

Neue Komponenten: `Files/UploadValidator`, `StoredFileRepository`, `FileStorage`,
`DocumentAccess`; keine zweite Kopie der MIME-/Hash-/Pfadprüfung.

- [ ] `stored_files`: ID, zufälliger Dateiname, serverseitige Speicherwurzel,
  SHA-256, Originalname, MIME, Größe, Uploadbenutzer/-zeit und Verarbeitungsstatus.
- [ ] Bestehenden `ReceiptStore` zunächst an die gemeinsamen Datei-Helfer anbinden;
  seine fachlichen Sperren/Historien bleiben dort. Neue Rechnungen und Mitgliederdokumente
  referenzieren `stored_files`; bestehende Belege bekommen optional eine Dateireferenz.
- [ ] Altdateien in kleinen Schritten registrieren, ohne sie umzukopieren.
  Unterstützte Wurzeln sind feste interne Beleg-/Dokumentverzeichnisse, nie Benutzereingaben.
- [ ] Rollenprüfung über die referenzierende Rechnung/Buchung/Person, nicht nur über eine Datei-ID.
- [ ] PDF/JPEG/PNG und geprüfte Rechnungs-XML erlauben. Bankimporte separat behandeln.
  Dateigrößen pro Nutzung begrenzen; Bildpixel-Limit und sichere Downloads erhalten.
- [ ] Aufbewahrung/Löschung verwaister Uploads fachlich trennen: Entwürfe dürfen
  bereinigt werden, an bestätigte Finanzvorgänge gebundene Originale nicht automatisch.

## G3 – Ein gemeinsamer Hintergrundrahmen

- [ ] `jobs`: Typ, Status, minimale Payload, Versuche, frühester Lauf,
  Lease-Ende, Fortschritt, sichere Fehlerkennung, Idempotenzschlüssel.
- [ ] `Jobs/Runner`, je Aufgabe ein Handler; atomar per `FOR UPDATE SKIP LOCKED`
  beanspruchen, committen und erst danach Netzwerk/PDF/OCR bearbeiten.
- [ ] Bestehenden Mail-Cron in den gemeinsamen Runner überführen, ohne den
  account-unabhängigen öffentlichen Passwort-Reset-Pfad wieder aufzubrechen.
- [ ] Pro Lauf höchstens 20 Sekunden Gesamtbudget, pro Job begrenzte Arbeit;
  Mail und kurze interaktive Aufgaben bekommen Vorrang vor langen OCR-Jobs.
- [ ] Lease-Absturz/Retry, exponentielle Wartezeit und maximale Versuche;
  keine doppelten Mails/Beitragsforderungen/Buchungen bei Wiederaufnahme.
- [ ] Fortschritt in der UI per kurzer Statusabfrage. Keine Netzwerkoperation
  mit gehaltenem PHP-Session- oder Datenbanklock; Polling bevorzugen.

## G4 – Ein Vereinsprofil

- [ ] Singleton-Profil: Name, Anschrift, Kontaktadresse, Website, Logo,
  Dokumentlinks zu Satzung/Beitragsordnung/Datenschutz, Standard-Sprache,
  Geschäftsjahresbeginn und optional Vereinsfarbe.
- [ ] Den bisherigen `settings.club_name` verlustfrei übernehmen; eine Stelle
  für den Vereinsnamen in Installer, Berichten und Mails verwenden.
- [ ] `/settings/club` GET/POST, nur `settings.manage`; Logo mit gemeinsamer Dateiablage.
- [ ] Externe Dokumentlinks ausschließlich validierte HTTPS-Adressen.
  Server ruft solche Links nicht automatisch ab.
- [ ] Änderungen mit Benutzer und Pflichtgrund protokollieren; vorhandene Daten vorbefüllen.

## G5 – Finanzkontakte und Kontometadaten

- [ ] `finance_contacts`: Person/Firma, Anzeigename, Anschrift, E-Mail,
  Telefon, optionale Bankdaten, Aktivstatus und Version. Ein Name muss nicht eindeutig sein.
- [ ] Konten um optionale IBAN/BIC ergänzen; vorhandene Barkonten bleiben gültig.
- [ ] `/contacts`, Detail, Anlegen, Ändern, Archivieren; Suche und Export.
- [ ] Buchung/Rechnung bekommen optionale Kontaktreferenz plus historischen
  Namenssnapshot; bisheriger Gegenpartei-Freitext bleibt bei vorhandenen Buchungen erhalten.
- [ ] Verdächtige Ähnlichkeiten nur vorschlagen, nicht verschiedene Personen/Firmen automatisch vereinen.

## Abnahme

- [ ] Bestandsbelege nach Migration unverändert herunterladbar und gleiche SHA-256.
- [ ] Leser kann keine Datei über eine fremde Modulreferenz freischalten.
- [ ] Fehlender/abgebrochener Job wird einmalig fortgesetzt; keine SMTP-Sperre in HTTP.
- [ ] Vereinsname in allen bisherigen Exporten identisch; Profil und Kontakthistorie nachvollziehbar.
- [ ] Barkonto benötigt keine IBAN; ungültige IBAN wird nicht still korrigiert.

Geplante Orte: `app/src/Files`, `Jobs`, `Club`, `Contacts`, kleine Web-Controller,
`templates/settings`/`contacts`, neue SQL-Migrationen und gezielte Tests derselben Module.
