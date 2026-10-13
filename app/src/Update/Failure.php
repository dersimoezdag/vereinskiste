<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

final class Failure extends \RuntimeException
{
    public function __construct(public readonly string $reason) { parent::__construct('Automatisches Update: ' . $reason); }
}
