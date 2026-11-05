<?php
declare(strict_types=1);
namespace Vereinskiste\Integrations;

use PDO;

final class BookingReviews
{
    public function __construct(private PDO $pdo) {}

    public function forBooking(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT r.id,r.booking_version,r.status,r.note,r.created_at,c.name AS client_name FROM integration_booking_reviews r JOIN integration_clients c ON c.id=r.client_id WHERE booking_id=? ORDER BY r.id DESC LIMIT 100');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    public function add(array $input, int $client): array
    {
        $stmt = $this->pdo->prepare('SELECT version FROM bookings WHERE id=? FOR SHARE');
        $stmt->execute([$input['id']]);
        $version = $stmt->fetchColumn();
        if ($version === false) { throw new ApiError(404, 'not_found', 'Buchung nicht gefunden.'); }
        if ((int) $version !== $input['version']) { throw new ApiError(409, 'version_conflict', 'Die Buchung wurde geändert. Bitte erneut lesen und prüfen.'); }
        $stmt = $this->pdo->prepare('INSERT INTO integration_booking_reviews(client_id,booking_id,booking_version,status,note) VALUES(?,?,?,?,?) RETURNING id');
        $stmt->execute([$client, $input['id'], $input['version'], $input['status'], $input['note']]);
        return ['id' => (int) $stmt->fetchColumn(), 'booking_id' => $input['id'], 'booking_version' => $input['version'], 'status' => $input['status']];
    }
}
