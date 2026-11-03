-- Strasse und Hausnummer getrennt speichern. Nur eindeutige Faelle aufteilen:
-- Adresse endet auf eine Hausnummer (z. B. 12, 3a, 10-12, 4/1) und es gibt noch keine Hausnummer.
CREATE FUNCTION vk_split_house_number(data jsonb) RETURNS jsonb LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN m IS NULL THEN data
        ELSE data || jsonb_build_object('address', m[1], 'house_number', m[2]) END
    FROM (SELECT CASE WHEN coalesce(data->>'house_number', '') = ''
        THEN regexp_match(btrim(coalesce(data->>'address', '')), '^(.*\S)\s+(\d+\s?[a-zA-Z]?(?:\s?[-/]\s?\d+\s?[a-zA-Z]?)?)$') END AS m) s
$$;
UPDATE members SET data = vk_split_house_number(data), version = version + 1 WHERE anonymized_at IS NULL AND vk_split_house_number(data) <> data;
UPDATE finance_contacts SET data = vk_split_house_number(data), version = version + 1 WHERE vk_split_house_number(data) <> data;
UPDATE club_profile SET data = vk_split_house_number(data), version = version + 1 WHERE vk_split_house_number(data) <> data;
UPDATE membership_applications SET data = vk_split_house_number(data), version = version + 1
    WHERE status = 'pending' AND anonymized_at IS NULL AND vk_split_house_number(data) <> data;
DROP FUNCTION vk_split_house_number(jsonb);
