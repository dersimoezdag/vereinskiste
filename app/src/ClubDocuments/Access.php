<?php
declare(strict_types=1);
namespace Vereinskiste\ClubDocuments;
use Vereinskiste\Auth\AuthService;
final class Access
{
 public static function canRead(array $document, AuthService $auth, bool $historic=false): bool
 {
  if ($auth->can('documents.confidential')) { return true; }
  if (!$auth->currentUser() || $document['status'] !== 'published') { return false; }
  return $document['kind'] !== 'minutes' || (!$historic && $document['meeting_type'] === 'assembly');
 }
}
