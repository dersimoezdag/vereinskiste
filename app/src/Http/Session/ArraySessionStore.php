<?php
declare(strict_types=1);

namespace Vereinskiste\Http\Session;

/** Speicher im Arbeitsspeicher; fuer Tests (eine Instanz entspricht einem Browser). */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string,mixed> */
    private array $data = [];
    private int $generation = 0;

    public function configure(bool $secure): void
    {
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->generation++;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->generation++;
    }

    public function commit(): void
    {
    }

    /** Aendert sich bei jeder Rotation der Sitzungs-ID. */
    public function id(): string
    {
        return 'array-session-' . $this->generation;
    }
}
