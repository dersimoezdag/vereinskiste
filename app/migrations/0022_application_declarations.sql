-- Frei festlegbare Erklaerungen im Mitgliedsantrag mit digitaler Unterschrift.
CREATE TABLE application_declarations (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    position integer NOT NULL,
    active boolean NOT NULL DEFAULT true,
    current_version integer NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE application_declaration_versions (
    declaration_id bigint NOT NULL REFERENCES application_declarations (id),
    version integer NOT NULL CHECK (version > 0),
    title text NOT NULL CHECK (length(btrim(title)) BETWEEN 1 AND 200),
    content text NOT NULL CHECK (length(content) <= 20000),
    kind text NOT NULL CHECK (kind IN ('confirmation', 'consent', 'notice')),
    signature_required boolean NOT NULL,
    actor_id bigint REFERENCES users (id), -- NULL beim Standardsatz der Migration
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (declaration_id, version)
);
ALTER TABLE application_declarations ADD FOREIGN KEY (id, current_version)
    REFERENCES application_declaration_versions (declaration_id, version) DEFERRABLE INITIALLY DEFERRED;
CREATE TRIGGER application_declaration_versions_immutable BEFORE UPDATE OR DELETE ON application_declaration_versions
    FOR EACH ROW EXECUTE FUNCTION vk_reject_modification();
CREATE TRIGGER application_declaration_versions_no_truncate BEFORE TRUNCATE ON application_declaration_versions
    FOR EACH STATEMENT EXECUTE FUNCTION vk_reject_modification();

CREATE TABLE application_signatures (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES membership_applications (id),
    declaration_id bigint NOT NULL,
    version integer NOT NULL,
    granted boolean, -- NULL bei Hinweisen
    snapshot jsonb NOT NULL CHECK (jsonb_typeof(snapshot) = 'object'),
    snapshot_hash char(64) NOT NULL,
    signature_png bytea,
    signature_sha256 char(64),
    signed_place text NOT NULL DEFAULT '' CHECK (length(signed_place) <= 100),
    signed_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (declaration_id, version) REFERENCES application_declaration_versions (declaration_id, version),
    CHECK ((signature_png IS NULL) = (signature_sha256 IS NULL)),
    UNIQUE (application_id, declaration_id)
);
CREATE FUNCTION vk_signature_changes() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    -- Einzige erlaubte Aenderung: Schwaerzen nach Anonymisierung des Antrags.
    IF TG_OP = 'UPDATE' AND NEW.signature_png IS NULL AND NEW.signature_sha256 IS NULL
       AND NEW.signed_place = '' AND NEW.snapshot = '{}'::jsonb
       AND (to_jsonb(NEW) - ARRAY['signature_png', 'signature_sha256', 'signed_place', 'snapshot', 'snapshot_hash'])
         = (to_jsonb(OLD) - ARRAY['signature_png', 'signature_sha256', 'signed_place', 'snapshot', 'snapshot_hash'])
       AND EXISTS (SELECT 1 FROM membership_applications WHERE id = OLD.application_id AND anonymized_at IS NOT NULL)
    THEN RETURN NEW; END IF;
    RAISE EXCEPTION 'Application signatures are immutable';
END;
$$;
CREATE TRIGGER application_signatures_immutable BEFORE UPDATE OR DELETE ON application_signatures
    FOR EACH ROW EXECUTE FUNCTION vk_signature_changes();
CREATE TRIGGER application_signatures_no_truncate BEFORE TRUNCATE ON application_signatures
    FOR EACH STATEMENT EXECUTE FUNCTION vk_reject_modification();

ALTER TABLE member_consents DROP CONSTRAINT member_consents_kind_check;
ALTER TABLE member_consents ADD CONSTRAINT member_consents_kind_check
    CHECK (kind IN ('statute', 'privacy', 'media', 'newsletter', 'sepa', 'declaration'));
ALTER TABLE member_consents ADD COLUMN declaration_id bigint REFERENCES application_declarations (id);
ALTER TABLE member_consents ADD CONSTRAINT member_consents_declaration_check
    CHECK ((kind = 'declaration') = (declaration_id IS NOT NULL));

-- Standardsatz nach dem Papierformular; vereinsspezifische Angaben als Luecken.
WITH d AS (INSERT INTO application_declarations (position, current_version) VALUES (1, 1) RETURNING id)
INSERT INTO application_declaration_versions (declaration_id, version, title, content, kind, signature_required)
SELECT id, 1, 'Antrag auf Mitgliedschaft',
    '<p>Hiermit beantrage ich die Aufnahme in den {verein}.</p><p>Mit meiner Unterschrift erkenne ich die Satzung sowie die Geschäftsordnungen des Vereins in ihrer jeweils gültigen Fassung als verbindlich an. Die aktuelle Satzung habe ich zur Kenntnis genommen.</p>',
    'confirmation', true FROM d;

WITH d AS (INSERT INTO application_declarations (position, current_version) VALUES (2, 1) RETURNING id)
INSERT INTO application_declaration_versions (declaration_id, version, title, content, kind, signature_required)
SELECT id, 1, 'Einwilligung zur vereinsinternen Kommunikation',
    '<p>Ich bin damit einverstanden, dass meine Telefonnummer und E-Mail-Adresse vom Vorstand zur Organisation der Vereinsarbeit genutzt und – soweit erforderlich – an andere Vereinsmitglieder weitergegeben werden dürfen (z. B. zur Teilnahme an Kommunikationsgruppen).</p><p>Diese Einwilligung ist freiwillig und kann jederzeit mit Wirkung für die Zukunft ganz oder teilweise widerrufen werden.</p>',
    'consent', false FROM d;

WITH d AS (INSERT INTO application_declarations (position, current_version) VALUES (3, 1) RETURNING id)
INSERT INTO application_declaration_versions (declaration_id, version, title, content, kind, signature_required)
SELECT id, 1, 'Datenschutzhinweise gemäß Art. 13 DSGVO',
    '<h3>1. Verantwortlicher</h3><p>{verein}<br>[Vertretung, Anschrift und E-Mail-Adresse des Vereins eintragen]</p>'
 || '<h3>2. Zweck der Datenverarbeitung</h3><p>Die Verarbeitung personenbezogener Daten erfolgt ausschließlich zur Begründung und Durchführung des Mitgliedschaftsverhältnisses, zur Mitgliederverwaltung, zur Organisation und Durchführung von Vereinstätigkeiten sowie zur Erfüllung gesetzlicher Pflichten.</p>'
 || '<h3>3. Art der verarbeiteten Daten</h3><ul><li>Name, Vorname</li><li>Anschrift</li><li>Geburtsdatum</li><li>Telefonnummer</li><li>E-Mail-Adresse</li></ul><p>Die Bereitstellung dieser Daten ist für den Abschluss der Mitgliedschaft erforderlich.</p>'
 || '<h3>4. Rechtsgrundlagen der Verarbeitung</h3><ul><li>Art. 6 Abs. 1 lit. b DSGVO (Erfüllung des Mitgliedschaftsvertrags)</li><li>Art. 6 Abs. 1 lit. c DSGVO (gesetzliche Verpflichtungen)</li><li>Art. 6 Abs. 1 lit. a DSGVO (Einwilligung, z. B. Weitergabe von Kontaktdaten oder Bildveröffentlichungen)</li></ul>'
 || '<h3>5. Empfänger der Daten</h3><p>Personenbezogene Daten erhalten Vorstandsmitglieder sowie Funktions- und Amtsträger des Vereins, soweit dies zur Aufgabenwahrnehmung erforderlich ist. Eine Weitergabe an öffentliche Stellen erfolgt nur bei gesetzlicher Verpflichtung. Eine Übermittlung in Drittstaaten außerhalb der EU bzw. des EWR findet nicht statt.</p>'
 || '<h3>6. Speicherdauer</h3><p>Die personenbezogenen Daten werden für die Dauer der Mitgliedschaft gespeichert. Nach Beendigung der Mitgliedschaft werden die Daten grundsätzlich innerhalb eines Monats gelöscht, sofern keine gesetzlichen Aufbewahrungspflichten bestehen. Daten mit gesetzlichen Aufbewahrungspflichten werden nach Ablauf der Fristen gelöscht.</p>'
 || '<h3>7. Rechte der betroffenen Person</h3><ul><li>Recht auf Auskunft (Art. 15 DSGVO)</li><li>Recht auf Berichtigung (Art. 16 DSGVO)</li><li>Recht auf Löschung (Art. 17 DSGVO)</li><li>Recht auf Einschränkung der Verarbeitung (Art. 18 DSGVO)</li><li>Recht auf Datenübertragbarkeit (Art. 20 DSGVO)</li><li>Recht auf Widerspruch (Art. 21 DSGVO)</li><li>Recht auf Beschwerde bei einer Datenschutzaufsichtsbehörde (Art. 77 DSGVO)</li></ul><p>Zudem haben Sie das Recht, eine erteilte Einwilligung jederzeit mit Wirkung für die Zukunft zu widerrufen. Die Rechtmäßigkeit der bis zum Widerruf erfolgten Verarbeitung bleibt unberührt.</p>',
    'notice', true FROM d;

WITH d AS (INSERT INTO application_declarations (position, current_version) VALUES (4, 1) RETURNING id)
INSERT INTO application_declaration_versions (declaration_id, version, title, content, kind, signature_required)
SELECT id, 1, 'Einwilligung in die Veröffentlichung von Personenbildnissen',
    '<p>Freiwillig, nicht Voraussetzung für die Mitgliedschaft.</p><p>Ich willige ein, dass im Rahmen von Veranstaltungen des Vereins Foto- und Videoaufnahmen meiner Person angefertigt und insbesondere auf der Website sowie auf Social-Media-Kanälen des Vereins veröffentlicht werden dürfen.</p><p>Mir ist bewusst, dass veröffentlichte Aufnahmen weltweit abrufbar sein können, eine Weiterverwendung oder Veränderung durch Dritte nicht ausgeschlossen werden kann und eine vollständige Löschung im Internet nicht in jedem Fall gewährleistet werden kann.</p><p>Die Einwilligung gilt zeitlich unbeschränkt und kann jederzeit mit Wirkung für die Zukunft schriftlich gegenüber dem Vorstand widerrufen werden.</p>',
    'consent', true FROM d;
