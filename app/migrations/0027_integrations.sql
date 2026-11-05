CREATE TABLE integration_clients (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL,
    token_hash text NOT NULL UNIQUE,
    token_prefix text NOT NULL,
    scopes jsonb NOT NULL,
    protocols jsonb NOT NULL,
    allowed_roles jsonb NOT NULL DEFAULT '[]',
    created_by bigint NOT NULL REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at timestamptz NOT NULL,
    revoked_at timestamptz,
    last_used_at timestamptz
);
CREATE TABLE integration_results (
    client_id bigint NOT NULL REFERENCES integration_clients(id),
    request_id text NOT NULL,
    operation text NOT NULL,
    input_hash text NOT NULL,
    result jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, request_id)
);
CREATE TABLE integration_booking_reviews (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_id bigint NOT NULL REFERENCES integration_clients(id),
    booking_id bigint NOT NULL REFERENCES bookings(id),
    booking_version integer NOT NULL,
    status text NOT NULL CHECK (status IN ('checked', 'needs_review')),
    note text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX integration_booking_reviews_booking ON integration_booking_reviews(booking_id, id DESC);
