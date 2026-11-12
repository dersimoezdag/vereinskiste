CREATE TABLE expense_documents (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    expense_id bigint NOT NULL REFERENCES member_expenses(id) ON DELETE CASCADE,
    file_id bigint NOT NULL UNIQUE REFERENCES stored_files(id),
    review_status text NOT NULL DEFAULT 'pending' CHECK (review_status IN ('pending', 'reviewed')),
    reviewed_by bigint REFERENCES users(id),
    reviewed_at timestamptz,
    UNIQUE (expense_id, file_id)
);
CREATE INDEX expense_documents_expense_idx ON expense_documents(expense_id);
