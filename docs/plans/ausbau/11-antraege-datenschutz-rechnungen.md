# Anträge, Datenschutz und Eingangsrechnungen

## Implementierter Umfang

- Eingangsbestätigung an Antragsteller und optionale interne Benachrichtigung.
  Die interne Adresse wird unter Einstellungen → Datenschutz hinterlegt.
- Zusage/Ablehnung über eine editierbare Nachricht und Vorschau. Entscheidung,
  Mitgliederanlage und Versandauftrag werden gemeinsam transaktional gespeichert.
- Gemeinsame SMTP-Outbox, Verarbeitung durch den bestehenden Cron, sichtbarer Status
  und ausdrücklicher erneuter Versand bei unklarer Zustellung. SMTP-Aus hält die
  Nachrichten vor; keine Bankdaten oder vollständigen Antragskopien in Standardmails.
- Bestätigungen mit Art, Text-/Link-Snapshot, Betreiber-Dokumentversion, Hash,
  Zeitpunkt und Herkunft; Aufnahme übernimmt sie auf das Mitglied. Widerrufe sind
  neue Ereignisse, keine Überschreibung. Satzung, Datenschutzhinweis und optionales
  SEPA werden getrennt gespeichert; Medien/Newsletter können intern dokumentiert werden.
- Datenschutzseite je Mitglied (Admin/Vorstand): JSON-Einzelauskunft und Vorschau
  einer unwiderruflichen Anonymisierung. Aktive Mitglieder, verknüpfte Benutzer und
  laufender Mailversand sperren diese. Der Betreiber muss zuerst die Dauer festlegen;
  es werden keine gesetzlichen Fristen im Code angenommen.
- Separate Anonymisierung abgelehnter Anträge nach der konfigurierten Dauer.
  Personenfelder, Bankdaten, Mailinhalte und persönliche Verlaufssnapshots werden
  tatsächlich entfernt. Einladungs-Titel und gespeicherte Passwort-Hashes werden
  entfernt und Links gesperrt. Bereits versendete E-Mails und externe Backups sind
  nicht nachträglich aus der Anwendung löschbar. Eigenständige Finanzkontakte,
  Buchungen und Rechnungen bleiben erhalten und benötigen ihren eigenen Ablauf.
- Eingangsrechnungen/Rechnungsgutschriften mit Entwurf, Prüfung und Storno, exakten
  Netto-/Steuer-/Bruttosummen, Lieferant, Datum, Fälligkeit, privaten Originalen,
  Versionskontrolle und unveränderlichem Verlauf. Geprüfte Werte werden nicht überschrieben.
- PDF/JPEG/PNG/XML, max. 10 MB je Datei und 20 je Upload. Jede Datei ist eine eigene
  Rechnung; Dokumente können an einen Entwurf ergänzt werden. Ein Fehler einer Datei
  wird separat angezeigt, erfolgreiche Imports bleiben erhalten. Original-Hash
  verhindert wiederholten Import; fachliche Dubletten werden vor Prüfung angezeigt.
- UBL Invoice/CreditNote und CII CrossIndustryInvoice liefern begrenzte, nach Herkunft
  gekennzeichnete Feldvorschläge. Verarbeitung mit LIBXML_NONET, ohne DTD/Entities,
  begrenzter Struktur; mehrdeutige Kandidaten werden nicht geraten. Vorschläge
  werden nur auf Benutzeraktion ins Formular übernommen und bleiben unbestätigt.
- Dokumentdownload nur bei fachlicher Referenz und invoices.read, keine öffentliche
  Dateiablage. Upload/Prüfung erzeugt weder Buchung noch Kontosaldoänderung.

## Grenzen und Folgepakete

- Keine automatische Löschung nach Ablauf einer Dauer. Jeder konkrete Vorgang braucht
  Vorschau und ausdrückliche Bestätigung; Aufbewahrungskonzept einschließlich Backups
  und eigenständiger Finanzunterlagen bleibt beim Betreiber.
- Ein Hash über angezeigten Text und hinterlegte URL ist kein Hash des Inhalts eines
  externen Satzungs-/Datenschutzdokuments. Die Betreiber-Version wird separat erfasst.
- Keine automatische PDF-Textextraktion, kein OCR-Cloudversand und kein Auslesen
  eingebetteter ZUGFeRD-/Factur-X-Anhänge. PDFs und Bilder werden manuell erfasst;
  unterstützte XML kann separat importiert werden. Keine vollständige KoSIT-/Schematron-
  oder EN16931-Konformitätsprüfung. Im Produkt wird ausschließlich Feld-Auslesung behauptet.
- Die erste Rechnungs-Prüfstufe unterstützt EUR. Andere Währungen bleiben Entwurf.
- Positionsweise Mengen-/Steuerprüfung, Zahlungszuordnung/Teilzahlungen, Kontenabgleich,
  Ausgangsrechnungen und die Verknüpfung von Korrekturrechnungen sind Folgeausbau.
  „Fälligkeit überschritten“ ist kein behaupteter Offene-Posten-/Zahlungsstatus.
- Versand nach einem unklaren SMTP-Ergebnis wird nicht automatisch wiederholt.
  Ein Betreiber muss vor erneutem Versand prüfen, ob bereits zugestellt wurde.

## Quellen für XML-Feldpfade

- OASIS UBL: https://docs.oasis-open.org/ubl/os-UBL-2.3/UBL-2.3.html
- KoSIT-Testfälle: https://github.com/itplr-kosit/xrechnung-testsuite

Diese Quellen werden zur Feldzuordnung verwendet, nicht als Nachweis vollständiger
Validierung der eigenen Implementierung.
