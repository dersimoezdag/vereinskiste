-- Konten- und Kategorienamen sind ohne Beachtung der Gross-/Kleinschreibung eindeutig.
--
-- Die Anwendung prueft Namen mit lower(name); die Datenbank setzt dieselbe Regel durch, damit
-- gleichzeitige Anfragen keine Duplikate erzeugen koennen.
--
--  * accounts: bisher kein Eindeutigkeitsindex -> UNIQUE INDEX auf lower(name).
--  * categories: die case-sensitive Bedingung UNIQUE (name) wird durch den strengeren Index auf
--    lower(name) mitabgedeckt und deshalb entfernt (zwei Regeln fuer dieselbe Spalte waeren
--    redundant und koennten auseinanderlaufen).
--
-- Gibt es bereits Zeilen, die sich nur in Gross-/Kleinschreibung unterscheiden, bricht die
-- Migration mit einer klaren Meldung ab (die Migration ist transaktional, die Datenbank bleibt
-- unveraendert). Es werden keine Daten geloescht oder zusammengefuehrt: Buchungen verweisen auf
-- diese Zeilen, und der Betreiber muss entscheiden, welche Bezeichnung richtig ist (umbenennen
-- und die Migration wiederholen).

DO $$
DECLARE
    dup text;
BEGIN
    SELECT string_agg(names, '; ') INTO dup FROM (
        SELECT string_agg(name, ' / ' ORDER BY id) AS names
          FROM accounts GROUP BY lower(name) HAVING count(*) > 1
    ) d;
    IF dup IS NOT NULL THEN
        RAISE EXCEPTION 'accounts: Kontonamen sind nicht eindeutig, wenn Gross-/Kleinschreibung ignoriert wird (%). Bitte umbenennen und die Migration wiederholen.', dup;
    END IF;

    SELECT string_agg(names, '; ') INTO dup FROM (
        SELECT string_agg(name, ' / ' ORDER BY id) AS names
          FROM categories GROUP BY lower(name) HAVING count(*) > 1
    ) d;
    IF dup IS NOT NULL THEN
        RAISE EXCEPTION 'categories: Kategorienamen sind nicht eindeutig, wenn Gross-/Kleinschreibung ignoriert wird (%). Bitte umbenennen und die Migration wiederholen.', dup;
    END IF;
END
$$;

CREATE UNIQUE INDEX accounts_name_lower_key ON accounts (lower(name));

ALTER TABLE categories DROP CONSTRAINT categories_name_key;
CREATE UNIQUE INDEX categories_name_lower_key ON categories (lower(name));
