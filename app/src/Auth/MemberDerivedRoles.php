<?php
declare(strict_types=1);
namespace Vereinskiste\Auth;
use PDO;
final class MemberDerivedRoles
{
 public function __construct(private PDO $pdo) {}
 public function sources(int $userId): array
 {
  $stmt=$this->pdo->prepare("SELECT DISTINCT r.access_role AS role,r.system_key,r.name AS function_name
   FROM users u JOIN members m ON m.id=u.member_id JOIN member_club_roles a ON a.member_id=m.id JOIN club_roles r ON r.id=a.role_id
   WHERE u.id=? AND m.anonymized_at IS NULL AND r.active AND r.kind='function' AND r.access_role IS NOT NULL
   AND (a.start_date IS NULL OR a.start_date<=CURRENT_DATE) AND (a.end_date IS NULL OR a.end_date>CURRENT_DATE) ORDER BY r.access_role,r.name");
  $stmt->execute([$userId]);return $stmt->fetchAll();
 }
 public function roles(int $userId): array { return array_values(array_unique(array_column($this->sources($userId),'role'))); }
}
