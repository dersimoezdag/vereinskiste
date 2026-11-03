# Gebündelte Fremd-Assets

Diese Dateien werden mit der Anwendung ausgeliefert (kein Build auf dem Server).

## Stylesheet und Schriften (`../app.css`, `fonts/`)

- `public/assets/app.css` wird aus `app/styles/app.css` mit Tailwind CSS **4.3.3** (MIT) erzeugt und nicht versioniert. Bauen: `node scripts/build-css.cjs`, beim Entwickeln `node scripts/build-css.cjs --watch`. `scripts/build-release.sh` baut die Datei vor dem Packen.
- Schriften Geist und Geist Mono aus `@fontsource-variable/geist` bzw. `@fontsource-variable/geist-mono` **5.3.0** (SIL Open Font License 1.1, Lizenztexte `fonts/*-LICENSE`), selbst gehostet, kein Abruf bei Dritten. `build-css.cjs` kopiert die Dateien nach `fonts/`.
- Alle Pakete sind in `tests/package-lock.json` exakt versioniert. Auf dem Hosting wird weder Node noch ein Paketmanager gebraucht.

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
