# Paket 4: Zahlungsabgleich und Übernahme ins Kassenbuch

Abhängigkeiten: Bankimporte und Rechnungen. Wichtigster gemeinsamer Finanzbaustein.

## A1 – Eine Buchung je tatsächlichem Geldfluss

- [ ] `bank_booking_allocations`: Bankumsatz, Buchung, zugeordneter Betrag,
  bestätigender Benutzer/Zeit, Version und Status (aktiv/aufgehoben).
- [ ] `invoice_payment_allocations`: Rechnung, Buchung, Betrag, Status und Verlauf.
  Bankumsätze werden über ihre Buchungszuordnung angebunden; kein zweites,
  unabhängig gepflegtes System für denselben Zahlungsbetrag.
- [ ] Mehrere Teilzahlungen pro Rechnung, mehrere Rechnungen pro Zahlung und
  Aufteilungen eines Bankumsatzes in mehrere fachliche Buchungen unterstützen.
- [ ] Zugeordnete Summen dürfen weder den Bankumsatz noch die jeweilige Buchung
  überschreiten; Rechnungsüberzahlung getrennt als zu klärender Betrag anzeigen.
- [ ] Rechnungs- und Beitragszuordnungen gemeinsam gegen den verfügbaren
  Buchungsbetrag prüfen, auch wenn ihre Referenzen in getrennten Tabellen liegen.
  Normale Eingangsrechnungen erwarten Ausgaben, Beitragsforderungen Einnahmen;
  Gutschriften/Rückzahlungen benötigen den ausdrücklich passenden Sonderfall.
- [ ] DB-Constraints für Betragsvorzeichen/eindeutige IDs plus fachliche
  Summenprüfung unter Sperre. Festgelegte Sperrreihenfolge: Konto/Bankumsatz,
  Buchungen nach ID, Rechnungen/Forderungen nach ID.

## A2 – Vorschlagen und Bestätigen

- [ ] `/reconciliation`: nicht zugeordnete Umsätze, offene Rechnungen/Forderungen,
  Kandidaten, Restbeträge und abschließende Auswahl.
- [ ] Kriterien: Betrag/Richtung, Konto, Datumskorridor, IBAN/Kontakt,
  Rechnungs-/Mitgliedsreferenz und normalisierter Verwendungszweck.
- [ ] Treffer begründen und verlässlichkeitsabhängig sortieren; selbst ein
  eindeutiger Treffer wird zunächst vom Benutzer bestätigt.
- [ ] „Bestehende Buchung zuordnen“ als erster Weg bei bereits manuell erfassten Zahlungen.
- [ ] „Neue Buchung erstellen“ verwendet ausschließlich `BookingService`,
  einschließlich Eröffnungsdatum, Kategorie, Historie, Pflichtfeldern und Abschlusssperre.
- [ ] Korrekturen/Umbuchungen ausschließlich über ihre vorhandenen Fachservices;
  beide Seiten einer Kontoumbuchung müssen nachvollziehbar zugeordnet werden.
- [ ] Zugeordnete Originalrechnung als geschützte Belegreferenz nutzbar machen,
  ohne identische Dateien mehrfach zu speichern.

## A3 – Nebenläufigkeit, Rücknahme und Sonderfälle

- [ ] Bestätigung mit Versionsnummer und Idempotenzschlüssel; zweimaliger
  POST/Doppelklick/Retry erzeugt keine zweite Buchung oder Zuordnung.
- [ ] Zwei Benutzer auf demselben Umsatz → zweiter bekommt 409 und aktuellen Stand.
- [ ] Aufheben einer Zuordnung braucht Pflichtgrund; Historie bleibt erhalten.
  Bestehende Buchung nicht automatisch löschen oder ändern.
- [ ] Stornierte/geänderte Buchung macht betroffene Zuordnungen prüfbedürftig;
  der Status wird aus aktiven Daten neu berechnet. Keine still falschen „bezahlt“-Flags.
- [ ] Für abgeschlossene Zeiträume vorhandene passende Buchung zuordnen ist
  möglich, wenn kein Kassenbuchwert verändert wird; neue historische Buchung
  bleibt gesperrt. Korrektur/Reopening ist eine separate sichtbare Entscheidung.
- [ ] Gutschrift, Rückzahlung, Gebühren, Rabatt/Skonto, Sammelzahlung und
  Fremdreferenz jeweils gesondert abbilden; Differenz nicht still ausbuchen.
- [ ] Unbekannte Bankumsätze dürfen ungeklärt bleiben. Sie gehen nicht automatisch
  als „sonstige Einnahme“ oder Mitgliedsbeitrag ins Kassenbuch.

## Abnahme

- [ ] Manuell gebuchte Zahlung + nachfolgender Import: Zuordnung, keine Verdopplung.
- [ ] Zwei Teilzahlungen, Sammelzahlung, Gebührenrest, Rückzahlung und Storno konsistent.
- [ ] Parallelbestätigung/Retry/Aufheben/erneute Zuordnung mit unveränderlichem Verlauf.
- [ ] Kontosaldo wird ausschließlich aus aktiven Buchungen und Anfangssaldo berechnet.
- [ ] Bankauszug-Endsaldo und Kassenbuch-Saldo werden getrennt verglichen;
  Abweichungen erklären, nicht per Import automatisch korrigieren.

Geplante Komponenten: `Reconciliation/Matcher`, `AllocationService`,
`AllocationRepository`, kleine Änderungs-/Statushelfer und ein Web-Controller.
