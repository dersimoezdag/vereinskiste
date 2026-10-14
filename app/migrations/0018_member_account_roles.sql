-- English access-role identifiers; board access is derived, never stored manually.
ALTER TABLE user_roles DROP CONSTRAINT user_roles_role_check;
UPDATE user_roles SET role=CASE role WHEN 'kassenwart' THEN 'treasurer' WHEN 'schriftfuehrer' THEN 'secretary' WHEN 'kassenpruefer' THEN 'auditor' ELSE role END;
DELETE FROM user_roles WHERE role='vorstand';
ALTER TABLE user_roles ADD CONSTRAINT user_roles_role_check CHECK(role IN ('admin','treasurer','secretary','auditor'));
ALTER TABLE club_roles ADD COLUMN access_role text CHECK(access_role IN ('board','treasurer','secretary','auditor'));
ALTER TABLE club_roles ADD CONSTRAINT club_role_access_kind CHECK(kind='function' OR access_role IS NULL);
UPDATE club_roles SET name='1. Vorsitzender',system_key='first_chairperson',access_role='board' WHERE id=6;
UPDATE club_roles SET name='2. Vorsitzender',system_key='second_chairperson',access_role='board' WHERE id=7;
UPDATE club_roles SET system_key='finance_board_member',access_role='board' WHERE id=5;

-- users.name/email are a database-maintained login projection for linked accounts.
-- members remain the only editable source; unlinking retains the last projection.
CREATE FUNCTION derive_linked_user_identity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source_name text; source_email text;
BEGIN
 IF NEW.member_id IS NOT NULL THEN
  SELECT name,lower(btrim(data->>'email')) INTO source_name,source_email FROM members WHERE id=NEW.member_id AND anonymized_at IS NULL;
  IF source_name IS NULL OR source_email IS NULL OR source_email !~ '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$' THEN
   RAISE EXCEPTION 'Linked member requires a valid login email' USING ERRCODE='23514',CONSTRAINT='linked_member_identity';
  END IF;
  NEW.name=source_name;NEW.email=source_email;
 END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER users_member_identity BEFORE INSERT OR UPDATE ON users FOR EACH ROW EXECUTE FUNCTION derive_linked_user_identity();
CREATE FUNCTION sync_member_login_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE users SET name=NEW.name,email=lower(btrim(NEW.data->>'email')) WHERE member_id=NEW.id;
 RETURN NEW;
END;
$$;
CREATE TRIGGER members_login_identity AFTER UPDATE OF name,data ON members FOR EACH ROW EXECUTE FUNCTION sync_member_login_identity();
