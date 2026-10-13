ALTER TABLE stored_files DROP CONSTRAINT stored_files_storage_name_check;
ALTER TABLE stored_files ADD CHECK (storage_name ~ '^[a-f0-9]{32}\.(pdf|jpg|png|xml|csv)$');

CREATE TABLE bank_imports (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    account_id bigint NOT NULL REFERENCES accounts(id),
    file_id bigint NOT NULL REFERENCES stored_files(id),
    sha256 char(64) NOT NULL,
    format text NOT NULL DEFAULT 'csv' CHECK (format = 'csv'),
    status text NOT NULL DEFAULT 'uploaded' CHECK (status IN ('uploaded','parsing','review','confirming','confirmed','cancelled','failed')),
    settings jsonb NOT NULL DEFAULT '{}'::jsonb,
    total_rows integer NOT NULL CHECK (total_rows BETWEEN 1 AND 10000),
    parsed_rows integer NOT NULL DEFAULT 0,
    version integer NOT NULL DEFAULT 1,
    parse_revision integer NOT NULL DEFAULT 1,
    error_code text,
    uploaded_by bigint NOT NULL REFERENCES users(id),
    confirmed_by bigint REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (account_id, sha256)
);
CREATE TABLE bank_csv_profiles (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL CHECK (length(name) BETWEEN 1 AND 100),
    settings jsonb NOT NULL,
    created_by bigint NOT NULL REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE bank_statements (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    import_id bigint NOT NULL UNIQUE REFERENCES bank_imports(id),
    account_id bigint NOT NULL REFERENCES accounts(id),
    bank_reference text,
    date_from date,
    date_to date,
    opening_balance numeric(12,2),
    closing_balance numeric(12,2),
    currency char(3) NOT NULL DEFAULT 'EUR'
);
CREATE TABLE bank_transactions (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    account_id bigint NOT NULL REFERENCES accounts(id),
    statement_id bigint NOT NULL REFERENCES bank_statements(id),
    bank_reference text NOT NULL DEFAULT '',
    booking_date date NOT NULL,
    value_date date,
    amount numeric(10,2) NOT NULL CHECK (amount <> 0),
    currency char(3) NOT NULL CHECK (currency = 'EUR'),
    counterparty text NOT NULL,
    iban text NOT NULL DEFAULT '',
    purpose text NOT NULL,
    fingerprint char(64) NOT NULL,
    status text NOT NULL DEFAULT 'unassigned' CHECK (status IN ('unassigned','assigned','ignored')),
    version integer NOT NULL DEFAULT 1
);
CREATE UNIQUE INDEX bank_transaction_reference ON bank_transactions(account_id,bank_reference) WHERE bank_reference <> '';
CREATE INDEX bank_transaction_candidates ON bank_transactions(account_id,fingerprint);
CREATE TABLE bank_import_rows (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    import_id bigint NOT NULL REFERENCES bank_imports(id),
    row_number integer NOT NULL,
    proposal jsonb NOT NULL,
    issues jsonb NOT NULL,
    decision text NOT NULL DEFAULT 'accept' CHECK (decision IN ('accept','pending','new','duplicate','excluded')),
    reason text NOT NULL DEFAULT '',
    duplicate_id bigint REFERENCES bank_transactions(id),
    transaction_id bigint REFERENCES bank_transactions(id),
    is_current boolean NOT NULL DEFAULT true
);
CREATE UNIQUE INDEX bank_import_current_row ON bank_import_rows(import_id,row_number) WHERE is_current;
CREATE INDEX bank_import_row_candidates ON bank_import_rows(import_id,(proposal->>'fingerprint')) WHERE is_current;
CREATE TABLE bank_import_attempts (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    import_id bigint NOT NULL REFERENCES bank_imports(id),
    revision integer NOT NULL,
    settings jsonb NOT NULL,
    reason text NOT NULL,
    actor bigint NOT NULL REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (import_id,revision)
);
