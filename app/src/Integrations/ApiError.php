<?php
declare(strict_types=1);
namespace Vereinskiste\Integrations;

final class ApiError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $error, string $message, public readonly array $fields = [])
    {
        parent::__construct($message);
    }

    public function data(): array
    {
        return ['code' => $this->error, 'message' => $this->getMessage(), 'fields' => (object) $this->fields];
    }
}
