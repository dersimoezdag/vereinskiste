-- Veranstaltungen mit Vorlagen, Foerdermittel und Buchungsimport aus Tabellen.
CREATE TABLE events (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind text NOT NULL DEFAULT 'event' CHECK (kind IN ('event', 'template')),
    title text NOT NULL CHECK (length(btrim(title)) > 0),
    starts_on date,
    ends_on date,
    location text NOT NULL DEFAULT '',
    notes text NOT NULL DEFAULT '',
    status text NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'done', 'cancelled')),
    source_event_id bigint REFERENCES events (id) ON DELETE SET NULL,
    version integer NOT NULL DEFAULT 1,
    created_by bigint NOT NULL REFERENCES users (id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT event_dates CHECK (
        (kind = 'template' AND starts_on IS NULL AND ends_on IS NULL)
        OR (kind = 'event' AND starts_on IS NOT NULL AND (ends_on IS NULL OR ends_on >= starts_on))
    )
);
CREATE INDEX events_kind_date_idx ON events (kind, starts_on DESC, id DESC);

CREATE TABLE event_budget_items (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_id bigint NOT NULL REFERENCES events (id) ON DELETE CASCADE,
    label text NOT NULL CHECK (length(btrim(label)) > 0),
    direction text NOT NULL CHECK (direction IN ('in', 'out')),
    planned_amount numeric(12,2) NOT NULL CHECK (planned_amount > 0)
);
CREATE INDEX event_budget_items_event_idx ON event_budget_items (event_id, id);

-- Faelligkeit relativ zum Start, damit sie beim Klonen mitwandert.
CREATE TABLE event_tasks (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_id bigint NOT NULL REFERENCES events (id) ON DELETE CASCADE,
    title text NOT NULL CHECK (length(btrim(title)) > 0),
    member_id bigint REFERENCES members (id) ON DELETE SET NULL,
    person_name text NOT NULL DEFAULT '',
    due_offset_days integer CHECK (due_offset_days BETWEEN -3650 AND 3650),
    done boolean NOT NULL DEFAULT false
);
CREATE INDEX event_tasks_event_idx ON event_tasks (event_id, id);
CREATE INDEX event_tasks_member_idx ON event_tasks (member_id) WHERE member_id IS NOT NULL;

CREATE TABLE funding_applications (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    title text NOT NULL CHECK (length(btrim(title)) > 0),
    funder text NOT NULL CHECK (length(btrim(funder)) > 0),
    event_id bigint REFERENCES events (id) ON DELETE SET NULL,
    status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'approved', 'rejected', 'closed')),
    requested_amount numeric(12,2) NOT NULL CHECK (requested_amount > 0),
    approved_amount numeric(12,2) CHECK (approved_amount >= 0),
    submitted_on date,
    decided_on date,
    proof_due_on date,
    notes text NOT NULL DEFAULT '',
    version integer NOT NULL DEFAULT 1,
    created_by bigint NOT NULL REFERENCES users (id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX funding_applications_status_idx ON funding_applications (status, id DESC);
CREATE INDEX funding_applications_event_idx ON funding_applications (event_id) WHERE event_id IS NOT NULL;

CREATE TABLE funding_items (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES funding_applications (id) ON DELETE CASCADE,
    label text NOT NULL CHECK (length(btrim(label)) > 0),
    amount numeric(12,2) CHECK (amount > 0)
);
CREATE INDEX funding_items_application_idx ON funding_items (application_id, id);

-- Zahlungsstaende werden immer aus Buchungen berechnet, nie gespeichert.
ALTER TABLE bookings ADD COLUMN event_id bigint REFERENCES events (id);
ALTER TABLE bookings ADD COLUMN funding_id bigint REFERENCES funding_applications (id);
CREATE INDEX bookings_event_idx ON bookings (event_id) WHERE event_id IS NOT NULL;
CREATE INDEX bookings_funding_idx ON bookings (funding_id) WHERE funding_id IS NOT NULL;

-- Entwurf mit ausgelesenen Zeilen; nach der Uebernahme bleibt nur die Zusammenfassung.
CREATE TABLE booking_imports (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    account_id bigint NOT NULL REFERENCES accounts (id),
    file_name text NOT NULL,
    file_sha256 text NOT NULL,
    status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'confirmed')),
    sheets jsonb NOT NULL,
    settings jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_count integer NOT NULL DEFAULT 0,
    version integer NOT NULL DEFAULT 1,
    created_by bigint NOT NULL REFERENCES users (id),
    created_at timestamptz NOT NULL DEFAULT now(),
    confirmed_at timestamptz
);

ALTER TABLE foundation_changes DROP CONSTRAINT foundation_changes_subject_check;
ALTER TABLE foundation_changes ADD CONSTRAINT foundation_changes_subject_check
    CHECK (subject IN ('club', 'contact', 'account_bank', 'member', 'club_role', 'payment_policy', 'event', 'funding'));
