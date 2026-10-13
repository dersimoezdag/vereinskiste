# CSV-Kontoauszug-Import

Lokal implementiert, noch nicht als Release veröffentlicht. Eine Installation verwaltet einen Verein.

## Bedienung

1. Im Menü **Kontoauszug-Import** öffnen und CSV hochladen.
2. Zielkonto auswählen. Die Datei braucht eine Kopfzeile und einen Vorzeichenbetrag:
   positive Werte sind Eingänge, negative Werte sind Belastungen.
3. Die Vorschau zeigt die ersten zehn Datenzeilen, soweit vorhanden. Spalten ausdrücklich
   zuordnen, Datums-/Zahlenformat wählen und das Zielkonto bestätigen.
   Optional lässt sich die Zuordnung als CSV-Profil speichern.
4. Vorprüfung über den bestehenden Cronjob abwarten oder jeweils 250 Zeilen per
   Schaltfläche verarbeiten. Fortschritt wird automatisch abgefragt.
5. Fehlerhafte Zeilen begründet ausschließen oder vor der ersten Übernahme die
   Spaltenzuordnung zurücksetzen. Alte Vorschläge und Zuordnungen bleiben erhalten.
6. Bankumsätze ausdrücklich in Abschnitten von höchstens 250 Zeilen übernehmen.
   Bei möglichen Dubletten pausiert die Übernahme für eine begründete Entscheidung.
   Bereits übernommene Abschnitte bleiben gespeichert; denselben Import fortsetzen.

Die Übernahme erstellt **keine Buchungen**. Kontosalden bleiben unverändert.
Der spätere Abgleich mit bestehenden oder neuen Buchungen gehört zu Paket 4.

## Unterstützter Umfang

- CSV mit Semikolon, Komma oder Tabulator; UTF-8 einschließlich BOM und Windows-1252.
- Datumsformate `TT.MM.JJJJ`, `JJJJ-MM-TT`, `TT/MM/JJJJ` oder `MM/TT/JJJJ`.
- Zahlenformat `1.234,56` oder `1,234.56`; keine Float-Berechnung.
- Buchungsdatum/Betrag verpflichtend; Valuta, Gegenpartei, Zweck, Währung,
  Gegenpartei-IBAN, eigene IBAN und Bank-ID optional.
- Nur EUR. Ohne zugeordnete Währungsspalte muss EUR ausdrücklich bestätigt werden.
- Eigene IBAN wird, sofern vorhanden, gegen die Kontometadaten geprüft.
  Ohne eigene IBAN in der Datei ist die manuelle Kontobestätigung erforderlich.
- Höchstens 10 MB, 10.000 Umsätze, 64 Spalten, 8 KB je Feld und 64 KB je CSV-Datensatz.
- Profile werden nur bei passender Kopfzeile vorbelegt; Zuordnung erneut prüfen.

## Dubletten und Sicherheit

Identische Dateien desselben Kontos führen zum vorhandenen Import. Eindeutige
bankseitige Einzelumsatz-IDs sind pro Konto einmalig; End-to-End-, Mandats- und
Sammelreferenzen dürfen dafür nicht verwendet werden. `NOTPROVIDED` und ähnliche
Platzhalter erzeugen keine eindeutige ID. Ohne ID dienen Inhaltsgleichheiten nur
als Hinweise; zwei tatsächlich gleiche Zahlungen dürfen bestätigt übernommen werden.

Bestätigung sperrt Konto und Import, prüft Kandidaten erneut und speichert die
Umsatzreferenz je Importzeile atomar. Stale Formularversionen werden abgelehnt.
Originale liegen privat unter `app/storage/documents`, mit SHA-256 und ausschließlich
über referenzgeprüfte Downloads. Rollen und CSRF schützen alle Schreibwege.
Das allgemeine Protokoll enthält IDs/Status, keine Rohdateien, IBANs oder Prüfgründe.

## Noch offen in Paket 2

CAMT.053, CAMT.054 und MT940 benötigen zunächst anonymisierte Bankexporte und eine
Supportmatrix. Sie werden ausdrücklich noch nicht unterstützt. PDF-Kontoauszüge
haben noch keinen eigenen Dokument-Upload. CSV liefert hier keine Anfangs-/Endsalden;
ein rechnerischer Saldenabgleich ist deshalb noch nicht verfügbar.

Für den nächsten produktiven Upgrade werden zusätzlich Migration 0010 und die neue
Datei-Endung `csv` benötigt. Vorher Datenbank, Konfiguration und gesamte private
Dateiablage sichern. Der bestehende Cron-Aufruf verarbeitet auch die CSV-Vorprüfung.
