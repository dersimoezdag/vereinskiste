<?php
declare(strict_types=1);
namespace Vereinskiste\Integrations;

final class OpenApi
{
    public static function document(array $scopes): array
    {
        $paths = [];
        foreach (Catalog::operations() as $name => $op) {
            if (!in_array($op['scope'], $scopes, true)) { continue; }
            $properties = $op['properties'];
            $entry = ['operationId' => $name, 'description' => $op['description'], 'responses' => [
                (string) ($op['method'] === 'GET' ? 200 : 201) => ['description' => 'Ergebnis der Aktion.'],
                '401' => ['description' => 'Token ungültig, abgelaufen oder widerrufen.'],
                '403' => ['description' => 'Aktion oder Rolle nicht freigegeben.'],
                '409' => ['description' => 'Buchungsversion oder request_id im Konflikt.'],
                '422' => ['description' => 'Ungültige Eingabe.'],
            ]];
            if (isset($properties['id'])) {
                $entry['parameters'][] = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => $properties['id']];
                unset($properties['id']);
            }
            if ($op['method'] === 'GET') {
                foreach ($properties as $key => $schema) { $entry['parameters'][] = ['name' => $key, 'in' => 'query', 'required' => false, 'schema' => $schema]; }
            } else {
                $entry['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'properties' => (object) $properties, 'additionalProperties' => false,
                    'required' => array_values(array_diff($op['required'], ['id'])),
                ]]]];
            }
            $paths[substr($op['path'], strlen('/api/v1'))][strtolower($op['method'])] = $entry;
        }
        return ['openapi' => '3.1.0', 'info' => ['title' => 'Vereinskiste API', 'version' => '1'], 'servers' => [['url' => '/api/v1']],
            'security' => [['bearerAuth' => []]], 'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]], 'paths' => (object) $paths];
    }
}
