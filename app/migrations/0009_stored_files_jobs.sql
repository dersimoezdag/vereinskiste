CREATE TABLE stored_files (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    root text NOT NULL CHECK (root IN ('receipts', 'documents')),
    storage_name text NOT NULL CHECK (storage_name ~ '^[a-f0-9]{32}\.(pdf|jpg|png|xml)$'),
    original_name text NOT NULL,
    mime text NOT NULL,
    size bigint NOT NULL CHECK (size > 0),
    sha256 char(64) NOT NULL,
    uploaded_by bigint NOT NULL REFERENCES users(id),
    uploaded_at timestamptz NOT NULL DEFAULT now(),
    status text NOT NULL DEFAULT 'stored' CHECK (status IN ('stored', 'processing', 'failed')),
    UNIQUE (root, storage_name)
);
ALTER TABLE receipts ADD COLUMN stored_file_id bigint REFERENCES stored_files(id);
ALTER TABLE club_profile ADD COLUMN logo_id bigint REFERENCES stored_files(id);

CREATE TABLE jobs (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind text NOT NULL,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    dedup_key text NOT NULL UNIQUE,
    priority integer NOT NULL DEFAULT 0,
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'done', 'failed')),
    attempts integer NOT NULL DEFAULT 0,
    available_at timestamptz NOT NULL DEFAULT now(),
    lease_until timestamptz,
    lease_token char(64),
    progress integer NOT NULL DEFAULT 0 CHECK (progress BETWEEN 0 AND 100),
    error_code text,
    created_at timestamptz NOT NULL DEFAULT now(),
    finished_at timestamptz
);
CREATE INDEX jobs_ready_idx ON jobs (priority DESC, available_at, id) WHERE status IN ('pending', 'running');
