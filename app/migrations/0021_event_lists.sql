-- Einkaufs-, Pack- und andere Listen einer Veranstaltung oder Vorlage.
CREATE TABLE event_lists (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_id bigint NOT NULL REFERENCES events (id) ON DELETE CASCADE,
    title text NOT NULL CHECK (length(btrim(title)) > 0)
);
CREATE INDEX event_lists_event_idx ON event_lists (event_id, id);

CREATE TABLE event_list_items (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    list_id bigint NOT NULL REFERENCES event_lists (id) ON DELETE CASCADE,
    label text NOT NULL CHECK (length(btrim(label)) > 0),
    quantity text NOT NULL DEFAULT '',
    estimated_cost numeric(12,2) CHECK (estimated_cost >= 0),
    person_name text NOT NULL DEFAULT '',
    done boolean NOT NULL DEFAULT false
);
CREATE INDEX event_list_items_list_idx ON event_list_items (list_id, id);
