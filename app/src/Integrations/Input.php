<?php
declare(strict_types=1);
namespace Vereinskiste\Integrations;

final class Input
{
    public static function read(array $input, array $operation, bool $query = false): array
    {
        $out = [];
        if (array_diff(array_keys($input), array_keys($operation['properties']))) {
            throw new ApiError(422, 'invalid_input', 'Unbekannte Eingabefelder.');
        }
        foreach ($operation['properties'] as $field => $schema) {
            if (!array_key_exists($field, $input)) {
                if (in_array($field, $operation['required'], true)) { throw self::invalid($field); }
                continue;
            }
            $value = $input[$field];
            if ($query && $schema['type'] === 'integer' && is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/D', $value)) { $value = (int) $value; }
            if ($schema['type'] === 'integer') {
                if (!is_int($value) || $value < $schema['minimum'] || $value > $schema['maximum']) { throw self::invalid($field); }
            } elseif ($schema['type'] === 'string') {
                if (!is_string($value) || !\Vereinskiste\Auth\UserRepository::isStorableText($value)) { throw self::invalid($field); }
                $value = trim($value);
                if (mb_strlen($value) < ($schema['minLength'] ?? 0) || mb_strlen($value) > ($schema['maxLength'] ?? 100)) { throw self::invalid($field); }
                if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) { throw self::invalid($field); }
            } elseif ($schema['type'] === 'array') {
                if (!is_array($value) || !array_is_list($value) || count($value) > $schema['maxItems']) { throw self::invalid($field); }
                foreach ($value as $item) {
                    if (!is_string($item) || !in_array($item, $schema['items']['enum'], true)) { throw self::invalid($field); }
                }
            }
            $out[$field] = $value;
        }
        foreach (['from', 'to'] as $field) {
            if (isset($out[$field]) && !\Vereinskiste\Ledger\DateInput::valid($out[$field])) { throw self::invalid($field); }
        }
        return $out;
    }

    private static function invalid(string $field): ApiError
    {
        return new ApiError(422, 'invalid_input', 'Ungültige Eingabe.', [$field => 'foundation.invalid']);
    }
}
