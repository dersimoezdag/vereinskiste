# Paket 3: Rechnungsimport, Texterkennung und optionale OCR

Abhängigkeit: Paket 1. Bestätigt sind **PDF/Bild + E-Rechnungen, OCR optional**.
Gemeint sind zunächst Eingangsrechnungen; eigene Ausgangsrechnungserstellung
ist ein separates Folgepaket, kein Teil des Uploads.

## R1 – Rechnung ist nicht gleich Belegdatei oder Zahlung

- [ ] `invoices`: Richtung/Dokumentart (Rechnung/Gutschrift), Lieferant/Kontakt,
  Rechnungsnummer/-datum, Fälligkeit, Netto/Steuer/Brutto, Währung, Notiz,
  Status (`draft`, `reviewed`, `cancelled`), Benutzer/Zeit und Version.
- [ ] `invoice_lines`: Text, Menge, Einheit, Einzelpreis, Steuerklasse und Beträge;
  Summen mit exakter Arithmetik prüfen. Einfache Rechnung ohne Positionserfassung erlauben.
- [ ] `invoice_documents`: Rechnung + Originaldatei + Rolle (Original/Ergänzung).
- [ ] `extraction_runs`: Methode/Version/Status, begrenzte Vorschlagsdaten,
  Feldherkunft/Verlässlichkeit und sichere Fehlerkennung; Rohdaten nicht in Logs.
- [ ] `invoice_changes`: unveränderliche bestätigte Änderungen mit Pflichtgrund.
  Geprüfte Originale nicht überschreiben, Korrekturversion/Gutschrift verknüpfen.
- [ ] Zahlungsstatus aus Zuordnungen in Paket 4 berechnen; ein Upload erzeugt
  weder eine bezahlte Rechnung noch eine Kassenbuchausgabe.

## R2 – Upload und Prüfansicht zuerst

- [ ] `/invoices` mit Filtern offen/teilbezahlt/bezahlt/überfällig/Entwurf;
  `/invoices/import`, Detail/Prüfung, Korrektur und geschützter Dokumentdownload.
- [ ] PDF/JPEG/PNG/XML, maximal 10 MB/Datei, zunächst 20 Dateien je Sammelauftrag
  in einzeln wiederaufnehmbaren Jobs. Passwortgeschützte PDFs melden, Original sichern.
- [ ] Original zeigen, Felder daneben prüfen, Lieferant auswählen/anlegen,
  Pflichtfelder ergänzen, Betrag/Datum/Währung bestätigen. Unsichere Felder hervorheben.
- [ ] Datei-Hash verhindert Doppelimport desselben Originals. Lieferant + Nummer
  + Datum + Betrag ergibt zusätzlich einen Warnkandidaten, keine automatische Löschung.
- [ ] Bei Ersatz/Korrektur bleibt die ursprüngliche Rechnung erreichbar.

## R3 – Strukturierte E-Rechnungen

- [ ] XRechnung als UBL/CII anhand Root/Namespace/Profil erkennen; Parser getrennt halten.
- [ ] ZUGFeRD/Factur-X: eingebettetes Rechnungs-XML aus dem PDF extrahieren.
  PDF- und XML-Original gemeinsam aufbewahren; mehrere widersprüchliche Kandidaten stoppen.
- [ ] Unterstützte Profile/Versionen und Bundles bei Implementierung dokumentieren;
  veröffentlichte Beispiele als Fixtures verwenden. Keine beliebige XML als Rechnung akzeptieren.
- [ ] XML ohne externe Entities/DTD, externe Ressourcen oder XSLT-Zugriffe verarbeiten.
  PDF-Anhänge nach Anzahl/entpackter Größe begrenzen; niemals enthaltene Skripte ausführen.
- [ ] Technische Formatprüfung und fachliche Betrags-/Pflichtfeldprüfung getrennt anzeigen.
  „Ausgelesen“ bedeutet nicht „vollständig standardkonform validiert“.
- [ ] Vollständige Schematron-/Profilvalidierung im Entwicklungs-/CI-Prozess mit
  KoSIT-Referenzwerkzeugen vergleichen. Kein Java-Programm auf dem Hosting voraussetzen.
- [ ] Eine PHP-Lösung darf im Produkt nur die tatsächlich durchgeführten Prüfungen
  ausweisen; noch nicht unterstützte Profile erhalten einen klaren manuellen Prüfpfad.

## R4 – Normale PDFs und Bilder

- [ ] Reine PHP-PDF-Textextraktion als Adapter auswählen, Lizenz und Grenzen prüfen,
  Composer-Abhängigkeit bündeln; keine Shell-Aufrufe zu `pdftotext` voraussetzen.
- [ ] Lieferant, Rechnungsnummer, Datum, Fälligkeit und Summen heuristisch vorschlagen.
  Jede Heuristik bekommt Herkunft; uneindeutige Summen nicht als bestätigt markieren.
- [ ] Kein brauchbarer PDF-Text/Foto → optionaler OCR-Auftrag oder manuelle Erfassung.
- [ ] Vorrechnung/Zahlungsaufforderung/Gutschrift nicht allein anhand eines gefundenen
  Betrags als normale Rechnung übernehmen. Dokumentart explizit prüfen.

## R5 – Optionales OCR-Paket

- [ ] OCR standardmäßig deaktiviert; PDF-/XML-Import und manuelle Erfassung bleiben nutzbar.
- [ ] `OcrProvider`-Vertrag: Auftrag übergeben, Status/Ergebnis abholen, Auftrag
  löschen; Textblöcke, Koordinaten und Verlässlichkeit, keine direkte Buchung.
- [ ] Vor Umsetzung eines konkreten Adapters Dienst auswählen: unterstützte Formate,
  Größen/Seiten, Datenschutzbedingungen, Region, Löschung, Preis und API-Limits
  anhand der Originaldokumentation prüfen. Dies ist eine Integrationsentscheidung,
  keine Annahme über vorhandene Hostingfähigkeiten.
- [ ] Entweder administrativ aktivierter externer HTTPS-Dienst oder optionaler
  eigener OCR-Server außerhalb des PHP-Webhostings. Anwendung selbst benötigt
  auch dann kein Tesseract/Python/Docker/SSH.
- [ ] Zugangsdaten privat speichern, nur `settings.manage`; Ziel fest konfigurieren,
  keine aus Rechnungen gelesenen URLs aufrufen. TLS-Prüfung nie abschalten.
- [ ] Vor externer Übertragung aktive Freigabe/klare Kennzeichnung im Produkt;
  unnötige Seiten/Daten nicht mitsenden. Kein stiller Wechsel zu einem Cloudanbieter.
- [ ] Asynchron mit kurzer HTTP-Frist, Retry/Lease, Idempotenz und Löschauftrag;
  Kosten-/Seitenbudget, deaktivierbarer Betrieb und sichtbarer Fehlerstatus.
- [ ] Ergebnisse bleiben Vorschläge; Kontonummern oder Beträge aus OCR sind
  kein Auslöser für Überweisung, Lastschrift oder automatische Kontaktdatenänderung.

## Abnahme

- [ ] PDF mit Text, Scan, Handyfoto, geschütztes PDF, defektes XML und valide
  UBL/CII/ZUGFeRD-Originale durchspielen; falsche Summen/Steuer-Rundungen sichtbar.
- [ ] Dieselbe Datei und dieselbe Rechnung mit neuem Dateinamen erzeugen Warnungen.
- [ ] OCR aus/fehlerhaft/abgebrochen/wiederholt funktioniert ohne Datenverlust oder doppelte Kostenaufträge.
- [ ] Prüfer kann nur lesen, Kassenwart bestätigen; kein unauthorisierter Originaldownload.
- [ ] Dokumente erzeugen vor Bestätigung/Zuordnung keine Buchungen oder Saldoänderungen.

Quellen: [FeRD: ZUGFeRD/Factur-X](https://www.ferd-net.de/standards/zugferd-faq),
[KoSIT-Validierung](https://github.com/itplr-kosit/validator),
[XRechnung-Konfiguration](https://github.com/itplr-kosit/validator-configuration-xrechnung).
