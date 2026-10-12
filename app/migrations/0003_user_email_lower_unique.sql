-- Benutzerverwaltung: E-Mail-Adressen sind ohne Beachtung der Gross-/Kleinschreibung eindeutig.
--
-- Die Anwendung speichert Adressen getrimmt und kleingeschrieben und sucht mit lower(email).
-- Bestehende Zeilen werden hier angeglichen. Gibt es Zeilen, die sich nur in Gross-/Kleinschreibung
-- oder Leerraum unterscheiden, bricht die Migration mit einer klaren Meldung ab (die Migration ist
-- transaktional, die Datenbank bleibt unveraendert); solche Konten muss der Betreiber vorher
-- bereinigen, weil nicht entschieden werden kann, welches das richtige ist.

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM users GROUP BY lower(btrim(email)) HAVING count(*) > 1
    ) THEN
        RAISE EXCEPTION 'users: E-Mail-Adressen sind nicht eindeutig, wenn Gross-/Kleinschreibung und Leerraum ignoriert werden. Bitte doppelte Konten bereinigen und die Migration wiederholen.';
    END IF;
END
$$;

UPDATE users SET email = lower(btrim(email)) WHERE email <> lower(btrim(email));

CREATE UNIQUE INDEX users_email_lower_key ON users (lower(email));
