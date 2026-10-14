# Rollen und verknüpfte Identitäten

Benutzer und Mitglieder sind optional 1:1 verknüpft. Unverknüpfte Benutzer pflegen Name und Login-E-Mail selbst. Bei verknüpften Benutzern ist das Mitglied die maßgebliche Quelle für Personendaten. Name und normalisierte E-Mail werden von Datenbank-Triggern als Login-Projektion in users gehalten; direkte Änderungen am Benutzer können diese Quelle nicht überschreiben. Weitere Personendaten zeigt die Benutzerverwaltung ausschließlich aus dem Mitglied an. Beim Lösen bleiben der letzte Name und die letzte Login-Adresse erhalten.

Fehlende, ungültige oder für einen anderen Benutzer vergebene Mitglieds-E-Mails verhindern Verknüpfungen. Eine kollidierende E-Mail-Änderung wird zusammen mit der Mitgliedsänderung zurückgerollt. Änderungen der Login-E-Mail verknüpfter Mitglieder benötigen users.manage, damit Mitglieds-Schreibrechte keine Übernahme anderer Konten ermöglichen.

Manuelle Rollen: admin, treasurer, secretary, auditor. board ist ausschließlich abgeleitet. Aktive Funktionen mit access_role vergeben die fachliche Rolle an den verknüpften Benutzer: Funktionsdefinition aktiv, Mitglied nicht anonymisiert, Startdatum erreicht, Enddatum noch nicht erreicht. Das Enddatum ist der erste Tag ohne diese Funktion. first_chairperson, second_chairperson und finance_board_member vergeben derzeit identisch board. Sichtbare Funktionsnamen bestimmen keine Rechte. Benutzer ohne Mitglied erhalten keine abgeleiteten Rollen; Mitglieder ohne Benutzer haben keinen Login.

Admin erhält alle definierten Rechte, bleibt unabhängig von Mitglied und Funktionen und wird niemals automatisch aus board vergeben. Board hat fachliche Rechte, jedoch keine Benutzer-/Systemverwaltung. Die zentrale Entscheidung erfolgt in Auth/AccessControl.php über AuthService::can(permission, resource). Routing, Twig-Schaltflächen und Dokumentzugriff nutzen diese Prüfung. Unbekannte Rechte, inaktive Benutzer und fehlende Anmeldung werden abgewiesen.

Funktionen mit access_role dürfen nur mit functions.manage zugewiesen oder beendet werden. Zunächst besitzt nur Admin dieses Recht; dadurch kann ein Schriftführer sich nicht über die Mitgliedsverwaltung zusätzliche Rechte geben. Andere Vereinsfunktionen und Mitgliedsarten bleiben über members.write pflegbar.

Migration 0018_member_account_roles.sql führt englische Rollenkennungen und Funktionszuordnungen ein. Es existieren keine Alias-Rollen oder Kompatibilitätszweige für das alte Rollenmodell. Der Schutz des letzten aktiven Administrators bleibt bestehen.
