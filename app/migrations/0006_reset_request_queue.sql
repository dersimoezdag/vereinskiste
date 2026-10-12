CREATE TABLE password_reset_requests (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email text NOT NULL CHECK (octet_length(email) <= 254),
    requested_at timestamptz NOT NULL DEFAULT now()
);
