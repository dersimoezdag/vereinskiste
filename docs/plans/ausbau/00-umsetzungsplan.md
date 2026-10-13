# Vollständiger Umsetzungsplan: Ausbau der Vereinskiste

Plan erstellt am 12. Oktober 2026. Die Umsetzung hat mit den Grundlagen aus Paket 1 begonnen.
Aktueller Umfang und offene Erweiterungen: [Umsetzungsstand](09-umsetzungsstand.md).
Grundlage: [Funktionsvergleich](../../reviews/2026-10-12-funktionsvergleich-vereinfacht.md).
Bestätigt: **eine Installation = ein Verein**; Kontoauszug-Import und Import von
PDF-/Bild-/E-Rechnungen mit optionaler OCR. Jede Übernahme wird vorher geprüft.

## Verbindliche Entscheidungen

- PHP ab 8.4, Slim/Twig, PostgreSQL ab 15, gebündelte Abhängigkeiten/Assets.
- Eine Datenbank, ein gemeinsamer Cronjob, keine Shell-/Node-/Python-Pflicht auf dem Hosting.
- Bestehendes Kassenbuch bleibt die einzige Quelle für gebuchte Einnahmen/Ausgaben.
  Bankumsatz, Rechnung, Beitragsforderung und Buchung sind unterschiedliche Objekte.
- Beträge als Dezimalstrings/Integer-Cent, EUR als erste unterstützte Währung.
- Keine `clubs`-Mandantenverwaltung und keine `club_id`-Filter. Ein Vereinsprofil.
  Mehrere Konten, Abteilungen und Mitgliedschaftsarten gehören zu diesem Verein.
- Originaldateien privat und unverändert aufbewahren; erkannte Werte sind Vorschläge.
- Keine Buchung aus einem Import ohne bestätigte Übernahme oder Zuordnung.
- Veröffentlichte Migrationen nicht umschreiben. Neue Nummern beim Arbeitsbeginn
  nach dem dann aktuellen Stand vergeben; Dateisystemarbeiten nicht in SQL verstecken.
- Bestehende Rollen/Abschlüsse/Historien gelten auch für neue Finanzwege.
- Gemeinsame Validierung, Dateiablage, Transaktionen und UI-Teile verwenden.
  Services nach Fachaufgabe teilen; große Controller, Parser und Templates vermeiden.

## Aufwand und Schwierigkeit

Die Einstufung ist eine technische Einschätzung, keine Terminzusage.

| Paket | Schwierigkeit | Hauptaufwand |
|---|---|---|
| Kontoauszug-Upload + CSV/CAMT | Mittel | Varianten, Geld/Datum und Import-Vorschau |
| MT940 + Zahlungsabgleich | Hoch | Bankvarianten, fehlende IDs, Sammelumsätze, Dubletten |
| Rechnungsablage mit manueller Prüfung | Mittel | Dokumentmodell, Rechte und Zahlungsstatus |
| XML-E-Rechnungen/ZUGFeRD | Mittel bis hoch | Formatprofile, PDF-Anhänge und Validierung |
| PDF-Textauslesen | Mittel bis hoch | Unterschiedliche Layouts und unsichere Erkennung |
| Foto-/Scan-OCR | Hoch | Optionaler Dienst, Datenübertragung, Qualität und Hintergrundjobs |
| Mitglieder/Vereinsprofil/Kontakte | Mittel | Historie, Statuszeiträume, Filter und Datenschutzfunktionen |
| Beiträge/Familienmitgliedschaften | Hoch | Zeitabhängige Tarife, Forderungen und Teilzahlungen |
| Online-Beitritt | Mittel bis hoch | Öffentliche Eingaben, Spam, Entscheidung und Mailablauf |

## Reihenfolge und Abhängigkeiten

| Schritt | Arbeitspaket | Abhängigkeiten | Prüffähiges Ergebnis |
|---|---|---|---|
| 1 | [Grundlagen, Vereinsprofil, Kontakte](01-grundlagen-profil-kontakte.md) | Bestehende Anwendung | Profil-/Kontaktseiten, Dateiverträge und Jobrahmen |
| 2 | [Kontoauszug-Import](02-kontoauszug-import.md) | 1 | Upload → Vorschau → bestätigte Bankumsätze |
| 3 | [Rechnungsimport](03-rechnungsimport.md) | 1 | Dokument → Vorschläge → bestätigte Rechnung |
| 4 | [Abgleich und Buchung](04-abgleich-buchungen.md) | 2 + 3 | Keine Doppelbuchung; Teil-/Sammelzahlungen nachvollziehbar |
| 5 | [Mitglieder/Mitgliedschaften/Abteilungen](05-mitglieder-mitgliedschaften.md) | 1 | Mitgliederpflege, Historie, Stichtag und Export |
| 6 | [Beiträge und Forderungen](06-beitraege-forderungen.md) | 4 + 5 | Vorschau eines Beitragslaufs und Zahlungszuordnung |
| 7 | [Online-Beitritt und Benachrichtigungen](07-online-beitritt.md) | 5 + 6 | Antrag → Prüfung → Aufnahme; sichere Mails |
| 8 | [Auswertung, Sprachen, Veranstaltungen, Releases](08-auswertung-betrieb.md) | Stufenweise ab 1 | Modulübergreifende Auswertungen und betriebsfähige Pakete |

Finanzimporte haben hier die vom Auftraggeber gewünschte Priorität. Mitglieder
können danach umgesetzt werden, ohne die Imports auf Mitgliederdaten aufzubauen.
Jedes Paket enthält konkrete Aufgaben, Tabellen, Einstiegspunkte und Abnahmen.

## Vollständigkeit gegenüber dem Vergleich

Vereinsprofil/Kontakte: Paket 1. Kontodaten/Bankimport: 2. Rechnungen/Dokumente:
3. Beleg-/Zahlungsabgleich: 4. Mitglieder, Familien, Ein-/Austritt und Abteilungen:
5. Beitragsarten/Zahlungsperioden/freiwillige Beiträge: 6. Öffentliches Formular,
Einwilligungen und Mails: 7. Steuerkonten, zusätzliche Exporte, Mehrsprachigkeit,
Veranstaltungen und Betrieb: 8. Bestehende Benutzer-/Rollenpflege wird erweitert,
aber nicht als zweites Verwaltungsmodul neu gebaut.

SEPA-Dateierzeugung und vollautomatisches Banking sind ein ausdrückliches
Folgepaket mit eigener Freigabe; Bankdaten und Beitragsforderungen allein
rechtfertigen weder einen Einzug noch eine automatische Bankverbindung.
OCR ist optional und hat eine eigene Integrationsentscheidung in Paket 3.

## Allgemeine Fertigkriterien

- [ ] Zustände, Fehlerfälle, Rollen und Geld-/Datumskonventionen dokumentiert.
- [ ] Migration auf Bestandsdaten und erneuter Aufruf funktionieren; Fehler rollen zurück.
- [ ] Erfolgreicher Ablauf, bösartige Eingaben und Nebenläufigkeit sinnvoll geprüft.
- [ ] Mobile Bedienung ab 360 px, Labels, Tastatur, aktive Tabs und klare Meldungen.
- [ ] Kein verlorener Änderungsverlauf, keine neue öffentliche Dateiablage.
- [ ] Hintergrundjobs begrenzt, wiederaufnehmbar und mit bestehendem Cron verträglich.
- [ ] Installations-/Updateanleitung, VERSION und Release-Paket passend aktualisiert.
- [ ] Vor Veröffentlichung konkreten Prüfstand ausweisen. Der frühere Wunsch,
  einen Release-Testlauf abzubrechen, ist kein Verzicht auf die hier geplanten Abnahmen.

Die Umsetzung beginnt erst mit einem Umsetzungsauftrag. Dieser Plan ändert
keine produktiven Daten, veröffentlicht kein neues Release und aktiviert keinen Dienst.
