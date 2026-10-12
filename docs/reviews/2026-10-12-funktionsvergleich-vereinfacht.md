# Funktionsvergleich: Vereinskiste und vereinfacht

Stand: 12. Oktober 2026. Vergleich der lokalen Quellstände in
`C:/Users/Dersim/Entwicklung/vereinskiste` und
`C:/Users/Dersim/Entwicklung/vereinfacht`.
Quellenprüfung von Routen, Verwaltungsseiten, Modellen und Fachlogik;
vereinfacht wurde nicht gestartet oder verändert.

Vorgabe für die Vereinskiste: **Eine Installation = ein Verein.**

## Vergleich

| Funktion | vereinfacht | Vereinskiste heute |
|---|---|---|
| Benutzer und Rollen | Benutzer, Rollen und Berechtigungen im Vereinskontext | Benutzer, feste Vereinsrollen, Admin, Aktivstatus, Passwortwechsel und Reset |
| Vereinsprofil | Adresse, E-Mail, Website, Logo/Farbe und Dokumentenlinks | Vereinsname bei Einrichtung; kein vollständiger Profil-Editor |
| Mitglieder | Personenstammdaten, Kontakt, Geburtstag, Status, Sprache, Medieneinwilligung und Anhänge | Noch kein Modul; `members.read/write` sind lediglich vorbereitete Rechte |
| Mitgliedschaften | Eintritt/Ende, Status, verantwortliche Person, weitere Personen, Notizen und Bankdaten | Noch nicht implementiert |
| Online-Beitritt | Öffentliches mehrstufiges Formular, Mitgliedschaftsart, Personen, Abteilungen und Bestätigung | Noch nicht implementiert |
| Antragsbenachrichtigungen | Eingangsbestätigung und Nachricht an den Verein | SMTP bisher für Passwort-Reset |
| Abteilungen/Gruppen | Mitgliederzuordnung und abweichende Beiträge je Mitgliedschaftsart | Noch nicht implementiert |
| Beitragsarten | Aufnahmegebühr, Monatsbeitrag, freiwilliger Beitrag, Mindestdauer, Personen-/Abteilungsgrenzen und Zahlungsperioden | Noch nicht implementiert |
| Finanzkonten | Konten, IBAN/BIC, Anfangssaldo und Kontoauszüge | Bank-/Barkonten, Anfangssaldo und Eröffnungsdatum; keine IBAN/BIC-Felder |
| Finanzkontakte | Eigene Kontaktverwaltung und Zuordnung zu Belegen | Gegenpartei als Freitext an der Buchung |
| Kontoauszug-Import | CAMT- und MT940-Parser, Kontoauszüge und Banktransaktionen | Keine Importfunktion; Buchungen werden manuell erfasst |
| Beleg-/Zahlungsabgleich | Belege mit Betrag, Steuerkonto, mehreren Transaktionen und Vollständigkeitsstatus | Dateien an einer Buchung; kein separater Abgleich mit Banktransaktionen |
| Kategorien/Steuerkonten | Steuerkonten und Kontenrahmen | Frei pflegbare Kategorien; kein Kontenrahmen |
| Auswertung/Export | Einnahmen-/Ausgabenrechnung nach Steuerkonten und CSV-Exporte mehrerer Verwaltungslisten | Übersicht nach Zeitraum/Konto/Kategorie, Buchungs-CSV und Übersichts-PDF |
| Nachvollziehbarkeit | Für diesen Vergleich keine vollständige Prüfung der Historiengarantien | Änderungsverlauf, Pflichtgrund, Storno/Wiederherstellung, Korrektur-/Umbuchungen und Abschlusssperre |
| Mehrsprachigkeit | Sprachabhängige Oberfläche und übersetzbare Vereins-/Beitragsdaten | Deutsche Oberfläche |
| Betrieb | Laravel-API, Next.js-Frontend, MariaDB und Build-/Docker-Umgebung | PHP/PostgreSQL, gebündeltes Release, Web-Einrichtung, Migrationen und GitHub-Releaseprüfung |

## Wichtig: Beitragsarten sind keine vollständige Beitragserhebung

In vereinfacht sind Gebühren, Zahlungsperioden, Bankdaten und die Berechnung
des Monatsbeitrags belegt. Eine vollständige automatische Forderungserstellung,
Mahnlogik oder SEPA-Dateierzeugung wurde in den geprüften Anwendungspfaden
nicht gefunden. Diese Funktionen daher nicht als bereits fertige Vorlage
einplanen. Veranstaltungen wurden ebenfalls nicht als eigenes Fachmodul
identifiziert; sie stehen in unserem Entwurf als spätere Erweiterung.

## Übertragung auf einen einzelnen Verein

- Vereinsdaten einmalig unter Einstellungen speichern. Kein Vereinswechsel,
  keine Vereinsliste und keine mandantenbezogene Rollenzuordnung.
- Mitglieder, Mitgliedschaften, Abteilungen und Beitragsarten gehören direkt
  zur Installation. Keine `club_id`-Filter als künstliche Mandantenschicht.
- Mehrere Bank-/Barkonten und Abteilungen sind innerhalb dieses Vereins möglich.
- Ein öffentlicher Beitritt kann direkt unter `/beitreten` liegen; ein
  Vereins-Slug in jeder URL wird nicht benötigt.
- Benutzerkonten und Mitgliederstammdaten bleiben eigenständige Dinge;
  ein Mitglied muss kein Login besitzen. Eine spätere Verknüpfung ist möglich.
- Falls Familienmitgliedschaften benötigt werden, Personen und die gemeinsame
  Mitgliedschaft getrennt modellieren. Für reine Einzelmitgliedschaften zuerst
  einen einfachen Ablauf anbieten.
- Die bestehenden Regeln für Änderungsverlauf, Rollen, Geldbeträge und
  Abschlusssperren bei zusätzlichen Modulen weiterverwenden.
- Die Funktionen als fachliche Vorlage verwenden; die Umsetzung bleibt im
  vorhandenen PHP-/Twig-/PostgreSQL-Projekt und benötigt keine zweite Anwendung.

## Sinnvolle Reihenfolge

1. **Mitgliederverwaltung:** Stammdaten, Suche/Filter, Eintritt/Austritt,
   Wiedereintritt, Stichtagsabfrage, Verlauf und CSV-Export. Das entspricht
   der bereits vorgesehenen Phase 2.
2. **Vereinsprofil:** Kontaktdaten, Logo und Links zu Vereinsdokumenten als
   Einstellungsseite. Vorbedingung für einen brauchbaren öffentlichen Beitritt.
3. **Mitgliedschafts-/Beitragsarten:** Gebühren, Gültigkeitszeiträume und
   Zuordnung zu Mitgliedern. Forderungen und Zahlungszuordnung ausdrücklich
   als eigene anschließende Funktion planen.
4. **Online-Beitritt:** Antrag, Verwaltungsentscheidung, Einwilligungen und
   Eingangsbestätigung. Baut auf Mitgliedern und Mitgliedschaftsarten auf.
5. **Optional nach Bedarf:** Abteilungen, Finanzkontakte, Bankimport und
   Abgleich mit vorhandenen Buchungen. Beim Import Dubletten vermeiden;
   eine importierte Transaktion darf keine schon manuell erfasste Zahlung
   unbemerkt ein zweites Mal buchen.

Bankimport, SEPA und Kontenrahmen sind im bisherigen Entwurf ausdrücklich
nicht Teil der ersten Version. Ihr Fehlen ist eine Abweichung vom
Funktionsumfang des Vergleichsprojekts, kein unerledigter Phase-1-Task.

## Quellbelege

vereinfacht:

- `api/routes/api.php`: verfügbare Ressourcen, Import- und Exportrouten.
- `api/app/Models/{Club,Member,Membership,MembershipType,Division}.php`:
  Vereinsdaten, Mitgliedschaften, Gruppen und Beitragsberechnung.
- `api/app/Actions/Membership/ApplyMembershipAction.php` und
  `api/app/Listeners/SendApplicationNotifications.php`: Antrag und E-Mail-Fluss.
- `web_application/src/app/[lang]/[slug]/apply/`: öffentliches Beitrittsformular.
- `api/app/Models/{FinanceAccount,FinanceContact,Receipt,Transaction}.php`:
  Konten, Kontakte und Zuordnungs-/Vollständigkeitsstatus.
- `api/app/Services/Statement/StatementParserResolver.php`: CAMT/MT940.
- `api/app/Classes/FinancialStatement.php`: Auswertung nach Steuerkonten.
- `web_application/src/app/[lang]/admin/(secure)/`: Verwaltungsseiten.

Vereinskiste:

- `app/src/Http/Kernel.php` und `app/migrations/`: tatsächlich vorhandene
  Webmodule und Datenmodelle; aktuell keine Mitgliedertabelle/-routen.
- `app/src/Ledger/`, `app/src/Receipts/`, `app/src/Reports/`:
  Kassenbuch, Belege, Historie, Abschlüsse und Exporte.
- `docs/superpowers/specs/2026-10-12-vereinskiste-design.md`:
  vorgesehene Phasen und Ein-Verein-Vorgabe.
