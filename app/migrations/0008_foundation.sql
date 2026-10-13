CREATE TABLE club_profile (
    id smallint PRIMARY KEY CHECK (id = 1),
    data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(data) = 'object'),
    version integer NOT NULL DEFAULT 1 CHECK (version > 0)
);
INSERT INTO club_profile (id, data)
SELECT 1, jsonb_build_object('name', value) FROM settings WHERE key = 'club_name';
INSERT INTO club_profile (id) VALUES (1) ON CONFLICT DO NOTHING;

CREATE TABLE finance_contacts (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind text NOT NULL CHECK (kind IN ('person', 'company')),
    name text NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 200),
    data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(data) = 'object'),
    active boolean NOT NULL DEFAULT true,
    version integer NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX finance_contacts_name_idx ON finance_contacts (lower(name), id);
CREATE TABLE foundation_changes (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    subject text NOT NULL CHECK (subject IN ('club', 'contact', 'account_bank')),
    subject_id bigint NOT NULL,
    actor_id bigint NOT NULL REFERENCES users(id),
    at timestamptz NOT NULL DEFAULT now(),
    reason text NOT NULL CHECK (length(btrim(reason)) > 0),
    changes jsonb NOT NULL CHECK (jsonb_typeof(changes) = 'array')
);
CREATE TRIGGER foundation_changes_immutable BEFORE UPDATE OR DELETE ON foundation_changes
    FOR EACH ROW EXECUTE FUNCTION vk_reject_modification();
CREATE TRIGGER foundation_changes_no_truncate BEFORE TRUNCATE ON foundation_changes
    FOR EACH STATEMENT EXECUTE FUNCTION vk_reject_modification();
ALTER TABLE accounts ADD COLUMN iban text NOT NULL DEFAULT '';
ALTER TABLE accounts ADD COLUMN bic text NOT NULL DEFAULT '';
ALTER TABLE accounts ADD COLUMN bank_version integer NOT NULL DEFAULT 1 CHECK (bank_version > 0);
ALTER TABLE bookings ADD COLUMN finance_contact_id bigint REFERENCES finance_contacts(id);
