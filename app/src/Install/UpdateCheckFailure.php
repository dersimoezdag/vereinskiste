<?php
declare(strict_types=1);
namespace Vereinskiste\Install;

final class UpdateCheckFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason) { parent::__construct('Updateprüfung fehlgeschlagen.'); }
}
