# Funktionsüberschneidungen und deutsche Oberfläche

Ausgangsstand: main `ce525d1` vom 13.10.2026. Die gefundenen Überschneidungen wurden in PR #7 bereinigt.

## Vereinskasse

| Menübereich | Enthaltene Funktionen |
|---|---|
| Buchungen | Buchungsliste, Erfassung, Umbuchung, Korrektur; Tab für Kontoauszüge und Bankimport |
| Konten und Stammdaten | Tabs für Konten, Kategorien und Kontakte |
| Belege und Rechnungen | Einmalige Liste mit Statusfiltern, Belegupload, manuelle Entwürfe, Prüfung, Erstattungen und Zahlungszuordnung |

Der bisherige `/receipt-inbox` führt auf `/invoices?state=draft`. Die zweite Entwurfsliste und ihre Vorlage wurden entfernt. Abschlüsse bleiben unter Einstellungen; die Finanz-Auswertung bleibt unter Auswertungen. Die persönliche Übersicht verlinkt ihre Finanzkarte dorthin. Bestehende Fachfunktionen und URLs bleiben erreichbar; die Anzeige und Zugriffe beachten weiterhin die jeweiligen Berechtigungen.

## Gemeinsame Implementierung

- Übersicht, Kategorieauswertung und monatliche Finanzdiagramme teilen SQL-Summendefinitionen und den Ausschluss stornierter Buchungen und Umbuchungen. Die Übersicht lädt nur Summen, keine Korrekturhistorie.
- Rechnungsliste und Zahlungsdetail teilen die Berechnung bezahlter und offener Beträge aus aktiven Buchungen.
- Rechnungsoriginale erscheinen über die Zahlungszuordnung auch bei der Buchung. Dateien werden referenziert, weder kopiert noch erneut hochgeladen. Nur zugelassene Dokumente sind lesbar; ohne Rechnungsrechte wird ausschließlich das zugeordnete Original freigegeben. Entfernen der Zuordnung oder Sperren des Dokuments entzieht diesen Zugriff.
- Ungenutzte Startseitenmethode und alte Mailimport-Abhängigkeiten und Texte wurden entfernt. Es erfolgt weiterhin kein automatischer Belegimport aus E-Mails.

## Texte

Fehlende Mitgliedertexte, Auditbezeichnungen und veraltete Erstattungshinweise sind korrigiert. Dashboardbeschriftungen, Maskenhinweise, OCR- und Speichermeldungen, Brotkrumen sowie die statischen sichtbaren Texte der betroffenen Finanz-, Mitglieder- und Einstellungsseiten sind in den deutschen Katalog gezogen. Browsertexte werden als sicher kodiertes JSON aus diesem Katalog bereitgestellt; Theme-Bezeichnungen stammen aus der Auswahl.

Die Anwendung bleibt deutschsprachig. Die zuvor akzeptierte, aber wirkungslose Englischoption wird nicht mehr angeboten oder gespeichert. Ein englischer Katalog ist nicht Teil dieser Änderung.

## Absicherung

Katalogtests prüfen literale Twig-Schlüssel und doppelte Definitionen. Finanztests vergleichen gemeinsame Summen und Zahlungsstände, einschließlich Storno und aufgehobener Zuordnung. HTTP-Tests prüfen Menügruppierung, alte Belegeingangslinks und die beschränkten Dokumentrechte. Browsertests prüfen deutsche Masken, Dashboardreihenfolge, Theme-Auswahl und lokale OCR.
