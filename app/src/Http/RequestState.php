<?php
declare(strict_types=1);

namespace Vereinskiste\Http;

use Vereinskiste\Auth\AuthService;

/** Zustand des aktuellen Requests fuer die Twig-Funktionen (PHP bearbeitet Requests nacheinander). */
final class RequestState
{
    public ?AuthService $auth = null;
}
