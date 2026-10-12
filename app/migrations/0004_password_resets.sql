CREATE TABLE password_resets (
    user_id bigint PRIMARY KEY REFERENCES users(id),
    token_hash char(64) NOT NULL UNIQUE,
    credential_hash char(64) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL DEFAULT (now() + interval '1 hour')
);
