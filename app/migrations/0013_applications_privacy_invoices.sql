ALTER TABLE members ADD COLUMN anonymized_at timestamptz;
CREATE TABLE privacy_settings (
    id smallint PRIMARY KEY CHECK(id=1),
    version integer NOT NULL DEFAULT 1 CHECK(version>0),
    data jsonb NOT NULL DEFAULT '{}'::jsonb
);
INSERT INTO privacy_settings(id) VALUES(1);
CREATE TABLE member_consents (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id bigint REFERENCES members(id),
    application_id bigint REFERENCES membership_applications(id),
    kind text NOT NULL CHECK(kind IN ('statute','privacy','media','newsletter','sepa')),
    granted boolean NOT NULL,
    document_version text NOT NULL,
    snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
    snapshot_hash char(64) NOT NULL,
    source text NOT NULL CHECK(source IN ('application','staff')),
    recorded_by bigint REFERENCES users(id),
    recorded_at timestamptz NOT NULL DEFAULT now(),
    reason text NOT NULL DEFAULT '',
    CHECK(member_id IS NOT NULL OR application_id IS NOT NULL)
);
CREATE INDEX member_consents_member_idx ON member_consents(member_id,id DESC);
CREATE TABLE mail_outbox (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    application_id bigint REFERENCES membership_applications(id),
    kind text NOT NULL,
    recipient text NOT NULL,
    subject text NOT NULL,
    body text NOT NULL,
    dedup_key char(64) NOT NULL UNIQUE,
    status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','sending','sent','failed','uncertain','cancelled')),
    version integer NOT NULL DEFAULT 1,
    attempts integer NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    attempted_at timestamptz,
    sent_at timestamptz
);
CREATE INDEX mail_outbox_pending_idx ON mail_outbox(id) WHERE status='pending';
CREATE TABLE privacy_actions (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id bigint NOT NULL REFERENCES members(id),
    actor_id bigint NOT NULL REFERENCES users(id),
    action text NOT NULL CHECK(action IN ('anonymized')),
    reason text NOT NULL,
    at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER privacy_actions_immutable BEFORE UPDATE OR DELETE ON privacy_actions
    FOR EACH ROW EXECUTE FUNCTION vk_reject_modification();
CREATE TRIGGER privacy_actions_no_truncate BEFORE TRUNCATE ON privacy_actions
    FOR EACH STATEMENT EXECUTE FUNCTION vk_reject_modification();
-- Immutable history permits only a narrowly defined removal of personal member snapshots.
CREATE FUNCTION vk_member_history_redaction() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='UPDATE' AND OLD.subject='member'
       AND NEW.id=OLD.id AND NEW.subject=OLD.subject AND NEW.subject_id=OLD.subject_id
       AND NEW.actor_id=OLD.actor_id AND NEW.at=OLD.at
       AND NEW.changes='[]'::jsonb AND NEW.reason='privacy.redacted'
       AND EXISTS(SELECT 1 FROM members WHERE id=OLD.subject_id AND anonymized_at IS NOT NULL)
    THEN RETURN NEW; END IF;
    RAISE EXCEPTION 'Immutable history';
END $$;
DROP TRIGGER foundation_changes_immutable ON foundation_changes;
CREATE TRIGGER foundation_changes_immutable BEFORE UPDATE OR DELETE ON foundation_changes
    FOR EACH ROW EXECUTE FUNCTION vk_member_history_redaction();
CREATE TABLE invoices (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind text NOT NULL DEFAULT 'invoice' CHECK(kind IN ('invoice','credit_note')),
    supplier_id bigint REFERENCES finance_contacts(id),
    supplier_name text NOT NULL DEFAULT '',
    number text NOT NULL DEFAULT '',
    invoice_date date,
    due_date date,
    currency char(3) NOT NULL DEFAULT 'EUR',
    net numeric(12,2), tax numeric(12,2), gross numeric(12,2),
    notes text NOT NULL DEFAULT '',
    status text NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','reviewed','cancelled')),
    version integer NOT NULL DEFAULT 1,
    created_by bigint NOT NULL REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    reviewed_by bigint REFERENCES users(id),
    reviewed_at timestamptz,
    CHECK(net IS NULL OR net>=0), CHECK(tax IS NULL OR tax>=0), CHECK(gross IS NULL OR gross>0)
);
CREATE INDEX invoices_list_idx ON invoices(status,due_date,id);
CREATE TABLE invoice_documents (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id bigint NOT NULL REFERENCES invoices(id),
    file_id bigint NOT NULL UNIQUE REFERENCES stored_files(id),
    original_sha256 char(64) NOT NULL UNIQUE,
    role text NOT NULL DEFAULT 'original' CHECK(role IN ('original','supplement')),
    extraction_status text NOT NULL DEFAULT 'pending' CHECK(extraction_status IN ('pending','done','manual','failed')),
    extraction jsonb NOT NULL DEFAULT '{}'::jsonb,
    UNIQUE(invoice_id,file_id)
);
CREATE TABLE invoice_changes (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id bigint NOT NULL REFERENCES invoices(id),
    actor_id bigint NOT NULL REFERENCES users(id),
    reason text NOT NULL CHECK(length(btrim(reason))>0),
    before_data jsonb NOT NULL, after_data jsonb NOT NULL,
    at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER invoice_changes_immutable BEFORE UPDATE OR DELETE ON invoice_changes
    FOR EACH ROW EXECUTE FUNCTION vk_reject_modification();
CREATE TRIGGER invoice_changes_no_truncate BEFORE TRUNCATE ON invoice_changes
    FOR EACH STATEMENT EXECUTE FUNCTION vk_reject_modification();
CREATE FUNCTION vk_consent_changes() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='UPDATE' THEN
        -- Link submitted confirmations to the member created by an approved application.
        IF OLD.member_id IS NULL AND NEW.member_id IS NOT NULL
           AND (to_jsonb(NEW)-'member_id')=(to_jsonb(OLD)-'member_id')
           AND EXISTS(SELECT 1 FROM membership_applications WHERE id=OLD.application_id AND member_id=NEW.member_id AND status='approved')
        THEN RETURN NEW; END IF;
        -- Anonymization may redact personal snapshots but cannot rewrite the recorded fact.
        IF OLD.member_id IS NOT NULL AND NEW.member_id=OLD.member_id
           AND (to_jsonb(NEW)-ARRAY['snapshot','snapshot_hash','document_version','reason'])=(to_jsonb(OLD)-ARRAY['snapshot','snapshot_hash','document_version','reason'])
           AND NEW.snapshot='{}'::jsonb AND NEW.document_version='' AND NEW.reason='privacy.redacted'
           AND EXISTS(SELECT 1 FROM members WHERE id=OLD.member_id AND anonymized_at IS NOT NULL)
        THEN RETURN NEW; END IF;
        IF OLD.member_id IS NULL
           AND (to_jsonb(NEW)-ARRAY['snapshot','snapshot_hash','document_version','reason'])=(to_jsonb(OLD)-ARRAY['snapshot','snapshot_hash','document_version','reason'])
           AND NEW.snapshot='{}'::jsonb AND NEW.document_version='' AND NEW.reason='privacy.redacted'
           AND EXISTS(SELECT 1 FROM membership_applications WHERE id=OLD.application_id AND anonymized_at IS NOT NULL)
        THEN RETURN NEW; END IF;
    END IF;
    RAISE EXCEPTION 'Immutable consent history';
END $$;
CREATE TRIGGER member_consents_immutable BEFORE UPDATE OR DELETE ON member_consents
    FOR EACH ROW EXECUTE FUNCTION vk_consent_changes();
CREATE TRIGGER member_consents_no_truncate BEFORE TRUNCATE ON member_consents
    FOR EACH STATEMENT EXECUTE FUNCTION vk_reject_modification();
ALTER TABLE membership_applications ADD COLUMN anonymized_at timestamptz;
ALTER TABLE membership_applications ADD COLUMN version integer NOT NULL DEFAULT 1;
ALTER TABLE privacy_actions ALTER COLUMN member_id DROP NOT NULL;
ALTER TABLE privacy_actions ADD COLUMN application_id bigint REFERENCES membership_applications(id);
ALTER TABLE privacy_actions ADD CONSTRAINT privacy_action_subject CHECK(member_id IS NOT NULL OR application_id IS NOT NULL);
