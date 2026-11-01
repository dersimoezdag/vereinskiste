# Veranstaltungen, Fördermittel und Buchungsimport

Lokal implementiert, noch nicht als Release veröffentlicht. Migration: `0019_events_funding_booking_import.sql`.
Setzt E4 aus [Paket 8](08-auswertung-betrieb.md) um und erweitert es um Vorlagen und Aufgaben.

## Veranstaltungen

- Menü **Veranstaltungen** mit den Reitern Veranstaltungen und Vorlagen.
- Budgetposten mit Plan-Betrag und Aufgaben mit Mitglied oder freier Person.
- Die Fälligkeit einer Aufgabe wird in Tagen relativ zum Beginn gespeichert (`-14` = zwei Wochen vorher).
- **Klonen** erzeugt aus einer Vorlage oder Veranstaltung eine neue Veranstaltung oder Vorlage. Mit kommen Ort,
  Notizen, Budgetposten und Aufgaben samt Personen. Nicht mit kommen Erledigt-Haken und Buchungen.
- Eine Buchung kann einer Veranstaltung zugeordnet werden. Der Ist-Wert wird aus aktiven Buchungen ohne
  Umbuchungen berechnet, nie gespeichert. Ist-Werte gibt es je Veranstaltung, nicht je Budgetposten.
- Veranstaltungen mit zugeordneten Buchungen oder Förderanträgen lassen sich nicht löschen, nur absagen.
- Aufgaben eines Mitglieds erscheinen in der Datenschutz-Einzelauskunft.

## Fördermittel

- Menü **Fördermittel**: Anträge mit Geldgeber, Status, beantragtem und bewilligtem Betrag, Einreichungs-,
  Entscheidungs- und Nachweisdatum, Posten und optionaler Veranstaltung.
- Eine Buchung kann einem Antrag zugeordnet werden. „Eingegangen“ wird daraus berechnet
  (Einnahmen minus Rückzahlungen).
- Es gibt keine automatischen Erinnerungen an Fristen.

## Buchungsimport

**Kassenbuch → Buchungsimport** übernimmt `.xlsx` oder CSV als echte Buchungen, anders als der
[Kontoauszug-Import](10-csv-import.md), der nur Bankumsätze ablegt.

1. Zielkonto und Datei wählen. Das Konto muss mit Anfangssaldo und Eröffnungsdatum bereits bestehen.
2. Tabellenblatt und Spalten prüfen. Die Zuordnung wird aus den Überschriften vorgeschlagen.
3. Vorschau prüfen: Bis zur Bestätigung entstehen keine Buchungen.
4. Bestätigen: Alle Zeilen werden in einer Transaktion über den `BookingService` angelegt.

Regeln der Vorschau:

- Zeilen ohne Betrag werden nie übernommen.
- Zeilen ohne eindeutiges Datum, vor der Kontoeröffnung oder im abgeschlossenen Zeitraum werden nur
  übernommen, wenn ein Datum eingetragen wird. So bleiben Zeilen wie „Start“ und „Gesamt“ draußen.
- Gleiche Buchungen auf dem Konto (Datum, Betrag, Richtung) sind als mögliche Dublette vorab ausgelassen.
- Zeilen lassen sich ausschließen: bei der Zuordnung über Zeilennummern (`4, 75-76`) oder in der Vorschau per Checkbox.
- Werte einer Spalte können vorhandenen oder neuen Veranstaltungen zugeordnet werden.

Formeln werden nicht ausgewertet, es zählt der von Excel gespeicherte Wert. Nach der Übernahme werden die
Tabellendaten gelöscht, nur Dateiname, Prüfsumme und Anzahl bleiben. Die Originaldatei wird nicht gespeichert.
Grenzen: 10 MB, 30 Blätter, 2.000 Zeilen je Blatt. Das alte Format `.xls` wird abgelehnt.
Der Excel-Import benötigt die PHP-Erweiterung `zip`, wie schon der DOCX-Import. Die Installationsprüfung
prüft sie; fehlt sie auf einem bestehenden Hosting, erscheint beim Upload ein Hinweis, und CSV funktioniert weiter.
Nicht übernommene Entwürfe löscht der Cronjob nach 30 Tagen.
