-- Namen getrennt speichern: Anrede, Titel, Vorname, Nachname.
--
-- Die Felder liegen wie die uebrigen Stammdaten in `data` (members, membership_applications). Die Spalten
-- `members.name` bzw. `membership_applications.applicant_name` bleiben der abgeleitete Anzeigename
-- (Titel Vorname Nachname) fuer Suche, Listen und Verknuepfungen.
--
-- Bestehende Namen werden am letzten Leerzeichen geteilt: das letzte Wort ist der Nachname, der Rest der
-- Vorname. Anrede und Titel bleiben leer und koennen nachgetragen werden. Das fruehere Feld `name` in
-- `data` entfaellt, damit der Aenderungsverlauf keinen Scheinunterschied zeigt.

UPDATE members
   SET data = (jsonb_build_object(
           'salutation', '',
           'title', '',
           'first_name', btrim(regexp_replace(btrim(name), '\s*\S+$', '')),
           'last_name', COALESCE(substring(btrim(name) FROM '(\S+)$'), '')
       ) || data) - 'name'
 WHERE NOT (data ? 'last_name');

UPDATE membership_applications
   SET data = (jsonb_build_object(
           'salutation', '',
           'title', '',
           'first_name', btrim(regexp_replace(btrim(applicant_name), '\s*\S+$', '')),
           'last_name', COALESCE(substring(btrim(applicant_name) FROM '(\S+)$'), '')
       ) || data) - 'name'
 WHERE NOT (data ? 'last_name');
