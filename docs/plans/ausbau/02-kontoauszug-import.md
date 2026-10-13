# Paket 2: Kontoauszug-Import

Abhängigkeit: Paket 1. Ziel: Bankdateien vorprüfen und Umsätze zuverlässig übernehmen.
Kein Online-Banking-Zugang und kein automatischer Zahlungseinzug.

Der CSV-Teil ist lokal implementiert. Details und verbleibende Grenzen stehen in
[CSV-Import](10-csv-import.md); CAMT/MT940 sind weiterhin offen.

## B1 – Datenmodell und Parservertrag

- [ ] `bank_imports`: Konto, Dateireferenz/Hash, Format/Version, Status
  (`uploaded`, `parsing`, `review`, `confirmed`, `failed`, `cancelled`), Benutzer,
  Fortschritt, Importversion und Zusammenfassung. Jeder Versuch bleibt nachvollziehbar.
- [ ] `bank_statements`: Konto, bankseitige Auszugskennung, Zeitraum,
  Anfangs-/Endsaldo, Währung und Quelle; ein Upload darf mehrere Auszüge enthalten.
- [ ] `bank_transactions`: Konto/Auszug, belastbare Bankreferenzen,
  Buchungs-/Valutadatum, Betrag/Richtung, Währung, Gegenpartei/IBAN,
  Verwendungszweck, Rohreferenz, Status und Version.
- [ ] `bank_import_rows`: Herkunft/Zeilennummer, normierter Vorschlag,
  Warnungen und Verweis auf übernommenen Umsatz oder geprüfte Dublette.
- [ ] DTOs `Statement`, `BankEntry`, `ParseIssue`; Parser liefern keine Buchungen.
- [ ] Parser nach Format in `BankImport/Parsers` teilen. Geld-/Datumsprüfung
  aus bestehenden Fachhelfern verwenden; XML/CSV/MT940 brauchen eigene Eingangsdecoder.

## B2 – Formate in umsetzbarer Reihenfolge

- [ ] CSV zuerst: Trennzeichen/Encoding erkennen, explizite Spaltenzuordnung,
  deutsche/internationale Datum-/Dezimalformate und wiederverwendbare Bankprofile.
  Vorschau von mindestens zehn Zeilen vor Speicherung des Profils.
- [ ] CAMT.053 anschließend: Namespace/Version erkennen, Einzel-/Sammelumsätze,
  Kredit/Belastung, Reversal und Salden lesen. Pro unterstützter Version eigene Fixtures;
  unbekannte Versionen ablehnen statt nach zufälligen Elementnamen zu interpretieren.
- [ ] CAMT.054 nur als weiteres erkanntes Format mit eigener Freigabe; nicht
  still als vollständigen Kontoauszug behandeln.
- [ ] MT940 danach: Datums-/Referenzfelder, Fortsetzungen und konkrete Bankvarianten
  mit echten anonymisierten Beispielen. Erst belegte Varianten freigeben.
- [ ] Supportmatrix vor CAMT-/MT940-Freigabe aus mindestens einem Export der
  tatsächlich verwendeten Bank festlegen. Ohne Beispiel CSV mit Zuordnung liefern.
- [ ] PDF-Kontoauszüge als Dokumente speicherbar, zunächst kein garantierter
  Umsatzimport daraus. Bankdateien in strukturiertem Format haben Vorrang.

## B3 – Bedienung und Fehlergrenzen

- [ ] `/imports/bank` Liste; `/imports/bank/new` Konto + Upload + Format;
  `/imports/bank/{id}` Vorschau/Status; POST für Mapping, Bestätigung und Abbruch.
- [ ] Kontonummer/IBAN abgleichen. Falsches Konto, Fremdwährung und ungültige
  Beträge/Datum sperren die Übernahme; Einzelzeilen können begründet ausgeschlossen werden.
- [ ] Buchungsdatum und Valuta getrennt anzeigen; Buchungsdatum als vorgeschlagener
  Kassenbuchtag. Kein Eröffnungsdatum automatisch verschieben.
- [ ] Start: maximal 10 MB pro Bankdatei, 10.000 Zeilen und 250 Zeilen je Schritt.
  Ergebnis/Payload-Limits getrennt prüfen; XML per XMLReader, CSV streamend lesen.
- [ ] XML: DTD/Entities/XInclude und Netzwerkzugriffe ausschließen. Keine URL-Imports.
  MT940/CSV-Zeilenlänge, Encoding und maximale Feldlängen vor DB-Eintrag prüfen.
- [ ] Vorschau zeigt Summen, Dubletten, ausgeschlossene Zeilen, Saldenabweichungen
  und die Folgeaktion. Die bloße Übernahme der Bankumsätze verändert keinen Kontosaldo.

## B4 – Dubletten und Wiederaufnahme

- [ ] Gleiche Datei pro Konto per Hash erkennen; erneuter Upload zeigt bestehenden Import.
- [ ] Belastbare Bank-ID mit Konto/Referenzart eindeutig in der DB; Platzhalter
  wie `NOTPROVIDED` nicht als globale eindeutige IDs verwenden.
- [ ] Ohne belastbare ID nur Kandidaten aus Datum/Betrag/IBAN/Verwendungszweck bilden.
  Zwei tatsächlich gleiche Zahlungen am selben Tag müssen unterscheidbar bleiben.
- [ ] Mengen-/Zeilenvergleich zwischen überlappenden Auszügen verwenden; zweifelhafte
  Dubletten vom Benutzer bestätigen lassen. Keine pauschale UNIQUE-Sperre auf einen Inhaltshash.
- [ ] Unter Konto-/Importsperre Kandidaten erneut prüfen; parallele Bestätigungen
  dürfen denselben bankidentifizierten Umsatz nicht zweimal anlegen.
- [ ] Bestätigung in kleinen atomaren Abschnitten, Idempotenzschlüssel je Zeile;
  Absturz nach einem Abschnitt setzt denselben Import ohne erneute Anlage fort.
- [ ] Fehlerformat, Kategorie und Fortschritt speichern, keine Rohkontoauszüge/IBANs loggen.

## Abnahme

- [ ] CSV mit Umlauten/BOM/Komma/Semikolon/positiven und negativen Beträgen korrekt.
- [ ] Unterstützte CAMT-/MT940-Beispiele inklusive Sammelumsatz und Jahreswechsel.
- [ ] Gleiche/überlappende Datei zweimal, zwei echte identische Zahlungen,
  fehlende IDs und parallele Bestätigung: keine unbemerkte Doppelübernahme.
- [ ] Abbruch/Retry/Fremdkonto/Fremdwährung/XML-Angriff/10.001 Zeilen sauber behandelt.
- [ ] Kleine Abschnitte passen unter 192 MB und blockieren die zwei PHP-Prozesse nicht dauerhaft.

Quelle für Formatdefinitionen: [ISO 20022](https://www.iso20022.org/iso-20022-message-definitions).
Die tatsächliche Parser-Versionenauswahl wird an Bankbeispielen geprüft, nicht
pauschal aus der neuesten ISO-Version abgeleitet.
