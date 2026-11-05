# Antragsentscheidung als Stepper – Design

Datum: 2026-11-05

## Ziel

Die Entscheidung über Mitgliedsanträge (`/members/applications/{id}/decision`) wird ein klarer
3-Schritt-Ablauf auf einer eigenen Seite. Behoben werden dabei:

1. Antragsdaten (Adresse, Geburtsdatum, SEPA, Notizen) fehlen auf der Entscheidungsseite.
2. Betreff/Nachricht bleiben beim Umschalten Annehmen ↔ Ablehnen auf dem alten Text stehen –
   es kann eine Annahme-Mail bei Ablehnung verschickt werden (und umgekehrt).
3. Der Ablehnungsgrund wird auch bei Annahme angezeigt.
4. Eintrittsdatum ist fest „heute“, Mitgliedsart fest „Aktiv“.
5. Vorschau braucht einen Server-Roundtrip und ist unstrukturiert.

## Nicht-Ziele

- Kein Modal, keine Entscheidung direkt aus der Tabelle.
- Kein expliziter No-JS-Fallback (Admin-Bereich, JS wird überall vorausgesetzt).
- Keine Beitragszuordnung (es gibt kein Beitragsmodul).

## Seitenaufbau

`members/decision.twig`, ein einziges `<form data-stepper data-checks-ok="1">`:

- **Kopf:** Name, `#id`, Eingangsdatum, Status-Badge, PDF-Button.
- **Stepper:** `stepper.steps([...], label, numbers=true)` mit *Prüfen · Entscheiden · Bestätigen*.
- **Hinweise:** Mail-deaktiviert-Warnung, Fehlerliste (wie bisher).

### Schritt 1 – Antrag prüfen (`<fieldset data-step>`)

- Antragsdaten als `<dl>` in zweispaltigem Grid (Kontakt, Adresse, Geburtsdatum, Bankverbindung/SEPA,
  Notizen). Die `<dl>` wird aus `members/_application_details.twig` in ein neues Partial
  `members/_application_data.twig` ausgelagert; das Details-Partial der Tabelle bindet es ein.
- Erklärungen & Unterschriften wie bisher (Abschnitt aus dem aktuellen Template übernehmen).

### Schritt 2 – Entscheiden

- Zwei Auswahlkarten (`<input type="radio" name="decision">` in `<label>`-Karten, Icon + Titel +
  Kurzbeschreibung): „Annehmen & Mitglied anlegen“ (Erfolgston), „Ablehnen“ (Gefahrton). Vorauswahl:
  `input.decision` bzw. `?decision=`, sonst `approved`.
- Nur bei *Annehmen* sichtbar (`hidden` + `disabled`, damit nicht validiert/gesendet):
  - `join_date` – Datumsfeld mit vorhandener Datumsmaske, Default heute, Pflicht.
  - `membership_type_id` – `<select>` aller aktiven `club_roles` mit `kind='membership_type'` außer
    `system_key='former_member'`, Default die Rolle `active_member`, Pflicht.
- Nur bei *Ablehnen* sichtbar: `reason` (optional, max. 500, „intern“).

### Schritt 3 – Nachricht & Bestätigen

- `subject` / `message` (Pflicht), vorbelegt mit der Vorlage der gewählten Entscheidung.
- Zusammenfassungskarte, live aus den Feldern:
  - Annahme: „{Name} wird zum {Datum} als {Mitgliedsart} angelegt. Nachricht an {E-Mail} wird vorgemerkt.“
  - Ablehnung: „Antrag von {Name} wird abgelehnt. Nachricht an {E-Mail} wird vorgemerkt.“
- Submit-Button in Entscheidungsfarbe: „Antrag annehmen“ / „Antrag ablehnen“.

### Navigation

Vorhandene Stepper-Leiste (`[data-step-prev]`, `[data-step-next]`, `[data-step-submit]`) plus
„Abbrechen“ → `/members/applications`.

## Frontend-Verhalten

- Wiederverwendung von `form[data-stepper]` in `public/assets/app.js`. Kleine Erweiterungen:
  - `valid()` und Fokus berücksichtigen auch `textarea`.
  - Ausgeblendete Zusatzfelder sind `disabled` und damit von der Constraint-Validierung
    ausgenommen (Browserstandard) – keine Sonderlogik nötig.
  - Neues Attribut `data-start-step="N"` (zusätzlich zu `data-start-last`), damit nach einem
    Serverfehler der Schritt mit dem fehlerhaften Feld geöffnet wird.
- Neues kleines Skript `public/assets/application-decision.js`:
  - Umschalten der Zusatzfelder je Entscheidung (`hidden`/`disabled`).
  - Vorlagen für beide Entscheidungen liegen als `data-template-approved-subject` usw. am Formular.
    Beim Wechsel: Wurde Betreff/Nachricht noch nicht manuell geändert → ersetzen. Sonst Inline-Hinweis
    „Text an neue Entscheidung anpassen?“ mit Button „Vorlage übernehmen“ (kein `confirm()`).
  - Zusammenfassung und Submit-Text/-Farbe aktualisieren.
- Styles in `app/styles/app.css` für die Auswahlkarten (`.choice-card`, `:has(:checked)`), Rest über
  bestehende Utility-Klassen.

## Backend

### `ApplicationDecisionController`

- `save`: Die Stufen `preview`/`edit` entfallen. Ein POST ist die Entscheidung.
- Validierung: `decision ∈ {approved, rejected}`, `subject`/`message` wie bisher. Bei `approved`
  zusätzlich `join_date` (gültiges Datum `Y-m-d`) und `membership_type_id` (existiert, aktiv,
  `kind='membership_type'`, nicht `former_member`) → sonst `ValidationError` mit Feldschlüssel.
- `page`: übergibt zusätzlich `membership_types`, `default_type_id`, `today`, beide Mail-Vorlagen
  (`templates.approved`, `templates.rejected` aus `ApplicationNotifications::defaults`) und
  `start_step` (aus Fehlerschlüsseln: `decision`/`join_date`/`membership_type_id`/`reason` → 1,
  `subject`/`message` → 2, sonst 0).
- Fehlertexte werden am jeweiligen Feld angezeigt, allgemeine Fehler oben.

### `ApplicationRepository::approve`

Signatur: `approve(int $id, int $actor, ?array $mail = null, ?string $joinDate = null, ?int $typeId = null)`.

- `$joinDate` Default heute, `$typeId` Default keiner (dann wie bisher „Aktiv“ über `onJoin`).
- Reihenfolge innerhalb der Transaktion: Mitglied anlegen → falls `$typeId`:
  `MemberRoleAssignments::assign($memberId, $typeId, '', $joinDate, $actor)` →
  `MembershipPeriods::join($memberId, $joinDate, $actor)`. `MembershipTypeSync::onJoin` vergibt
  „Aktiv“ nur ohne offene Mitgliedsart, daher keine Änderung an der Sync-Logik nötig.
- Typprüfung (aktiv, `membership_type`, nicht `former_member`) erfolgt im Repository, damit sie
  auch für andere Aufrufer gilt.

## Texte

Neue Schlüssel in `app/src/Text/de/modules.php` (Präfix `applications.decision.`):
`step_review`, `step_decide`, `step_confirm`, `approve_hint`, `reject_hint`, `join_date`,
`membership_type`, `reason_internal`, `summary_approved`, `summary_rejected`, `submit_approved`,
`submit_rejected`, `template_changed`, `template_apply`, `cancel`.
Entfallende Schlüssel: `applications.decision.preview`, `applications.decision.confirm`,
`applications.decision.edit` (nur entfernen, wenn nirgends sonst genutzt).

## Tests

- PHPUnit:
  - Annahme mit `join_date` + `membership_type_id` → Mitgliedschaftszeitraum startet am Datum,
    Mitglied hat genau diese Mitgliedsart ab Datum, kein zusätzliches „Aktiv“.
  - Annahme ohne Typ (Repository-Default) → weiterhin „Aktiv“ ab heute.
  - Ungültige/inaktive Mitgliedsart und `former_member` → 422, Antrag bleibt `pending`.
  - Ungültiges Datum → 422.
  - Ablehnung ignoriert `join_date`/`membership_type_id`.
  - Seite rendert Antragsdaten, beide Vorlagen und den Stepper.
- Bestehende Tests (`ModulesPagesTest`, `ApplicationOutboxTest`, `ApplicationSignaturePagesTest`) an
  den Wegfall von `stage=preview/confirm` anpassen.
