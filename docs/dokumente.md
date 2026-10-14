# Dokumente

Menü: Dokumente → Protokolle, Satzung, Geschäftsordnung. Protokolle werden nach Sitzungsdatum absteigend angezeigt. Satzung und Geschäftsordnung haben jeweils eine aktuelle freigegebene Fassung. Neue Entwürfe verdrängen sie nicht; bei Freigabe wird die vorherige Fassung im Archiv abrufbar. Freigabe ist unmittelbar wirksam; „Gültig ab“ dokumentiert den Beschluss und löst keine automatische zeitgesteuerte Freigabe aus.

Vorstand und Schriftführer bearbeiten alle Dokumente und lesen vertrauliche Protokolle sowie Entwürfe. Andere angemeldete Benutzer sehen freigegebene Satzungen und Geschäftsordnungen einschließlich früherer freigegebener Fassungen sowie freigegebene Mitgliederversammlungsprotokolle. Admin darf alle Dokumente lesen und bearbeiten. Vorstand wird aus aktiven Vereinsfunktionen des verknüpften Mitglieds abgeleitet.

Alle Inhalte liegen in PostgreSQL: Text, Metadaten und unveränderliche Versionsdatensätze; PDFs bis 10 MB werden binär in kleinen Datenbankblöcken gespeichert. Jeweils neue Originaldateien bleiben für frühere Fassungen erhalten. Metadatenänderungen können dieselbe unveränderliche Datei referenzieren. Datenbanksicherungen enthalten alle Dokumentinhalte. Konflikte bei gleichzeitigen Änderungen werden abgelehnt.

TinyMCE 8.10.0 ist lokal mit freien Plugins und deutscher Community-Übersetzung eingebunden. HTML Purifier bereinigt Editor-Inhalte, Dompdf exportiert A4-PDFs ohne externe Ressourcen. PDF-Uploads bleiben Originale und werden nicht in Editor-Text umgewandelt. Bilder und eingebettete Frames werden nicht übernommen. PHP-DOM muss verfügbar sein. Auf dem Hosting sind weder Node noch ein zusätzlicher Dienst erforderlich.

DOCX-Dateien bis 10 MB können als Editor-Text importiert werden. Absätze, Fett/Kursiv/Unterstreichung und Tabellen werden übernommen. Bilder, Kopf-/Fußzeilen und das genaue Word-Seitenlayout werden nicht übernommen. Die DOCX-Originaldatei wird nicht gespeichert. PHP-ZIP muss verfügbar sein.

Lizenz: TinyMCE GPL-2.0-or-later (GPLv3 kompatibel mit AGPLv3). Vollständiger Quellcode und Lizenzhinweise sind enthalten: `/assets/vendor/tinymce-source/tinymce-8.10.0.tar.gz`. Migration: `0017_club_documents.sql`. Keine Kompatibilitätsschicht für frühere Installationen.
