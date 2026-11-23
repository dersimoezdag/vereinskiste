-- Auslagen: Auszahlungsweg, Quelle und optionale Gegenzeichnung des Empfaengers.
ALTER TABLE member_expenses
    ADD COLUMN payout_method text CHECK (payout_method IN ('paypal', 'bank', 'cash')),
    ADD COLUMN payout_detail text NOT NULL DEFAULT '',
    ADD COLUMN payout_account_id bigint REFERENCES accounts (id) ON DELETE SET NULL,
    ADD COLUMN payout_at timestamptz,
    ADD COLUMN payout_by bigint REFERENCES users (id),
    ADD COLUMN counter_signed_at timestamptz,
    ADD COLUMN counter_signed_by bigint REFERENCES users (id);

-- Gegenzeichnung des Empfaengers (Unterschrift als PNG, unveraenderlich).
CREATE TABLE expense_payout_signatures (
    expense_id bigint PRIMARY KEY REFERENCES member_expenses (id) ON DELETE CASCADE,
    signature_png bytea NOT NULL,
    signature_sha256 char(64) NOT NULL,
    signed_place text NOT NULL DEFAULT '' CHECK (length(signed_place) <= 100),
    signed_at timestamptz NOT NULL DEFAULT now()
);
CREATE FUNCTION vk_expense_payout_signature_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Expense payout signatures are immutable';
END;
$$;
CREATE TRIGGER expense_payout_signatures_immutable BEFORE UPDATE OR DELETE ON expense_payout_signatures
    FOR EACH ROW EXECUTE FUNCTION vk_expense_payout_signature_immutable();
CREATE TRIGGER expense_payout_signatures_no_truncate BEFORE TRUNCATE ON expense_payout_signatures
    FOR EACH STATEMENT EXECUTE FUNCTION vk_reject_modification();

ALTER TABLE finance_payment_settings ADD COLUMN expense_counter_signature boolean NOT NULL DEFAULT false;
