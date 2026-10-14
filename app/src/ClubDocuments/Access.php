<?php
declare(strict_types=1);
namespace Vereinskiste\ClubDocuments;
use Vereinskiste\Auth\AuthService;
final class Access
{
 public static function canRead(array $document, AuthService $auth, bool $historic=false): bool
 {
  return $auth->can('documents.read',$document+['historic'=>$historic]);
 }
}
