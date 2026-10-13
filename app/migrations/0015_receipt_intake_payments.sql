ALTER TABLE invoice_documents ADD COLUMN security_status text NOT NULL DEFAULT 'legacy'
    CHECK(security_status IN ('legacy','pending','clean','blocked','error'));
ALTER TABLE invoice_documents ADD COLUMN security_checked_at timestamptz;
ALTER TABLE invoice_documents ALTER COLUMN security_status SET DEFAULT 'pending';
CREATE TABLE receipt_mail_messages (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    mailbox_key char(64) NOT NULL,
    uid_validity bigint NOT NULL,
    uid bigint NOT NULL,
    sender text NOT NULL DEFAULT '', subject text NOT NULL DEFAULT '', body text NOT NULL DEFAULT '',
    suggested_member_id bigint REFERENCES members(id),
    invoice_id bigint REFERENCES invoices(id),
    status text NOT NULL CHECK(status IN ('received','imported','rejected')),
    error_code text,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(mailbox_key,uid_validity,uid)
);
CREATE TABLE invoice_payment_requests (
    invoice_id bigint PRIMARY KEY REFERENCES invoices(id),
    kind text NOT NULL CHECK(kind IN ('supplier','reimbursement')),
    member_id bigint REFERENCES members(id),
    recipient text NOT NULL, iban text NOT NULL, purpose text NOT NULL,
    status text NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','approved','rejected')),
    version integer NOT NULL DEFAULT 1,
    created_by bigint NOT NULL REFERENCES users(id),
    approved_by bigint REFERENCES users(id), approved_at timestamptz,
    CHECK(kind<>'reimbursement' OR member_id IS NOT NULL),
    CHECK(approved_by IS NULL OR approved_by<>created_by)
);
CREATE TABLE invoice_payment_allocations (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id bigint NOT NULL REFERENCES invoice_payment_requests(invoice_id),
    booking_id bigint NOT NULL UNIQUE REFERENCES bookings(id),
    amount numeric(12,2) NOT NULL CHECK(amount>0),
    actor_id bigint NOT NULL REFERENCES users(id), at timestamptz NOT NULL DEFAULT now(),
    reason text NOT NULL
);
CREATE TABLE invoice_payment_changes (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id bigint NOT NULL REFERENCES invoices(id),
    actor_id bigint NOT NULL REFERENCES users(id), at timestamptz NOT NULL DEFAULT now(),
    reason text NOT NULL, before_data jsonb NOT NULL, after_data jsonb NOT NULL
);
CREATE TRIGGER invoice_payment_changes_immutable BEFORE UPDATE OR DELETE ON invoice_payment_changes
    FOR EACH ROW EXECUTE FUNCTION vk_reject_modification();
CREATE TRIGGER invoice_payment_changes_no_truncate BEFORE TRUNCATE ON invoice_payment_changes
    FOR EACH STATEMENT EXECUTE FUNCTION vk_reject_modification();
