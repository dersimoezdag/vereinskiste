# Paket 6: Beitragsarten, Forderungen und Zahlungszuordnung

Abhängigkeiten: Mitglieder/Verträge und gemeinsamer Zahlungsabgleich.
Ziel: echte Beitragserhebung, nicht nur ein Preisfeld auf dem Mitglied.

## C1 – Tarifmodell

- [ ] `membership_types`: Name/Beschreibung, Einzel/Familie, Mindestdauer,
  Mindest-/Maximalpersonenzahl, Gruppenlimits und Aktivstatus.
- [ ] `contribution_rates`: Beitragsart, Gültig-ab/bis, Grundbetrag,
  Aufnahmegebühr, Berechnungsbasis, Zahlungsrhythmus (monatlich/quartalsweise/jährlich).
- [ ] `division_contribution_rates`: Abteilung + Beitragsart + Gültigkeit + Betrag.
- [ ] `membership_tariffs`: Vertrag + Beitragsart + wirksamer Zeitraum,
  abweichender genehmigter Betrag/Befreiung und Pflichtgrund.
- [ ] Alte Tarifversionen nicht überschreiben. Überschneidende Gültigkeit
  unter Tarif-/Vertragssperre verhindern; Änderungen gelten für zukünftige Läufe.
- [ ] Freiwilliger Zusatzbeitrag explizit und widerrufbar je Vertrag;
  Gruppenbeitrag je tatsächlich zugehöriger aktiver Person berechnen.

## C2 – Festgelegte erste Berechnungsregeln

- [ ] Monat ist die kleinste Abrechnungseinheit. Eintrittsmonat standardmäßig
  bei Eintritt am Monatsersten berechnen, sonst ab dem Folgemonat;
  Aufnahmegebühr zum Eintritt fällig.
- [ ] Beitragsende mit dem letzten aktiven Monat; Austrittsdatum/Stichtag aus
  Paket 5 verwenden. Regel in UI und Tarifvorschau ausdrücklich anzeigen.
- [ ] Zahlungsrhythmus bündelt Monatsbeträge; Quartal/Jahr bestimmt nicht
  still einen anderen Monatsbeitrag. Optionale jährliche Pauschaltarife separat kennzeichnen.
- [ ] Spätere taggenaue anteilige Berechnung als ausdrückliche Tarifoption,
  mit einer einzigen Rundungsregel und eigener Abnahme hinzufügen.
- [ ] Familien-Grundbetrag einmal pro Vertrag; Gruppenbeiträge je Person;
  Grenzen/Wechsel zum betrachteten Monat prüfen. Verantwortliche Person ist kein Zusatzbeitrag.
- [ ] Tarife, Gruppenzugehörigkeit und Ausnahmen werden im Forderungssnapshot
  dokumentiert; spätere Änderungen verändern bestätigte Forderungen nicht.

## C3 – Vorschau und bestätigter Beitragslauf

- [ ] `contribution_runs`: Zeitraum, Status, Vorschauergebnis, Version,
  Ersteller/Bestätiger und Idempotenzschlüssel.
- [ ] `contribution_claims`: Vertrag, Leistungszeitraum, Art, Fälligkeit,
  exakter Betrag, Tarif-/Berechnungssnapshot, interne Referenz und Status.
- [ ] Eindeutiger Geschäftsschlüssel pro Vertrag/Zeitraum/Forderungsart;
  DB verhindert doppelte Monats-/Aufnahmeforderungen aus parallelen Jobs.
- [ ] `/contributions`: offene/überfällige Forderungen und Guthaben;
  `/contributions/runs/new` → Vorschau → ausdrückliche Bestätigung.
- [ ] Änderungen zwischen Vorschau und Bestätigung erkennen und Vorschau
  erneuern. Lange Läufe in begrenzten Schritten; bestätigte Teile wiederaufnehmbar.
- [ ] Korrektur durch Gutschrift/Storno mit Bezug und Grund, keine Löschung
  bereits bestätigter Forderungen. Nachberechnung separat vorprüfen.

## C4 – Zahlung und Kommunikation

- [ ] `claim_payment_allocations`: Forderung, aktive Buchung, Betrag,
  Bestätiger, Version und Aufhebungsverlauf; gemeinsame Summen-/Sperrlogik aus Paket 4.
- [ ] Offene/teilbezahlte/bezahlte/überfällige Zustände aus aktiven Forderungen
  und Zuordnungen ableiten. Guthaben, Mehrfach- und Familienzahlungen unterstützen.
- [ ] Zugeordnete Zahlung im Kassenbuch einmal erfasst; Forderungserstellung
  selbst verändert keinen Kontosaldo und ist keine Einnahme.
- [ ] Zahlungsmitteilung/Erinnerung als Vorschau mit Empfängerliste,
  konfigurierbaren Texten und bestätigtem Mailauftrag; keine automatische Gebühren-/Mahnlogik.
- [ ] Ohne SMTP PDF/CSV-Ausgabe möglich; Erneutsenden wird protokolliert,
  Retry desselben Mailauftrags sendet nicht unbemerkt doppelt.

## Abnahme

- [ ] Tarifwechsel, Eintritt/Austritt, Familiengrößenwechsel, Gruppenwechsel,
  Befreiung, Zusatzbeitrag und Monats-/Quartals-/Jahresgrenzen berechnet.
- [ ] Zwei parallele Läufe/Retry nach Absturz: genau eine Forderung pro Geschäftsschlüssel.
- [ ] Teil-/Überzahlung, Gutschrift, Zuordnungsrücknahme und Buchungsstorno konsistent.
- [ ] Keine Saldoänderung durch bloß erzeugte Forderungen; Exporte zeigen offene Beträge separat.

SEPA ist ein Folgepaket: Mandate, Gläubiger-ID, Mandatsreferenz, Vorabinformation,
geprüfte pain.008-Version und Bankfreigabe sind eigenständig erforderlich.
Für die hier geplante Beitragserhebung werden keine Lastschriften automatisch erzeugt.
