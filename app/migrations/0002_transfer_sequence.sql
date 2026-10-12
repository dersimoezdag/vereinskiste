-- Umbuchungen: gemeinsame transfer_id beider Buchungen aus einer Sequenz,
-- dazu die Invarianten, die TransferService und BookingService voraussetzen.

CREATE SEQUENCE booking_transfer_id_seq AS bigint;

ALTER TABLE bookings
    -- Umbuchung genau dann, wenn eine transfer_id gesetzt ist
    ADD CONSTRAINT bookings_transfer_link CHECK ((kind = 'transfer') = (transfer_id IS NOT NULL)),
    -- Umbuchungen haben keine Kategorie (sie sind weder Einnahme noch Ausgabe des Vereins)
    ADD CONSTRAINT bookings_transfer_no_category CHECK (kind <> 'transfer' OR category_id IS NULL),
    -- Nur Korrekturbuchungen verweisen auf eine Ursprungsbuchung (der Verweis ist optional)
    ADD CONSTRAINT bookings_correction_of_kind CHECK (correction_of_id IS NULL OR kind = 'correction'),
    ADD CONSTRAINT bookings_version_positive CHECK (version >= 1);
