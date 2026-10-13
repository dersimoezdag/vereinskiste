# Paket 5: Mitglieder, Mitgliedschaften und Abteilungen

Abhängigkeit: Paket 1. Benutzerkonten bleiben getrennt von Mitgliederpersonen.
Ziel: Phase 2 vollständig umsetzen und Beiträge/Online-Beitritt vorbereiten.

## M1 – Personen und zeitliche Mitgliedschaft

- [ ] `members`: interne Mitgliedsnummer (eindeutig), Name, Anschrift,
  Geburtsdatum, optionale E-Mail/Telefon, bevorzugte Sprache, Notiz und Version.
  Gleiche Namen/E-Mails sind bei verschiedenen Personen zulässig.
- [ ] `memberships`: Einzel-/Familienvertrag, verantwortliche Person,
  Zahlungskontakt, Status, Tarifzuordnung, Notiz; Bankdaten getrennt und nur
  mit Finanzrecht abrufbar. Die Person selbst ist nicht der Vertrag.
- [ ] `membership_periods`: Person, Vertrag, Eintritt, Austritt und Grund.
  Eintritt/Austritt sind einschließlich; Stichtag D gilt bei Eintritt ≤ D
  und keinem Austritt oder Austritt ≥ D. Wiedereintritt beginnt einen neuen Zeitraum.
- [ ] Pro Person überlappende Mitgliedschaftszeiträume verhindern, unter
  Personensperre erneut prüfen. Ein Austritt löscht weder Person noch Verlauf.
- [ ] Wechsel zwischen Einzel-/Familienverträgen mit Datum und nachvollziehbarem
  Vorgänger/Nachfolger; bisherige Forderungen nicht umhängen.
- [ ] `member_changes`: Feld alt/neu, Benutzer/Zeit und Pflichtgrund;
  unveränderlich, mit optimistischer Versionsprüfung.
- [ ] Optionale `users.member_id`-Verknüpfung erst für einen späteren
  Mitgliederlogin nutzen; sie verleiht nicht automatisch neue Rechte.

## M2 – Oberfläche und Suche

- [ ] `/members`: Suche, aktiv/ausgetreten, Stichtag, Mitgliedschaftsart,
  Abteilung und Seitenwechsel; Detail, Anlegen, Ändern, Eintritt/Austritt/Wiedereintritt.
- [ ] `/memberships`: Verträge mit beteiligten Personen, Ansprechpartner und
  Beitragseinstellungen; Einzelmitgliedschaft als einfache voreingestellte Variante.
- [ ] Duplikatkandidaten anhand Name/Geburtstag/Kontakt vor Aufnahme zeigen;
  Zusammenführung nur explizit, mit Referenzübernahme und unveränderlichem Protokoll.
- [ ] Änderungsverlauf im Detail; keine Historientabelle in jeder Mitgliederliste.
- [ ] Mitgliederdokumente und Einwilligungen mit Paket-1-Dateirechten;
  Finanz-/Bankdaten nicht in allgemeine Mitgliederexporte mischen.
- [ ] Bestehende Rollen `members.read/write` wirklich mit Routen/Fachlogik verbinden.

## M3 – Abteilungen/Gruppen

- [ ] `divisions`: Name, Beschreibung, Aktivstatus; eindeutig ohne Groß-/Kleinschreibung.
- [ ] `division_memberships`: Person, Gruppe, Beginn/Ende, Version und Verlauf.
- [ ] `/settings/divisions` zur Konfiguration; Zuordnung auf der Mitgliedsseite.
- [ ] Archivieren statt Löschen verwendeter Gruppen; Mitgliedschaftswechsel
  beendet Gruppenzugehörigkeit nicht still ohne fachliche Entscheidung.
- [ ] Gruppenbeiträge in Paket 6, keine Geldberechnung in diesem Controller.

## M4 – Einwilligungen, Export und Anonymisierung

- [ ] `member_consents`: Art, zugestimmt/widerrufen, Dokumentversion/Hash,
  Zeitpunkt und Erfassungsweg; unterschiedliche Einwilligungen getrennt halten.
- [ ] Widerruf beendet die konkrete Nutzung, nicht automatisch die Mitgliedschaft.
- [ ] CSV für berechtigte Mitgliederlisten; Einzel-Auskunft mit Stammdaten,
  Zeiträumen, Gruppen und nachvollziehbaren Änderungen.
- [ ] Anonymisierung als administrativer Ablauf mit Vorschau und Pflichtgrund;
  Finanzreferenzen bleiben auf stabile interne IDs gebunden. Personenbezogene
  Historien und Dateien ausdrücklich in diesen Ablauf einbeziehen.
- [ ] Aufbewahrungsregeln je Dokument-/Datenart konfigurierbar; rechtliche
  Fristen vor Aktivierung durch den Betreiber festlegen, nicht im Code erfinden.
- [ ] Keine Klartext-Personendaten in URLs, technischen Fehlerlogs oder Jobkennungen.

## Abnahme

- [ ] Einzel-/Familienvertrag, mehrere Personen mit derselben E-Mail, Austritt,
  Wiedereintritt, Vertragswechsel und Stichtag an den Grenztagen korrekt.
- [ ] Parallele Änderungen ergeben 409; keine überlappenden Eintrittsintervalle.
- [ ] Schriftführer kann pflegen, Kassenwart lesen, Bankdaten bleiben separat geschützt.
- [ ] Mitgliedsliste/CSV/Einzelauskunft enthalten jeweils nur zugelassene Daten.
- [ ] Anonymisierung erhält Finanzintegrität und umfasst historische personenbezogene Kopien.

Geplante Komponenten: `Members/MemberRepository`, `MemberService`,
`MembershipService`, `MembershipPeriodRepository`, `ConsentService`,
`MemberExport`; `Divisions/DivisionService`; getrennte Web-/Twig-Seiten.
