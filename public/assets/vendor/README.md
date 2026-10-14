# Gebündelte Fremd-Assets

Diese Dateien werden unverändert mit der Anwendung ausgeliefert (kein Build auf dem Server, kein Node im Repository).

## Tabler (`tabler/`)

- Paket: `@tabler/core` **1.6.1** (MIT-Lizenz, siehe `tabler/LICENSE`)
- Dateien: `tabler/tabler.min.css`, `tabler/tabler.min.js` (aus `dist/css` bzw. `dist/js`)
- Quelle: `https://registry.npmjs.org/@tabler/core/-/core-1.6.1.tgz`
- Release: `https://github.com/tabler/tabler/releases/tag/%40tabler/core%401.6.1`
- Lizenztext: `https://github.com/tabler/tabler/blob/%40tabler/core%401.6.1/LICENSE`
- Registry-Integrität (vor Entpacken geprüft): `sha512-lgmR9gcxRp5JlLxcmRi9Rz85JrEvH6Bz71KnlcPVSa97wtwQNjgSs3g3fxwbAlulHyRMghhIV04QUuW3pqDsIg==`
- SHA-256 CSS: `370a2d4e608ae518351d10528fc7dbe19b354ae5062c51b2a9cb294850d65403`
- SHA-256 JS: `9d26ca1aa061a0c647305654e32efc2c8838e348b2423e9d1f4593869868e09b`

Aktualisieren: offizielles npm-Paket herunterladen, dessen veröffentlichte Integrität vor dem Entpacken prüfen, Version/Prüfsummen dokumentieren, Lizenz und Oberfläche prüfen. Kein Paketmanager oder Node auf dem Hosting nötig.

## Chart.js (`chartjs/`)

- Paket: `chart.js` **4.4.7** (MIT-Lizenz, siehe `chartjs/LICENSE`)
- Datei: `chartjs/chart.umd.min.js` (aus `dist/chart.umd.js`, unverändert)
- Quelle: `https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.js`
- Lizenztext: `https://github.com/chartjs/Chart.js/blob/v4.4.7/LICENSE.md`
- SHA-256: `2812cb8825fdc57469eb2f7bb055e9429244e599920511ee477e828499b632cb`
- Verwendung: nur das Finanz-Dashboard (`/reports/dashboard`, `public/assets/dashboard.js`). Kein Build, kein React; die Seite bleibt mit der Content-Security-Policy `script-src 'self'` vereinbar.

Aktualisieren: neue Version von jsDelivr/npm laden, Prüfsumme hier eintragen, Dashboard auf allen Zeiträumen prüfen.

## TinyMCE (`tinymce/`)

- Editor: TinyMCE 8.10.0, GPL-2.0-or-later; hier freie Plugins mit `license_key: gpl`.
- Deutsche Community-Übersetzung: tinymce-i18n 26.9.28, Lizenzhinweise unter `tinymce/langs/LICENSE`.
- Beide Pakete sind in `tests/package-lock.json` exakt versioniert. `scripts/vendor-document-editor.cjs` liefert Laufzeitdateien, lesbare JavaScript-Dateien, Übersetzung und Lizenzhinweise aus.
- Vollständiger zugehöriger TinyMCE-Quellcode: `tinymce-source/tinymce-8.10.0.tar.gz`, unverändert von https://github.com/tinymce/tinymce/archive/refs/tags/8.10.0.tar.gz. Der Quellcode wird im Release mitgeliefert.
- Keine Tiny Cloud, keine Premium-Plugins. Der PDF-Export verwendet Dompdf (LGPL), die serverseitige HTML-Bereinigung HTML Purifier (LGPL); die Composer-Lizenzen bleiben im Release erhalten.

SHA-256 TinyMCE-Quellarchiv: `d116ef7dccffe0f1a524ebaa029a08e3c1e055ecd82b700286e1f4b20bc4975d`.
