# Prüfung auf Funktionsüberschneidungen und Übersetzungslücken

Geprüfter Ausgangsstand: `ce525d1` (main, 13.10.2026). Statische Prüfung der PHP-Dienste, Navigation, Twig-Schlüssel und deutschen Textkataloge; keine vollständige Prüfung sämtlicher Laufzeitkombinationen oder einer englischen Oberfläche.

## Korrigiert

- `members.list` fehlte im deutschen Katalog. Die Menü- und Brotkrumenansicht zeigte deshalb den Schlüssel. Twig `default` hilft hier nicht, da die Textauflösung einen fehlenden Schlüssel als nicht leeren Text zurückgibt. Schlüssel ergänzt und irreführende Fallbacks entfernt.
- Darstellungsauswahl und Hinweis-Komponente verwenden jetzt zentrale Textschlüssel. JavaScript liest die übersetzten Modusbezeichnungen aus der Auswahl, statt Hell/Dunkel/System erneut zu definieren.
- Die nicht mehr geroutete `AuthController::home()`-Methode wurde entfernt; die Übersicht wird ausschließlich vom Dashboard-Controller aufgebaut.
- Der seit Entfernung des Mailimports ungenutzte Verzeichniskonstruktorparameter im Belegeingangs-Controller und die alten `intake.mail.*`-Texte wurden entfernt.
- Auditbereiche wie `payment_policy` erhalten deutsche Fachbezeichnungen; die allgemeine Änderungsmeldung deckt auch Einstellungen ab.
- Der Erstattungshinweis verweist jetzt auf den eingereichten Beleg und nicht mehr auf eine automatisch eingegangene E-Mail.
- Die Startseite heißt konsistent „Übersicht“. Ihre Finanzkarte führt direkt zum bestehenden Finanzdashboard unter Auswertungen; Mitgliederkarten und Schnellaktionen bleiben nach ihren eigenen Berechtigungen erreichbar.
- Tests sichern fehlende literale Twig-Schlüssel und mehrfach definierte Katalogschlüssel ab.

## Verbleibende Übersetzungsthemen

1. Das Vereinsprofil akzeptiert `de` und `en`; der Kernel lädt standardmäßig immer `Text/de.php`. Ein englischer Katalog und die Umschaltung anhand der Profil-Sprache fehlen. Die aktuelle Anwendung ist dadurch faktisch deutschsprachig.
2. Dashboard-Katalog, Kennzahlenbeschreibungen, Zahlungsformulare, OCR-Statusmeldungen, Hilfetexte und weitere Brotkrumen enthalten fest eingetragene deutsche Texte. Sie sind auf Deutsch lesbar, können aber nicht über einen Textkatalog übersetzt werden. Diese Texte sollten systematisch in passende Bereichskataloge und an JavaScript übergebene Beschriftungen umziehen.

Die gefundenen literalen `t('…')`-Aufrufe haben nach der Korrektur einen deutschen Katalogeintrag; es wurden keine doppelt definierten Textschlüssel gefunden. Dynamisch zusammengesetzte Schlüssel und fest eingetragene Texte brauchen weiterhin eine separate Prüfung.

## Funktionsüberschneidungen

| Stellen | Einordnung | Sinnvolle nächste Änderung |
|---|---|---|
| Persönliches Dashboard und Finanz-Auswertung | Unterschiedliche Aufgaben: persönliche Übersicht versus Zeitraum-/Kategorieauswertung. Keine vollständig doppelte Funktion. | Seiten beibehalten. |
| Dashboard-Finanzkennzahlen und SummaryService | Einnahmen/Ausgaben/Saldo und Ausschluss von Umbuchungen werden separat berechnet. Das kann bei späteren Änderungen auseinanderlaufen. | Gemeinsame Aggregationsbasis mit leichtgewichtiger Summenabfrage für die Startseite; keine vollständige Auswertung mitsamt Korrekturhistorie auf der Startseite laden. |
| Payments::overview und InvoiceRepository::list | Der bezahlte/offene Betrag wird einmal in PHP und einmal per SQL aus aktiven Zuordnungen ermittelt. | Eine gemeinsame Zahlungsübersicht für Liste und Detailansicht vorsehen. |
| Belegeingang und Rechnungsentwürfe | Beide zeigen Rechnungsentwürfe, Belegeingang zusätzlich je Dokument. Mehrere Dokumente können dieselbe Rechnung mehrfach aufführen. | Belegeingang als dokumentorientierte Ansicht oder Tab der Rechnungsverwaltung klar abgrenzen und je Rechnung gruppieren. |
| Rechnungsbelege und Buchungsbelege | Getrennte Fachzuordnungen, beide nutzen bereits FileStorage/UploadValidator. Kein Grund, die Dateiprüfung erneut einzuführen. Bei Zahlungszuordnung erscheint der Rechnungsbeleg allerdings nicht automatisch in der Buchungs-Belegliste. | Rechnungsbelege über die Zahlungszuordnung in der Buchungsansicht referenzieren, statt dieselbe Datei erneut zu speichern. |
| Mitglieder-/Einstellungsmenü und Bereichstabs | Mehrere Wege führen bewusst zum selben Controller. Das ist Navigation, keine doppelte Fachimplementierung. | Keine Funktion entfernen; Zuständigkeit und Berechtigungen konsistent halten. |

Es wurden keine zwei unabhängig ausgelösten Banküberweisungen oder automatischen Doppelbuchungen aus der Rechnungsfreigabe gefunden: Die Freigabe erstellt keine Überweisung oder neue Ledger-Buchung. Zahlungszuordnung und Datei-Prüfsummen besitzen separate Dublettensicherungen.
