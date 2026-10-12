<?php
declare(strict_types=1);

namespace Vereinskiste\Http\Session;

interface SessionStore
{
    /** Vor der ersten Nutzung: Cookie nur ueber HTTPS als "Secure" markieren. */
    public function configure(bool $secure): void;

    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** Neue Sitzungs-ID bei gleichem Inhalt (Schutz vor Session-Fixation). */
    public function regenerate(): void;

    /** Inhalt und Cookie verwerfen. */
    public function destroy(): void;

    /** Sitzung schreiben und freigeben (am Ende des Requests). */
    public function commit(): void;
}
