CREATE TABLE application_settings (
    id integer PRIMARY KEY CHECK (id = 1),
    require_sepa boolean NOT NULL DEFAULT false,
    version integer NOT NULL DEFAULT 1
);
INSERT INTO application_settings (id, require_sepa)
SELECT 1, COALESCE(data->>'application_require_sepa', '') = '1' FROM privacy_settings WHERE id = 1;
UPDATE privacy_settings SET data = data - 'application_require_sepa' WHERE id = 1;
