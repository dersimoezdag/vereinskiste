ALTER TABLE club_profile ADD COLUMN logo_dark_id bigint REFERENCES stored_files(id);
ALTER TABLE stored_files DROP CONSTRAINT stored_files_storage_name_check;
ALTER TABLE stored_files ADD CHECK (storage_name ~ '^[a-f0-9]{32}\.(pdf|jpg|png|xml|csv|svg)$');
