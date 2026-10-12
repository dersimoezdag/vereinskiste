# Vereinskiste – Entwurf

Stand: 2026-10-12 · Status: freigegeben durch Auftraggeber am 2026-10-12 (Frontend: React + MUI)

## 1. Worum es geht

Vereinskiste ist eine schlanke, quelloffene Web-Anwendung, mit der der
Vorstand eines Vereins seine **Finanzen** (Kassenbuch mit Belegen) und seine
**Mitglieder** (Stammdaten, Ein-/Austritte) verwaltet. Später kommen
**Veranstaltungen** und **Mitgliedsbeiträge** dazu, und eventuell
Mitglieder als eigene Nutzer.

Die Anwendung ist als Ideengeber vom bestehenden Projekt „vereinfacht"
inspiriert, teilt aber keinen Code und keine Architekturvorgaben mit ihm.

**Das Warum:** Der Vorstand soll Einnahmen und Ausgaben nachvollziehbar und
ohne Buchhaltungswissen führen können, Belege direkt an der Buchung haben und
der Kassenprüfung eine saubere Übersicht übergeben können. Die Buchhaltung ist
der schwierigste und wichtigste Teil.

## 2. Ziele, Nicht-Ziele, Anti-Ziele

**Ziele**
- Kassenbuch: Einnahmen und Ausgaben erfassen, kategorisieren, mit Belegen
  versehen, auswerten.
- Jede Änderung ist nachvollziehbar (Wer, Wann, Warum, vorher/nachher).
- Mitgliederverwaltung als eigenes Modul: Stammdaten sowie Ein- und Austritte.
- Läuft auf einem Server, auf dem nur PHP und PostgreSQL verfügbar sind.
- Installation und Updates per Web-Installer im Browser.
- Eine Installation = ein Verein.
- Open Source.

**Nicht-Ziele (zumindest für die erste Version)**
- Doppelte Buchführung, Bilanz, Kontenrahmen (SKR). Ein Kassenbuch reicht.
- Mandantenfähigkeit (mehrere Vereine in einer Installation).
- SEPA-Lastschrift, Bankanbindung, Kontoauszug-Import.
- Mitglieder-Selbstbedienung (kommt später, ist aber im Datenmodell nicht
  ausgeschlossen).
- Native Apps. Die Weboberfläche muss auf dem Handy brauchbar sein (Beleg
  fotografieren und hochladen).

**Anti-Ziele (wäre ein Fehlschlag, auch wenn es technisch funktioniert)**
- Buchungen lassen sich spurlos ändern oder löschen.
- Der Vorstand braucht Kommandozeile, Docker oder Node, um zu installieren
  oder zu aktualisieren.
- Ein Update lässt die Datenbank halb migriert zurück.

## 3. Phasen

1. **Kassenbuch**: Installer, Login, Rollen, Konten, Kategorien, Buchungen mit
   Belegen, Änderungsverlauf, Übersicht, Export.
2. **Mitglieder**: Stammdaten, Ein-/Austritt, Änderungsverlauf, Liste/Suche.
3. **Veranstaltungen und Beiträge**: Veranstaltungen als Auswertungseinheit
   über Buchungen, Beitragsarten und Zuordnung von Zahlungen zu Mitgliedern.

Dieser Entwurf beschreibt Phase 1 und 2 im Detail. Phase 3 ist nur als
Richtung vorgegeben; das Datenmodell darf sie nicht verbauen.

## 4. Fachliche Anforderungen

### 4.1 Kassenbuch

- **Konten:** mehrere Konten (z. B. Girokonto, Barkasse). Jede Buchung gehört
  zu genau einem Konto.
- **Buchung:** Datum, Betrag, Richtung (Einnahme/Ausgabe), Konto, Kategorie,
  Beschreibung, Gegenpartei (Freitext), optional Belegnummer und Belege.
- **Kategorien:** vom Verein pflegbar; Auswertung pro Kategorie.
- **Belege:** pro Buchung ein oder mehrere Dateien (PDF, Bild). Upload auch
  vom Handy. Belege werden nicht gelöscht, sondern nur der Buchung als
  „ersetzt" markiert.
- **Betrag:** exakte Dezimalarithmetik, keine Gleitkommazahlen. Währung in
  Phase 1 fest Euro.
- **Übersicht:** Einnahmen, Ausgaben und Saldo je Zeitraum, Konto und
  Kategorie. Export als CSV und als druckbare Ansicht/PDF für Kassenprüfung
  und Mitgliederversammlung.

### 4.2 Nachvollziehbarkeit

- Buchungen werden **nie gelöscht**. Korrigierbar ist eine Buchung, aber jede
  Änderung erzeugt eine neue Version; frühere Versionen bleiben einsehbar mit
  Benutzer, Zeitpunkt und einem **Pflichtgrund**.
- Pro Buchung gibt es einen **Änderungsverlauf direkt an der Buchung**. Jede
  Änderung (auch eine Korrektur wie ein Tippfehler direkt nach dem Buchen, und
  auch Storno oder Rücknahme eines Storno) erzeugt dort einen neuen Eintrag mit:
  - Änderung von (Benutzer),
  - Datum und Uhrzeit,
  - **was** geändert wurde (Feld, alter Wert → neuer Wert),
  - Grund (Pflichtfeld).
  Der Verlauf ist für alle mit Leserecht auf die Buchung sichtbar und kann
  nicht bearbeitet oder gelöscht werden.
- **Das Kassenbuch selbst bleibt übersichtlich:** In der Buchungsliste
  erscheint keine Änderungshistorie, nur der aktuelle Stand je Buchung (ein
  kleines Kennzeichen „geändert" mit Link zum Verlauf genügt). Der Verlauf
  öffnet sich in der Detailansicht der Buchung.
- **Zwei getrennte Dinge:**
  1. **Änderungsprotokoll** einer Buchung (Tippfehler, falsche Kategorie,
     Storno): gehört zur Buchung, nicht zur Buchungsliste.
  2. **Korrekturbuchung:** eine eigene, **sichtbare Buchung im Kassenbuch**
     mit eigenem Datum und Betrag, die die Bücher fachlich ausgleicht, z. B.
     Kassendifferenz nach einer Kassenzählung oder eine Korrektur nach dem
     Jahresabschluss. Sie kann optional auf eine Ursprungsbuchung verweisen
     und trägt eine Begründung.
- Eine Buchung kann **storniert** werden (bleibt sichtbar, zählt nicht mehr
  in Summen).
- Dasselbe Prinzip (versioniert, nicht löschend, mit Verlauf) gilt für
  Mitglieder-Stammdaten.
- Zusätzlich ein allgemeines Änderungsprotokoll (Anmeldungen, Rollen- und
  Einstellungsänderungen).
- *Offen, Entscheidung für den Builder zusammen mit dem Vorstand:* ob es einen
  Abschluss je Geschäftsjahr gibt, nach dem Korrekturen nur noch per
  Gegenbuchung möglich sind. Vorschlag: ja, als Phase-1-Funktion, einfach
  umsetzbar über ein „abgeschlossen bis"-Datum.

### 4.3 Mitglieder

- **Stammdaten:** Name, Anschrift, Geburtsdatum, E-Mail, Telefon, Notizen.
  E-Mail und Telefon sind optional.
- **Eintritt/Austritt:** jeweils mit Datum. Austritt löscht nichts; das
  Mitglied bleibt in der Historie. Wiedereintritt ist möglich.
- Stichtagsabfrage: „Wer war am Datum X Mitglied?"
- Liste mit Suche und Filter (aktiv/ausgetreten).
- Änderungsverlauf wie in 4.2.
- Phase 3: Beitragsarten, Beitragsforderungen und Zuordnung einer Buchung zu
  einem Mitglied. Im Datenmodell soll eine Buchung optional auf ein Mitglied
  verweisen können.

### 4.4 Rollen

| Rolle | Darf |
|---|---|
| Vorstand | alles, inkl. Benutzer, Rollen, Einstellungen |
| Kassenwart | Buchungen und Belege erfassen/korrigieren, Mitglieder lesen |
| Schriftführer | Mitglieder verwalten, Buchungen lesen *(Annahme)* |
| Kassenprüfer | nur lesen, inkl. Verlauf und Export |

Die genaue Rechtematrix ist eine Annahme und vom Vorstand zu bestätigen.
Mitglieder als Benutzer sind nicht Teil von Phase 1/2.

### 4.5 Datenschutz

Es werden personenbezogene Daten gespeichert (DSGVO). Phase 1/2 muss
mindestens bieten: Auskunft/Export der Daten eines Mitglieds,
Löschkonzept (Stammdaten anonymisierbar, soweit Aufbewahrungspflichten für
Buchungen dem nicht entgegenstehen) und keine Daten in URLs oder Logs.

## 5. Technische Vorgaben

Die Punkte 1–4 sind vom Auftraggeber vorgegeben, ab Punkt 5 sind es
Entscheidungen des Entwurfsautors, die der Builder begründet ändern darf.

1. **Laufzeit:** nur PHP und PostgreSQL auf dem Server. Kein Python, kein
   Docker, kein Node, keine Shell vorausgesetzt.
2. **Datenbank:** PostgreSQL. Migrationen laufen transaktional.
3. **Installation/Updates:** Web-Installer im Browser, inklusive
   Systemprüfung (PHP-Version, Extensions, Schreibrechte, DB-Verbindung) und
   Update-Lauf mit Migrationen.
4. **Eine Installation pro Verein**, kein Mandantenmodell.
5. **Anwendung (Auftraggeber-Entscheidung, 2026-10-12):** modernes Design mit
   **React und MUI (Material UI)** als Frontend. Das Frontend wird **lokal bzw.
   in der CI gebaut**; auf dem Server liegen nur die fertigen statischen
   Dateien. Node ist damit nur Entwicklungswerkzeug und wird auf dem Server
   nicht gebraucht. Das Backend ist ein schlankes PHP-Backend (kleines
   Framework oder Komponenten für Routing, Validierung, DB-Zugriff), das eine
   JSON-API, Session-Authentifizierung und geschützte Datei-Downloads
   bereitstellt. Kein Zwang zu Laravel. Konsequenz: Mitwirkende brauchen Node
   für die Frontend-Entwicklung; das Release-Paket ist der unterstützte Weg
   für Verein-Admins.
6. **Auslieferung:** Abhängigkeiten werden beim Release gebündelt
   (`vendor/` im Release-Paket), damit Upload per FTP genügt.
7. **Belege:** außerhalb des Webroots abgelegt, Zugriff nur über die
   Anwendung mit Rechteprüfung.
8. **Hintergrundaufgaben:** falls nötig per Cron oder beim Request, nichts
   verlangt einen dauerhaften Prozess.
9. **PDF:** reine PHP-Bibliothek, kein externes Programm.
10. **Mails:** über SMTP-Konfiguration des Vereins, nicht über `mail()`.
11. **Lizenz:** AGPL-3.0 (Vorschlag des Entwurfsautors; MIT ist die offenere
    Alternative).
12. **Name/Repo:** „Vereinskiste", Verzeichnis `vereinskiste`.

### 5a. Referenz-Zielumgebung

Der Verein nutzt **Hetzner Webhosting** (Tarif laut Auftraggeber „S", nicht
verifiziert). Laut Hetzner-Dokumentation bietet es PostgreSQL (derzeit 15,
Umstellung auf 17 angekündigt), MariaDB, wählbare PHP-8-Versionen und Cronjobs.
Vom Auftraggeber bestätigt (Auszug aus der Tarifbeschreibung): PostgreSQL
inkl. Erweiterungen, MariaDB, Redis, phpMyAdmin und ODBC-Zugriff sind im
Tarif enthalten.
Zu prüfen vor dem Bauplan: genaue PHP-Version im Tarif, Anzahl der
Datenbanken, SMTP-Versand, Ablage außerhalb
des Webroots (Hetzner-Konten haben ein Home-Verzeichnis oberhalb von
`public_html`, wahrscheinlich nutzbar). Der Builder soll gegen PostgreSQL 15
und die niedrigste angebotene PHP-8-Version entwickeln und testen.

### 5b. Grenzen des Tarifs „Webhosting S" (aus der Tarifbeschreibung des Auftraggebers)

Der Tarif ist damit bestätigt; die Vorbehalte in 5a zu Tarif und Postgres
entfallen. Eckdaten und ihre Folgen:

| Eckdatum | Folge für den Entwurf |
|---|---|
| PostgreSQL inkl. Erweiterungen, MariaDB | PostgreSQL ist nutzbar (siehe 5a) |
| **1 Datenbank** | Vereinskiste braucht genau eine; Tabellenpräfix oder eigenes Schema sinnvoll, falls die Datenbank mit anderer Software geteilt wird |
| **1 Cronjob** | Alle Hintergrundaufgaben laufen über einen einzigen Cron-Einstiegspunkt |
| **2 PHP-Prozesse** | Nur zwei gleichzeitige Anfragen: keine langen Requests, Exporte und PDFs in kleinen Schritten |
| **192 MB Memory-Limit** | Bild- und PDF-Verarbeitung speicherarm (Beleg-Fotos vor der Verarbeitung verkleinern, PDF streamend erzeugen) |
| 10 GB Speicher gesamt | Belege zählen dagegen; Hinweis auf Speicherverbrauch in der Oberfläche |
| **Kein SSH** (erst ab Tarif L) | Web-Installer und Web-Updater sind zwingend, kein CLI-Weg |
| PHP 5/7/8 wählbar | Entwickeln für die niedrigste PHP-8-Version; im Konto PHP 8 einstellen |
| SMTP (TLS/SSL), Mailboxen unbegrenzt | Mailversand ausschließlich über SMTP; Zugangsdaten im Installer |
| Domain nicht enthalten | Mailboxen und HTTP-Zugang brauchen eine gebuchte Domain |

**Mailversand:** E-Mails werden **ausschließlich per SMTP** versendet, nie
über PHP `mail()`. Ohne konfigurierten SMTP-Zugang bleibt die Anwendung
nutzbar; Funktionen, die Mail brauchen (z. B. Passwort-Reset per Mail),
stehen dann nicht zur Verfügung und die Oberfläche sagt das deutlich.

## 6. Qualität

- Automatisierte Tests für die Fachlogik (Salden, Versionierung, Storno,
  Stichtagsabfrage).
- Migrationstests gegen eine echte PostgreSQL-Instanz.
- Sicherheit: CSRF-Schutz, `password_hash`, Session-Härtung, Upload-Prüfung
  (Typ, Größe), Ausgabe-Escaping.
- Deutsche Oberfläche; Texte so abgelegt, dass eine Übersetzung später
  möglich ist.

## 6a. Festlegungen des Entwurfsautors (Entscheidungen, nicht vom Auftraggeber bestätigt)

Diese Punkte wurden vom Entwurfsautor nach dem Builder-Check entschieden und
sind als „mein Vorschlag" zu lesen.

- **Mitglied-Verknüpfung:** Phase 1 hat keine Verknüpfung zu Mitgliedern. Die
  nullable Spalte `member_id` an der Buchung wird in einer Phase-2-Migration
  ergänzt. Bei Anonymisierung bleibt die Mitglieds-ID als Pseudonym erhalten;
  nur die personenbezogenen Felder werden überschrieben.
- **Storno:** Storno ist ein **Zustand** der Buchung und erscheint als Eintrag
  im Änderungsverlauf der Buchung (mit Benutzer, Zeitpunkt, Grund). Er kann
  vor Abschluss des Zeitraums zurückgenommen werden, auch das ist ein Eintrag
  im Verlauf.
  Nach Abschluss ist eine Buchung unveränderbar; Korrekturen erfolgen per neuer
  **Korrekturbuchung** (siehe 4.2). Korrekturbuchungen sind auch **vor**
  einem Abschluss jederzeit möglich, z. B. für Kassendifferenzen aus einer
  Kassenzählung. Eine Korrekturbuchung hat einen eigenen Buchungstyp und kann
  in der Übersicht gefiltert werden.
- **Abschluss:** in Phase 1 enthalten. Geschäftsjahr = Kalenderjahr
  (konfigurierbar später). Nur Vorstand darf das „abgeschlossen bis"-Datum
  setzen; Wiederöffnen ist möglich, mit Pflichtgrund und Protokolleintrag.
- **Konten:** mit Anfangssaldo und Stichtag, deaktivierbar. **Umbuchungen**
  zwischen Konten sind im Umfang (z. B. Bargeldabhebung) und zählen nicht als
  Einnahme oder Ausgabe in der Auswertung.
- **Rollen:** pro Benutzer mehrere Rollen möglich; Berechtigungen ergeben sich
  aus der Vereinigung. Rechtematrix aus 4.4 ist fest vorgegeben, vom Verein
  nicht frei definierbar. Kassenprüfer sehen Mitgliederdaten nur lesend.
- **Löschwunsch (DSGVO):** Anonymisierung überschreibt personenbezogene Felder
  im Stammdatensatz **und** in dessen Versionsverlauf; der Vorgang wird
  protokolliert. Freitext in Buchungen und Belege werden nicht automatisch
  bereinigt, die Oberfläche weist darauf hin.
- **Belege:** Standard 10 MB je Datei, PDF/JPEG/PNG, Prüfsumme je Datei.
  „Ersetzt" heißt: alte Datei bleibt der alten Buchungsversion zugeordnet.
- **Anmeldung:** SMTP ist optional. Mit SMTP: Passwort-Reset per Mail. Ohne:
  Zurücksetzen durch einen Vorstand. Der erste Vorstand wird im Installer
  angelegt.
- **Updates:** Bei Abweichung zwischen Code- und DB-Version zeigt die
  Anwendung eine Wartungsseite statt zu laufen. Der Updater verlangt die
  Bestätigung, dass ein Datenbank-Backup existiert.

## 7. Dem Builder überlassen

- Wahl des Frameworks/der Bibliotheken innerhalb der Vorgaben.
- Genaues Datenbankschema und Versionierungsmuster.
- Aufbau der Oberfläche und des Designs.
- Struktur des Release-Pakets und des Updaters.
- Test- und CI-Setup.
