ALTER TABLE club_document_versions DROP CONSTRAINT club_document_versions_meeting_type_check;
ALTER TABLE club_document_versions ADD CONSTRAINT club_document_versions_meeting_type_check CHECK(meeting_type IN ('board','assembly','club'));
