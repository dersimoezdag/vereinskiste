# Paket 7: Online-Beitritt und Benachrichtigungen

Abhängigkeiten: Vereinsprofil, Mitglieder/Verträge und Beitragsarten.
Ein öffentliches Formular für den Verein dieser Installation: `/beitreten`.

## P1 – Antrag statt unmittelbarer Aufnahme

- [ ] `membership_applications`: status `draft/submitted/in_review/accepted/rejected/withdrawn`,
  befristete Formularversion, gewünschter Beginn/Tarif, Ansprechpartner,
  begrenzte Personenangaben und Verwaltungsentscheidung/Grund.
- [ ] Personendaten/Einwilligungen als versionierte Antragsdaten; noch keine
  vollwertigen aktiven Mitglieder oder Beitragsforderungen beim öffentlichen POST.
- [ ] Einzel-/Familienformulare: Person(en), Kontakt, gewünschte Abteilungen,
  Beitragsvorschau und Zusammenfassung mit Pflichtfeldprüfung.
- [ ] Mitgliedschafts-/Gruppenlimits aus derselben Fachlogik wie interne Pflege.
- [ ] Öffentliches Formular aus Vereinsprofil gestalten: Name/Logo,
  Satzung/Beitragsordnung/Datenschutzlinks; Sprache schaltbar, kein Vereins-Slug.
- [ ] Bankdaten zunächst nur bei ausdrücklich aktivierter Erfassung;
  aus einer angegebenen IBAN entsteht kein SEPA-Mandat.

## P2 – Öffentlicher Schutz und Speicherung

- [ ] Formular-CSRF, serverseitige Längen-/Typvalidierung, Honeypot und
  gemeinsames IP-/globales Anfragebudget. Konten-/Mitgliederexistenz nicht offenlegen.
- [ ] Zufällige serverseitige Antragskennung; keine Namens-/E-Mail-URLs.
  Bei optionalen Zugriffstokens Hash speichern, Ablauf und Berechtigung begrenzen.
- [ ] Formularversion und bestätigte Dokumentversionen/Hashes speichern.
  Keine vorangekreuzte Medieneinwilligung; konkrete Zustimmungen getrennt.
- [ ] Zunächst keine öffentlichen Datei-Uploads. Bei späterem Nachweisupload
  nur über eigenen befristeten Ablauf mit der gemeinsamen Uploadprüfung.
- [ ] Doppelklick/Wiederholung idempotent; öffentliche Rückmeldung bleibt
  neutral. Entwürfe/abgelehnte Anträge nach Betreiber-Aufbewahrungskonzept bereinigen.

## P3 – Interne Bearbeitung

- [ ] `/members/applications`: Liste, Prüfung, mögliche Personendubletten,
  Rückfrage, Ablehnen und Aufnehmen; nur `members.write`.
- [ ] Aufnahme transaktional: geprüfte Personen anlegen/zuordnen, Vertrag,
  Zeiträume, Gruppen und Einwilligungen anlegen, Antrag abschließen.
- [ ] Sperre/Versionsprüfung verhindert zwei Aufnahmen desselben Antrags.
- [ ] Bereits vorhandene Personen nicht automatisch anhand E-Mail übernehmen;
  explizite interne Bestätigung mit nachvollziehbarer Entscheidung.
- [ ] Aufnahme startet keine automatische Abbuchung; Aufnahmegebühr/Beitrag
  laufen über das bestätigte Forderungsverfahren aus Paket 6.

## P4 – Mails

- [ ] Eingangsbestätigung an Ansprechpartner und interne Benachrichtigung;
  weitere Personen nur nach gewähltem Benachrichtigungsmodell.
- [ ] Zusage/Ablehnung/Rückfrage mit Vorschau und editierbarer Nachricht;
  keine unnötigen Bankdaten oder kompletten Antragskopien in Mails.
- [ ] Gemeinsame Outbox/Jobs statt SMTP im öffentlichen Request;
  Retry, Empfängersprache, Fehleranzeige und SMTP-aus-Zustand berücksichtigen.
- [ ] SMTP deaktiviert: Formular erklärt vor Abgabe, dass keine E-Mail-
  Bestätigung erfolgt; interner Antrag bleibt trotzdem sichtbar.

## Abnahme

- [ ] Öffentlicher Einzel-/Familienantrag, Fehler auf jedem Schritt,
  Back-/Forward-Navigation und 360-px-Bedienung ohne verlorene Eingaben.
- [ ] Kein öffentlicher Zugriff auf interne Liste/Antragsdetails/Personendaten.
- [ ] Doppelabgabe/parallele Aufnahme/Retry erzeugen genau einen bestätigten Vorgang.
- [ ] Einwilligungen und Dokumentversionen sind überprüfbar; Mails enthalten nur erforderliche Angaben.
- [ ] Aufnahme, Ablehnung, Rücknahme, SMTP-aus und Mailfehler vollständig bedienbar.

Geplante Komponenten: `Applications/ApplicationService`, `ApplicationRepository`,
`ApplicationAcceptance`, `PublicApplicationController`, interne Antragsseiten
und Mailtemplates. Keine Kopie der vorhandenen Mitgliederservices.
