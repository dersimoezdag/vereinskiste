# Mitgliedsantrag: frei festlegbare Erklärungen mit digitaler Unterschrift

Stand: 2026-11-03

## Ziel

Der öffentliche Mitgliedsantrag (`/antrag/{token}`) soll das Papierformular des Vereins
vollständig ersetzen. Der Vorstand legt im Backend fest, welche Erklärungen ein Antrag
enthält (z. B. Antrag auf Mitgliedschaft, Datenschutzhinweise, Foto-Einwilligung) und welche
davon unterschrieben werden müssen. Antragstellende unterschreiben mit Maus, Finger oder
Stift. Der Vorstand sieht jede Erklärung mit Version, Wahl und Unterschrift und kann den
Antrag als PDF im Stil des Papierformulars herunterladen.

Vereinskiste ist generisch: Kein Text ist vereinsspezifisch fest eingebaut. Das
Papierformular der FSI Jura Erlangen dient nur als Vorlage für den Standardsatz.

Zusätzlich wird das kaputte Layout der öffentlichen Antragsseite behoben.

## Nicht-Ziele

- Keine qualifizierte oder fortgeschrittene elektronische Signatur (eIDAS). Die Unterschrift
  ist ein Bild des gezeichneten Strichs mit Hash, vergleichbar mit einer eingescannten
  Unterschrift.
- Keine Speicherung von IP-Adressen oder Geräteinformationen zum Antrag.
- Das SEPA-Lastschriftmandat bleibt ein eigener Abschnitt mit IBAN/BIC und wird nicht zu einer
  frei festlegbaren Erklärung; es erhält keine Unterschrift.
- Kein gespeichertes PDF: Das PDF wird bei Bedarf aus dem gespeicherten Snapshot erzeugt.

## Begriffe

- **Erklärung:** ein vom Vorstand gepflegter Abschnitt des Antrags.
- **Version:** unveränderlicher Stand einer Erklärung. Jede Änderung erzeugt eine neue Version.
- **Snapshot:** die beim Absenden gespeicherte Kopie von Titel, Text und Version, die die
  Person tatsächlich gesehen hat.

## Erklärungen

### Felder

| Feld | Bedeutung |
|---|---|
| Titel | z. B. „Antrag auf Mitgliedschaft“, max. 200 Zeichen |
| Text | Rich Text (TinyMCE, bereinigt mit HTMLPurifier wie `ClubDocuments/Content`), max. 20 000 Zeichen |
| Art | `confirmation`, `consent` oder `notice` (siehe unten) |
| Unterschrift erforderlich | ja/nein |
| Reihenfolge | ganze Zahl, bestimmt die Position im Formular und im PDF |
| Aktiv | nur aktive Erklärungen erscheinen im Formular |

Der Platzhalter `{verein}` im Titel und Text wird beim Anzeigen und beim Snapshot durch den
Vereinsnamen ersetzt. Der Snapshot speichert den ersetzten Text.

### Arten

- **`confirmation` – Pflichtbestätigung:** Eine Checkbox mit festem Label „Ich bestätige diese
  Erklärung.“ muss angehakt sein, sonst wird der Antrag abgelehnt. Beispiel: Antrag auf
  Mitgliedschaft und Anerkennung der Satzung.
- **`consent` – freiwillige Einwilligung:** Zwei Radio-Buttons „Ich willige ein“ / „Ich willige
  nicht ein“. Eine Wahl ist Pflicht, keine ist vorausgewählt. Beide Antworten werden
  gespeichert. Beispiel: Kommunikation, Foto-Einwilligung.
- **`notice` – Hinweis:** nur Text, keine Checkbox. Wird mit „Unterschrift erforderlich“
  als Kenntnisnahme unterschrieben. Beispiel: Datenschutzhinweise nach Art. 13 DSGVO.

### Standardsatz

Migration und Neuinstallation legen vier Erklärungen an, wenn noch keine existieren.
Formulierungen nach dem Papierformular, vereinsspezifische Angaben als `{verein}` bzw. als
deutlich markierte Lücke, die der Vorstand ausfüllen muss:

1. „Antrag auf Mitgliedschaft“ – `confirmation`, Unterschrift ja
2. „Einwilligung zur vereinsinternen Kommunikation“ – `consent`, Unterschrift nein
   (auf dem Papier deckt die Unterschrift unter 1. diesen Abschnitt mit ab)
3. „Datenschutzhinweise gemäß Art. 13 DSGVO“ – `notice`, Unterschrift ja; der Text enthält
   die Lücken „[Verantwortlicher, Anschrift und E-Mail eintragen]“
4. „Einwilligung in die Veröffentlichung von Personenbildnissen“ – `consent`, Unterschrift ja

Die bisherigen festen Checkboxen „Satzung“ und „Datenschutz“ entfallen; ihr Inhalt steckt in
1. und 3. Bestehende Links aus dem Vereinsprofil (`constitution_url`, `privacy_url`) werden
in Erklärung 1 bzw. 3 als Link „Satzung lesen“ / „Datenschutzhinweise lesen“ übernommen,
sofern gesetzt.

### Datenmodell (Migration `0022_application_declarations.sql`)

```
application_declarations
  id, position int, active bool, created_at,
  current_version int       -- FK (id, current_version) auf die neueste Version, DEFERRABLE

application_declaration_versions          -- unveränderlich (Trigger wie club_document_versions)
  declaration_id, version, title, content (bereinigtes HTML),
  kind ('confirmation'|'consent'|'notice'), signature_required bool,
  actor_id, created_at
  PRIMARY KEY (declaration_id, version)

application_signatures
  id, application_id → membership_applications, declaration_id, version,
  granted bool NULL         -- NULL bei notice, true/false bei consent, true bei confirmation
  snapshot jsonb            -- {title, content, kind, version}
  snapshot_hash char(64),
  signature_png bytea NULL  -- nur wenn signature_required
  signature_sha256 char(64) NULL,
  signed_place text NOT NULL DEFAULT '', signed_at timestamptz NOT NULL
```

`member_consents.kind` wird um `declaration` erweitert und erhält eine nullbare Spalte
`declaration_id`. Bei der Genehmigung eines Antrags wird für jede `consent`- und
`confirmation`-Erklärung ein Eintrag in `member_consents` angelegt
(`source='application'`, Snapshot aus `application_signatures`). Ein späterer Widerruf
(z. B. Foto-Einwilligung) läuft über die bestehende Consent-Erfassung am Mitglied.

Die Versionsfelder `statute_version` und `privacy_version` in `/settings/privacy` werden für
neue Anträge nicht mehr verwendet; vorhandene `member_consents`-Einträge bleiben unverändert.

## Backend: Einstellungen

Neue Seite **Einstellungen → Mitgliedsantrag → Erklärungen** (`/settings/declarations`,
Berechtigung `settings.manage`), als Tab neben den Datenschutz-Einstellungen.

- Liste aller Erklärungen mit Reihenfolge (Auf/Ab-Schaltflächen), Art, Unterschrift ja/nein,
  aktiv/inaktiv und aktueller Version.
- „Neue Erklärung“ und „Bearbeiten“ öffnen ein Formular mit Titel, Art, Unterschrift
  erforderlich und TinyMCE-Editor. Speichern erzeugt eine neue Version, auch wenn sich nur
  die Art oder die Unterschrift-Option ändert. Unveränderte Eingaben erzeugen keine Version.
- „Vorschau“ zeigt die Erklärung so, wie sie im Formular erscheint.
- Löschen gibt es nicht; nicht mehr benötigte Erklärungen werden deaktiviert, damit alte
  Anträge auflösbar bleiben.
- Es muss mindestens eine aktive Erklärung geben; sonst zeigt `/antrag/{token}` die Meldung,
  dass der Antrag derzeit nicht verfügbar ist.
- Jede Änderung wird im Audit-Log protokolliert.

Der bestehende `document_fingerprint` des Formulars wird auf die IDs und aktuellen Versionen
aller aktiven Erklärungen (plus SEPA-Text) umgestellt. Ändert der Vorstand eine Erklärung,
während jemand das Formular ausfüllt, wird der Antrag wie heute mit Hinweis neu angezeigt.

## Öffentliches Formular

### Aufbau als Stepper

Das Formular ist ein Stepper mit einem Schritt pro Abschnitt. Technisch bleibt es **ein**
`<form>`: Die Schritte werden per JavaScript (`public/assets/application-stepper.js`) ein- und
ausgeblendet, es gibt keinen Serverabruf pro Schritt. Die Serverprüfung läuft wie bisher
einmal beim Absenden.

Schritte:

1. Persönliche Angaben und Anschrift (Felder wie heute)
2. SEPA-Lastschriftmandat – nur wenn im Einladungslink gefordert (wie heute)
3. Je aktiver Erklärung ein Schritt in der festgelegten Reihenfolge: Titel, Text aus dem
   Editor, Checkbox bzw. Radio-Buttons je nach Art, darunter das Unterschriftsfeld, falls
   gefordert
4. Abschluss: Bemerkungen, Zusammenfassung aller Erklärungen mit Wahl und verkleinertem
   Unterschriftsbild sowie „Ändern“-Link je Erklärung, Feld „Ort“ (Pflicht, sobald
   mindestens eine Erklärung eine Unterschrift verlangt), automatisch gesetztes Datum
   (Anzeige; gespeichert wird die Serverzeit beim Absenden), Absenden

Bedienung:

- Fortschrittsanzeige oben: „Schritt 3 von 7“ mit Schrittnamen; mobil nur Nummer, Name des
  aktuellen Schritts und Fortschrittsbalken.
- „Zurück“ ist immer möglich. „Weiter“ prüft den aktuellen Schritt im Browser (Pflichtfelder,
  Checkbox bzw. Wahl, Unterschrift nicht leer) und markiert fehlende Angaben, statt weiterzugehen.
- Beim Schrittwechsel wird zum Anfang des Schritts gescrollt und der Fokus auf dessen
  Überschrift gesetzt (Barrierefreiheit); der aktuelle Schritt steht in `aria-current`.
- Zeigt der Server das Formular nach einem Fehler erneut an, öffnet der Stepper den ersten
  Schritt mit Fehler; alle Fehlermeldungen stehen am jeweiligen Feld bzw. Abschnitt.
- Die Schrittnummer steht im URL-Fragment (`#schritt-3`), damit die Zurück-Taste des Browsers
  einen Schritt zurück führt statt die Seite zu verlassen.

### Unterschriftsfeld (`public/assets/signature-pad.js`)

- Weiße Fläche in voller Formularbreite, 160 px hoch, mit Grundlinie und Hinweis
  „Hier unterschreiben“; daneben „Löschen“.
- Zeichnen per Pointer Events (Maus, Finger, Stift); `touch-action: none` nur auf der Fläche,
  damit die Seite beim Unterschreiben nicht scrollt. Linien werden geglättet, die Fläche
  berücksichtigt `devicePixelRatio`.
- „Größer unterschreiben“ öffnet die Fläche als Vollbild-Dialog im Querformat; „Übernehmen“
  überträgt die Unterschrift zurück.
- Beim Absenden wird jede Unterschrift als PNG (Data-URL) in ein verstecktes Feld
  `signature[<declaration_id>]` geschrieben. Eine leere Fläche gilt als fehlende Unterschrift;
  der Browser verhindert das Absenden und markiert das Feld.
- Zeigt der Server das Formular nach einem Fehler erneut an, werden vorhandene Unterschriften
  aus dem versteckten Feld wieder in die Fläche geladen.
- Ohne JavaScript ist das Unterschreiben nicht möglich; ein `<noscript>`-Hinweis erklärt das,
  der Absenden-Knopf ist dann deaktiviert.
- Keine externe Bibliothek (CSP `script-src 'self'`).

### Serverprüfung

- Jede `confirmation` muss bestätigt, jede `consent` beantwortet sein.
- Jede Unterschrift einer Erklärung mit `signature_required`: Data-URL `image/png`,
  Base64 gültig, höchstens 200 KB, mit GD lesbar, höchstens 1200 × 400 px, nicht vollständig
  transparent bzw. weiß. Das Bild wird mit GD neu kodiert gespeichert (entfernt Metadaten).
- „Ort“ max. 100 Zeichen, Pflicht wie oben.
- Fehlermeldungen pro Erklärung am jeweiligen Abschnitt.
- Alles in derselben Transaktion wie das Anlegen des Antrags.

### Layout

- `layout.twig`: öffentliche Antragsseiten (`/antrag/…`) erhalten den Container `max-w-3xl`
  statt der Login-Breite `max-w-md`.
- Das zweite Logo im Kartenkopf entfällt; der Kopf zeigt Titel und Einladungstitel.
- Mobil einspaltig; ab `sm` nebeneinander: Vorname/Nachname, Straße/Hausnummer, PLZ/Ort,
  IBAN/BIC, E-Mail/Telefon. Kein horizontales Scrollen bei 360 px Breite.
- Straße und Hausnummer sind getrennte Felder – im Antrag und überall sonst in der App
  (Mitglieder haben `house_number` bereits; Finanzkontakte und Vereinsprofil bekommen es,
  der Kontakt-Export eine eigene Spalte). Vorhandene Adressen, die eindeutig auf eine
  Hausnummer enden, werden per Migration aufgeteilt; alle anderen bleiben unverändert.
- Schritte mit einheitlichem Abstand; „Zurück“/„Weiter“ als Leiste am Ende jedes Schritts,
  mobil in voller Breite.
- Label Geburtsdatum korrigiert („Geburtsdatum“, Hilfetext „TT.MM.JJJJ“).
- Der interne Hinweis zum deaktivierten E-Mail-Versand erscheint nicht mehr im öffentlichen
  Formular.

## Antragsansicht und PDF

- Die Entscheidungsseite (`/members/applications/{id}/decision`) zeigt nach den Personendaten
  alle Erklärungen des Antrags aus `application_signatures`: Titel, Version, Wahl,
  Unterschriftsbild, „Ort, Datum“. Der Text ist aufklappbar.
- Das Unterschriftsbild wird über eine eigene Route
  `/members/applications/{id}/signatures/{signature}.png` ausgeliefert
  (Berechtigung `members.write`, `Cache-Control: no-store`), nicht als Data-URL.
- „PDF herunterladen“ (`/members/applications/{id}/pdf`, Berechtigung `members.write`)
  erzeugt mit dem bestehenden PDF-Baustein ein Dokument im Stil des Papierformulars:
  Kopf mit Vereinsname und Titel, „Angaben zur Person“, SEPA-Abschnitt falls vorhanden,
  danach jede Erklärung mit Text, ☒/☐ für die Wahl und „Ort, Datum: … Unterschrift: [Bild]“.
  Fußzeile mit Antragsnummer, Eingangszeit und den Snapshot-Hashes.
- Der PDF-Abruf wird im Audit-Log protokolliert.

## Datenschutz und Löschung

- Unterschriften sind personenbezogene Daten. Sie erscheinen nur in der Antragsansicht, im
  PDF und in der Datenschutzauskunft.
- Anonymisierung eines Antrags (`/members/applications/{id}/anonymize`) setzt in
  `application_signatures` `signature_png` und `signature_sha256` auf NULL, `signed_place` auf
  `''`, den Snapshot auf `{}` und `snapshot_hash` auf den Hash von `{}`, analog zu
  `member_consents` mit Grund `privacy.redacted`. Ein Trigger erlaubt an
  `application_signatures` nur diese Änderung und nur bei anonymisiertem Antrag.
- Die bestehenden Aufbewahrungsfristen (`application_retention_days`) gelten auch für
  Unterschriften.

## Fehlerfälle

- Erklärung geändert während des Ausfüllens → Fingerprint-Hinweis, Formular neu, Eingaben
  bleiben erhalten, Unterschriften bleiben nur für unveränderte Erklärungen erhalten.
- Keine aktive Erklärung → Formular nicht verfügbar (Meldung statt Formular).
- Ungültiges oder zu großes Bild → Fehlermeldung am Abschnitt „Bitte erneut unterschreiben.“
- PDF-Erzeugung schlägt fehl → 500 mit Hinweis, Antrag bleibt unverändert.

## Tests

- PHPUnit
  - Migration: Standardsatz wird angelegt; Versionen unveränderlich.
  - Einstellungen: neue Version bei Änderung, keine bei unveränderter Eingabe, Deaktivieren,
    Reihenfolge, Berechtigung.
  - Absenden: fehlende Bestätigung, fehlende Wahl, fehlende/ungültige/zu große/leere
    Unterschrift, fehlender Ort; erfolgreicher Antrag speichert Snapshot und Hashes.
  - Fingerprint ändert sich bei neuer Version.
  - Genehmigung überträgt Einwilligungen nach `member_consents`.
  - Anonymisierung entfernt Unterschriften und Snapshots.
  - PDF-Route liefert `application/pdf`, enthält Titel aller Erklärungen; Bildroute nur mit
    Berechtigung.
- jsdom (`tests/signature-pad.test.cjs`): Zeichnen erzeugt PNG im versteckten Feld, Löschen
  leert es, leere Fläche blockiert Absenden, Wiederherstellen nach Neuanzeige.
- jsdom (`tests/application-stepper.test.cjs`): „Weiter“ blockiert bei unvollständigem
  Schritt, „Zurück“ immer möglich, Fragment und Fokus beim Wechsel, Öffnen des ersten
  Schritts mit Serverfehler, Zusammenfassung zeigt Wahl und Unterschrift, „Ändern“ springt
  zum Schritt.
- Screenshots des Formulars bei 400 px und 1300 px Breite (manuell beim Umsetzen).
