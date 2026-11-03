-- Auslagen von Mitgliedern und anderen Personen. Erstattet ist eine Auslage nur ueber eine aktive
-- verknuepfte Buchung; der Status wird daraus berechnet, nie gespeichert.
CREATE TABLE member_expenses (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id bigint REFERENCES members (id) ON DELETE SET NULL,
    person_name text NOT NULL DEFAULT '',
    purpose text NOT NULL CHECK (length(btrim(purpose)) > 0),
    amount numeric(12,2) NOT NULL CHECK (amount > 0),
    spent_on date NOT NULL,
    has_receipt boolean NOT NULL DEFAULT false,
    event_id bigint REFERENCES events (id) ON DELETE SET NULL,
    booking_id bigint REFERENCES bookings (id),
    version integer NOT NULL DEFAULT 1,
    created_by bigint NOT NULL REFERENCES users (id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT member_expense_person CHECK (member_id IS NOT NULL OR length(btrim(person_name)) > 0)
);
CREATE INDEX member_expenses_member_idx ON member_expenses (member_id) WHERE member_id IS NOT NULL;
CREATE UNIQUE INDEX member_expenses_booking_idx ON member_expenses (booking_id) WHERE booking_id IS NOT NULL;

ALTER TABLE foundation_changes DROP CONSTRAINT foundation_changes_subject_check;
ALTER TABLE foundation_changes ADD CONSTRAINT foundation_changes_subject_check
    CHECK (subject IN ('club', 'contact', 'account_bank', 'member', 'club_role', 'payment_policy', 'event', 'funding', 'expense'));
