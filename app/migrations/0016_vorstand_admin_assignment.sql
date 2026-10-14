-- Bestehende Vollrechte als explizite, danach unabhaengig entziehbare Admin-Rolle erhalten.
INSERT INTO user_roles (user_id, role)
SELECT user_id, 'admin' FROM user_roles WHERE role = 'vorstand'
ON CONFLICT (user_id, role) DO NOTHING;
