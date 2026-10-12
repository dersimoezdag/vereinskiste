<?php
declare(strict_types=1);

namespace Vereinskiste\Auth;

use RuntimeException;

final class UserNotFound extends RuntimeException
{
    public function __construct(public readonly int $userId)
    {
        parent::__construct('Benutzer nicht gefunden: ' . $userId);
    }
}
