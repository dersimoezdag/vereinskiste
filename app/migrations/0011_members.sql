-- Mitglieder: Personen mit interner Nummer und zeitlicher Mitgliedschaft.
-- Ein Benutzerkonto ist optional und hoechstens einem Mitglied zugeordnet (users.member_id).

CREATE SEQUENCE member_no_seq;

CREATE TABLE members (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_no  integer     NOT NULL UNIQUE DEFAULT nextval('member_no_seq'),
    name       text        NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 200),
    data       jsonb       NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(data) = 'object'),
    version    integer     NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT now()
);
ALTER SEQUENCE member_no_seq OWNED BY members.member_no;
CREATE INDEX members_name_idx ON members (lower(name), id);

CREATE TABLE membership_periods (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id  bigint NOT NULL REFERENCES members (id),
    start_date date   NOT NULL,
    end_date   date,
    end_reason text,
    CHECK (end_date IS NULL OR end_date >= start_date)
);
-- Hoechstens ein offener Zeitraum je Mitglied; Ueberlappungen prueft MembershipPeriods unter Zeilensperre.
CREATE UNIQUE INDEX membership_periods_open_idx ON membership_periods (member_id) WHERE end_date IS NULL;
CREATE INDEX membership_periods_member_idx ON membership_periods (member_id, start_date);

ALTER TABLE users ADD COLUMN member_id bigint UNIQUE REFERENCES members (id);

-- Vereinsrollen: frei definierbare Bezeichnungen ohne Rechte (getrennt von user_roles).
CREATE TABLE club_roles (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name        text    NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 100),
    description text,
    kind        text    NOT NULL DEFAULT 'function' CHECK (kind IN ('function', 'membership_type')),
    single_holder boolean NOT NULL DEFAULT false,
    system_key  text UNIQUE,
    active      boolean NOT NULL DEFAULT true,
    version     integer NOT NULL DEFAULT 1 CHECK (version > 0)
);
CREATE UNIQUE INDEX club_roles_name_idx ON club_roles (lower(name));

INSERT INTO club_roles (name, kind, single_holder, system_key) VALUES
    ('Aktives Mitglied', 'membership_type', false, 'active_member'),
    ('Passives Mitglied', 'membership_type', false, 'passive_member'),
    ('Ehemaliges Mitglied', 'membership_type', false, 'former_member'),
    ('Ehrenmitglied', 'function', false, NULL),
    ('Finanzvorstand', 'function', true, NULL),
    ('1. Sprecher', 'function', true, NULL),
    ('2. Sprecher', 'function', true, NULL);

CREATE TABLE member_club_roles (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id  bigint NOT NULL REFERENCES members (id),
    role_id    bigint NOT NULL REFERENCES club_roles (id),
    scope      text CHECK (scope IS NULL OR length(btrim(scope)) BETWEEN 1 AND 200),
    start_date date,
    end_date   date,
    CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date)
);
-- Gleiche Rolle mit gleicher Zustaendigkeit nicht doppelt gleichzeitig (offene Zuordnungen).
CREATE UNIQUE INDEX member_club_roles_open_idx
    ON member_club_roles (member_id, role_id, lower(coalesce(scope, ''))) WHERE end_date IS NULL;
CREATE INDEX member_club_roles_role_idx ON member_club_roles (role_id) WHERE end_date IS NULL;

ALTER TABLE foundation_changes DROP CONSTRAINT foundation_changes_subject_check;
ALTER TABLE foundation_changes ADD CONSTRAINT foundation_changes_subject_check
    CHECK (subject IN ('club', 'contact', 'account_bank', 'member', 'club_role'));

-- Einmal-Links fuer Mitgliedsantraege (/antrag/{token})
CREATE TABLE membership_application_invites (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    token text NOT NULL UNIQUE CHECK (length(token) >= 32),
    title text NOT NULL DEFAULT '',
    require_sepa boolean NOT NULL DEFAULT false,
    password_hash text NULL,
    expires_at timestamptz NOT NULL,
    max_uses integer NOT NULL DEFAULT 1 CHECK (max_uses >= 1),
    used_count integer NOT NULL DEFAULT 0 CHECK (used_count >= 0),
    created_by bigint NOT NULL REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX membership_application_invites_token_idx ON membership_application_invites (token);

-- Mitgliedsantraege
CREATE TABLE membership_applications (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invite_id bigint NULL REFERENCES membership_application_invites(id) ON DELETE SET NULL,
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    applicant_name text NOT NULL CHECK (length(btrim(applicant_name)) BETWEEN 1 AND 200),
    email text NOT NULL CHECK (position('@' IN email) > 1),
    data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(data) = 'object'),
    member_id bigint NULL REFERENCES members(id) ON DELETE SET NULL,
    rejection_reason text NULL,
    reviewed_by bigint NULL REFERENCES users(id),
    reviewed_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX membership_applications_status_idx ON membership_applications (status, created_at DESC);
