<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use DateTimeImmutable;
use PDO;

/** Haelt die Mitgliedsart (Aktiv/Passiv/Ehemalig) mit Ein- und Austritt in Einklang. Funktionen bleiben unberuehrt. */
final class MembershipTypeSync
{
    private MemberRoleAssignments $assignments;

    public function __construct(private PDO $pdo)
    {
        $this->assignments = new MemberRoleAssignments($pdo);
    }

    public function onLeave(int $memberId, string $date, int $actor): void
    {
        $former = $this->assignments->roleIdByKey('former_member');
        $open = $this->openTypeRoleIds($memberId);
        if ($former !== null && $open === [$former]) {
            return;
        }
        $this->assignments->endOpenMembershipTypes($memberId, $date, $actor);
        if ($former !== null) {
            $next = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
            $this->assignments->assign($memberId, $former, '', $next, $actor);
        }
    }

    public function onJoin(int $memberId, string $date, int $actor): void
    {
        $former = $this->assignments->roleIdByKey('former_member');
        if ($former !== null && in_array($former, $this->openTypeRoleIds($memberId), true)) {
            $before = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
            $this->assignments->endOpenMembershipTypes($memberId, $before, $actor, $former);
        }
        $active = $this->assignments->roleIdByKey('active_member');
        if ($active !== null && $this->openTypeRoleIds($memberId) === []) {
            $this->assignments->assign($memberId, $active, '', $date, $actor);
        }
    }

    /** @return list<int> */
    private function openTypeRoleIds(int $memberId): array
    {
        $stmt = $this->pdo->prepare("SELECT a.role_id FROM member_club_roles a JOIN club_roles r ON r.id = a.role_id
            WHERE a.member_id = ? AND a.end_date IS NULL AND r.kind = 'membership_type' ORDER BY a.role_id");
        $stmt->execute([$memberId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
