# API & MCP

Unter **Einstellungen → API & MCP** bekommt jede Integration einen eigenen Zugang. Die Tabs „Zugänge“, „Anbindung“ und „Aktivität“ enthalten Tokenverwaltung, Endpunkte und die letzten 100 protokollierten Aktionen. Die Datenbankmigration `0027_integrations.sql` muss vor der Nutzung angewendet werden.

## Zugänge und Rechte

Ein Administrator wählt Name, Laufzeit von 1–365 Tagen, API und/oder MCP sowie einzelne Aktionen:

| Recht | Zugriff |
| --- | --- |
| `users.read` | Benutzer-ID, Name, E-Mail und Aktivstatus lesen |
| `users.create` | Neue Benutzer mit explizit erlaubten Rollen anlegen |
| `bookings.read` | Buchungen und vorhandene externe Prüfergebnisse lesen |
| `bookings.review` | Einen Prüfvermerk zu einer bestimmten Buchungsversion hinterlegen; benötigt zusätzlich `bookings.read` |

Die erlaubten Rollen neuer Benutzer werden je Zugang separat festgelegt: Kassenprüfer (`auditor`), Schriftführer (`secretary`) oder Kassenwart (`treasurer`). Ohne Rollen erhalten neue Benutzer keine Anwendungsrechte. Administrator- und Vorstandsrollen sind ausgeschlossen. Es gibt keine API zum Bearbeiten bestehender Benutzerrechte, Ändern von Buchungen oder Auslösen von Zahlungen.

Ein Token wird nach seiner Erstellung genau einmal angezeigt. Dauerhaft gespeichert werden nur sein SHA-256-Hash und ein kurzes Kennungspräfix. Für einen Wechsel zuerst einen neuen Zugang erstellen, die Integration umstellen und den alten Zugang widerrufen. Abgelaufene und widerrufene Tokens sind sofort ungültig. Aktuelle Benutzerrechte des Erstellers begrenzen zusätzlich die freigegebenen Aktionen; eine Deaktivierung des Erstellers deaktiviert seine Zugänge.

## REST

Alle Aufrufe erfolgen serverseitig über HTTPS mit `Authorization: Bearer <TOKEN>`. Token gehören weder in URLs noch in Browser-JavaScript. Cookie-Logins ersetzen den Token nicht. Der Token muss im Hosting als Authorization-Header bei PHP ankommen; die mitgelieferte Apache-Konfiguration reicht ihn an PHP weiter. Bei bestehenden Installationen beachten: automatische Updates ersetzen die ursprüngliche `public/.htaccess` nicht. Falls das Hosting den Header bisher entfernt, die neue Weiterleitungsregel dort übernehmen. Bei Nginx/PHP-FPM gegebenenfalls `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` konfigurieren.

`GET /api/v1/capabilities` zeigt die wirksamen Rechte und erlaubten Benutzerrollen. `GET /api/v1/openapi.json` liefert eine OpenAPI-3.1-Beschreibung der für diesen Zugang freigegebenen Geschäftsaktionen. Ohne Token sind beide Endpunkte nicht erreichbar.

| Methode | Endpunkt | Aktion |
| --- | --- | --- |
| GET | `/api/v1/users?limit=25&offset=0` | `users_list` |
| POST | `/api/v1/users` | `users_create` |
| GET | `/api/v1/bookings?limit=25&offset=0` | `bookings_list` |
| GET | `/api/v1/bookings/{id}` | `bookings_get` |
| POST | `/api/v1/bookings/{id}/reviews` | `bookings_review` |

Listen enthalten `items`, `limit` und `offset`; maximal 100 Datensätze je Aufruf. Die Buchungsliste unterstützt zusätzlich `from`, `to` (ISO-Datum), `account_id`, `q`, `direction` (`in`/`out`) und `status` (`active`/`cancelled`). Unbekannte Felder oder ungültige Werte werden zurückgewiesen.

POST benötigt `Content-Type: application/json` und ein JSON-Objekt. Höchstens 64 KiB pro Anfrage. Das Budget beträgt 120 Anfragen pro Token, 600 pro IP-Adresse und 3.000 insgesamt je Minute; bei Überschreitung kommt `429` mit `Retry-After: 60`.

### Benutzer anlegen

```json
{
  "name": "Erika Muster",
  "email": "erika@example.org",
  "roles": [],
  "request_id": "wordpress-user-42"
}
```

Ergebnis (`201`):

```json
{"id":42,"roles":[],"active":true,"password_setup_required":true}
```

Das zufällige interne Passwort wird nicht ausgegeben. Die Einrichtung erfolgt über die vorhandene Passwort-Zurücksetzen-Funktion bei eingerichtetem Systemversand oder durch einen Administrator in der Benutzerverwaltung. Es wird keine automatische Einladungsmail versendet.

### Buchung prüfen

Zuerst `GET /api/v1/bookings/123` aufrufen und `booking.version` auslesen. Anschließend:

```json
{
  "version": 1,
  "status": "needs_review",
  "note": "Belegnummer und Betrag bitte abgleichen.",
  "request_id": "review-booking-123-v1"
}
```

`status` ist `checked` oder `needs_review`. Der Prüfvermerk erscheint auf der Buchungsdetailseite mit Integration, Datum und Buchungsversion. Bei später geänderter Buchung wird er als veraltet gekennzeichnet. Er ändert keine Buchungsdaten und erteilt keine Zahlungsfreigabe. Belege und Dokumentdateien werden über diese Schnittstelle nicht herausgegeben.

### Wiederholungen und Fehler

Jede Schreibaktion benötigt eine eindeutige `request_id` (maximal 100 Zeichen). Gleicher Zugang, gleiche Kennung und dieselbe Eingabe liefern dasselbe Ergebnis – auch bei Wiederholung über den jeweils anderen freigegebenen Transport. Die Kennung darf nicht für andere Eingaben oder Aktionen wiederverwendet werden; dafür kommt `409`. Eine geänderte Buchungsversion ergibt ebenfalls `409`. Eine Schreibaktion und ihr gespeichertes Wiederholungsergebnis bilden eine Transaktion.

Fehler verwenden `{"error":{"code":"scope_denied","message":"…","fields":{}}}`. Statuscodes: `401` ungültiger Token, `403` fehlendes Recht/unerlaubte Rolle/Origin, `404` nicht gefunden, `409` Konflikt, `415` falscher Content-Type und `422` ungültige Eingabe. Protokolliert werden Integration, Aktion, Transport und Ergebnisstatus, keine Tokens oder Eingabeinhalte. Wiederholungen sind im Audit-Protokoll zusätzlich als solche markiert.

## Beispiel: WordPress

Ein WordPress-Plugin kann mit dem Recht `users.create` provisionieren. Den Token ausschließlich in geschützter serverseitiger Konfiguration ablegen, beispielsweise als Konstante `VK_API_TOKEN` außerhalb des Plugin-Quellcodes. Dies ist ein Anbindungsbeispiel, kein bereits installiertes WordPress-Plugin:

```php
$response = wp_remote_post('https://verein.example/api/v1/users', [
    'timeout' => 20,
    'headers' => [
        'Authorization' => 'Bearer ' . VK_API_TOKEN,
        'Content-Type' => 'application/json',
    ],
    'body' => wp_json_encode([
        'name' => $wp_user->display_name,
        'email' => $wp_user->user_email,
        'roles' => [],
        'request_id' => 'wp-' . get_current_blog_id() . '-user-' . $wp_user->ID,
    ]),
]);
if (is_wp_error($response)) {
    // Mit unveränderter request_id und identischen Daten erneut versuchen.
} elseif (wp_remote_retrieve_response_code($response) === 201) {
    $created = json_decode(wp_remote_retrieve_body($response), true);
    // $created['id'] beim WordPress-Benutzer als externe ID speichern.
}
```

Eine eigenständige Anbindung muss zusätzlich ihre eigene WordPress-Berechtigungsprüfung vorsehen; öffentliche WordPress-Endpunkte dürfen keinen unbeschränkten Zugriff auf diesen Token vermitteln.

## MCP

Endpunkt: `POST /mcp`, stateless Streamable HTTP mit JSON-Antworten. Ein MCP-Client muss eigene Bearer-Header unterstützen. OAuth-Discovery/-Anmeldung, SSE-Streams, Ressourcen und Prompts sind nicht implementiert. Authentifiziertes GET und DELETE ergeben `405` mit `Allow: POST`.

```http
Authorization: Bearer <TOKEN>
Content-Type: application/json
Accept: application/json, text/event-stream
MCP-Protocol-Version: 2025-11-25
```

Handshake:

```json
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"mein-agent","version":"1.0"}}}
```

Danach `notifications/initialized` senden (Antwort `202`, leer). `tools/list` zeigt nur erlaubte Tools. Die Tool-Namen entsprechen der REST-Tabelle. `tools/call` verwendet dieselben Eingaben; bei `bookings_get` und `bookings_review` kommt die numerische `id` zusätzlich in `arguments`:

```json
{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"bookings_get","arguments":{"id":123}}}
```

Ergebnisse enthalten Text und `structuredContent`. Fachliche Fehler werden mit `isError: true` zurückgegeben; Rechte werden auch bei direkt geratenen Tool-Namen geprüft. Ein vorhandener fremder Origin-Header wird abgewiesen; es gibt keine freigegebene Browser-CORS-Schnittstelle. Freitexte aus Benutzern/Buchungen sind untrusted content und dürfen vom Agenten nicht als Handlungsanweisungen behandelt werden.

Technische Referenzen: [MCP Streamable HTTP](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports), [MCP Tools](https://modelcontextprotocol.io/specification/2025-11-25/server/tools), [WordPress HTTP API](https://developer.wordpress.org/reference/functions/wp_remote_post/).
