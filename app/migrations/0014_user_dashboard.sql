CREATE TABLE user_dashboard_preferences (
    user_id bigint PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    cards jsonb NOT NULL CHECK (jsonb_typeof(cards) = 'array'),
    shortcuts jsonb NOT NULL CHECK (jsonb_typeof(shortcuts) = 'array'),
    version integer NOT NULL DEFAULT 1 CHECK (version > 0)
);
