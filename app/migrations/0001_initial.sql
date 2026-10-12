-- Vereinskiste: Initialschema (PostgreSQL 15)

CREATE TABLE users (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email         text        NOT NULL UNIQUE,
    name          text        NOT NULL,
    password_hash text        NOT NULL,
    active        boolean     NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE user_roles (
    user_id bigint NOT NULL REFERENCES users (id),
    role    text   NOT NULL CHECK (role IN ('vorstand', 'kassenwart', 'schriftfuehrer', 'kassenpruefer')),
    PRIMARY KEY (user_id, role)
);

CREATE TABLE accounts (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            text          NOT NULL,
    type            text          NOT NULL CHECK (type IN ('cash', 'bank')),
    opening_balance numeric(12,2) NOT NULL DEFAULT 0,
    opening_date    date          NOT NULL,
    active          boolean       NOT NULL DEFAULT true
);

CREATE TABLE categories (
    id     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name   text    NOT NULL UNIQUE,
    active boolean NOT NULL DEFAULT true
);

CREATE TABLE bookings (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    account_id       bigint        NOT NULL REFERENCES accounts (id),
    booking_date     date          NOT NULL,
    amount           numeric(12,2) NOT NULL CHECK (amount > 0),
    direction        text          NOT NULL CHECK (direction IN ('in', 'out')),
    category_id      bigint        REFERENCES categories (id),
    description      text          NOT NULL,
    counterparty     text,
    receipt_no       text,
    kind             text          NOT NULL DEFAULT 'regular' CHECK (kind IN ('regular', 'correction', 'transfer')),
    status           text          NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'cancelled')),
    correction_of_id bigint        REFERENCES bookings (id),
    transfer_id      bigint,
    version          integer       NOT NULL DEFAULT 1,
    created_by       bigint        NOT NULL REFERENCES users (id),
    created_at       timestamptz   NOT NULL DEFAULT now(),
    updated_at       timestamptz   NOT NULL DEFAULT now(),
    CONSTRAINT bookings_correction_ref CHECK (kind <> 'correction' OR correction_of_id IS NOT NULL)
);

CREATE INDEX bookings_date_idx        ON bookings (booking_date DESC, id DESC);
CREATE INDEX bookings_account_date_idx ON bookings (account_id, booking_date DESC, id DESC);
CREATE INDEX bookings_category_date_idx ON bookings (category_id, booking_date DESC) WHERE category_id IS NOT NULL;
CREATE INDEX bookings_correction_of_idx ON bookings (correction_of_id) WHERE correction_of_id IS NOT NULL;
CREATE INDEX bookings_transfer_idx    ON bookings (transfer_id) WHERE transfer_id IS NOT NULL;

CREATE TABLE booking_changes (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    booking_id bigint      NOT NULL REFERENCES bookings (id),
    changed_by bigint      NOT NULL REFERENCES users (id),
    changed_at timestamptz NOT NULL DEFAULT now(),
    changes    jsonb       NOT NULL,
    reason     text        NOT NULL CHECK (length(btrim(reason)) > 0)
);

CREATE INDEX booking_changes_booking_idx ON booking_changes (booking_id, changed_at);

CREATE TABLE receipts (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    booking_id    bigint      NOT NULL REFERENCES bookings (id),
    original_name text        NOT NULL,
    mime          text        NOT NULL,
    size          bigint      NOT NULL CHECK (size >= 0),
    sha256        char(64)    NOT NULL,
    storage_name  text        NOT NULL UNIQUE,
    uploaded_by   bigint      NOT NULL REFERENCES users (id),
    uploaded_at   timestamptz NOT NULL DEFAULT now(),
    replaced_at   timestamptz
);

CREATE INDEX receipts_booking_idx ON receipts (booking_id);

CREATE TABLE settings (
    key   text PRIMARY KEY,
    value text NOT NULL
);

CREATE TABLE audit_log (
    id      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id bigint      REFERENCES users (id),
    action  text        NOT NULL,
    detail  jsonb       NOT NULL DEFAULT '{}'::jsonb,
    at      timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX audit_log_at_idx ON audit_log (at DESC);

-- Unveraenderlichkeit
CREATE FUNCTION vk_reject_modification() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION '% auf % ist nicht erlaubt (unveraenderlich)', TG_OP, TG_TABLE_NAME
        USING ERRCODE = 'restrict_violation';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER bookings_no_delete
    BEFORE DELETE ON bookings
    FOR EACH ROW EXECUTE FUNCTION vk_reject_modification();

CREATE TRIGGER booking_changes_immutable
    BEFORE UPDATE OR DELETE ON booking_changes
    FOR EACH ROW EXECUTE FUNCTION vk_reject_modification();

CREATE TRIGGER audit_log_immutable
    BEFORE UPDATE OR DELETE ON audit_log
    FOR EACH ROW EXECUTE FUNCTION vk_reject_modification();

CREATE TRIGGER bookings_no_truncate
    BEFORE TRUNCATE ON bookings
    FOR EACH STATEMENT EXECUTE FUNCTION vk_reject_modification();

CREATE TRIGGER booking_changes_no_truncate
    BEFORE TRUNCATE ON booking_changes
    FOR EACH STATEMENT EXECUTE FUNCTION vk_reject_modification();

CREATE TRIGGER audit_log_no_truncate
    BEFORE TRUNCATE ON audit_log
    FOR EACH STATEMENT EXECUTE FUNCTION vk_reject_modification();
