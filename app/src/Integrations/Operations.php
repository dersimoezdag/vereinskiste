<?php
declare(strict_types=1);
namespace Vereinskiste\Integrations;

use PDO;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Ledger\BookingRepository;

final class Operations
{
    public function __construct(private PDO $pdo) {}

    public function execute(string $name, array $input, array $client): array
    {
        return match ($name) {
            'users_list' => $this->users($input),
            'users_create' => $this->createUser($input, $client),
            'bookings_list' => ['items' => (new BookingRepository($this->pdo))->search($input, $input['limit'] ?? 25, $input['offset'] ?? 0), 'limit' => $input['limit'] ?? 25, 'offset' => $input['offset'] ?? 0],
            'bookings_get' => $this->booking($input['id']),
            'bookings_review' => (new BookingReviews($this->pdo))->add($input, (int) $client['id']),
        };
    }

    private function users(array $input): array
    {
        $limit = $input['limit'] ?? 25;
        $offset = $input['offset'] ?? 0;
        $stmt = $this->pdo->prepare('SELECT id,name,email,active FROM users ORDER BY id LIMIT ? OFFSET ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT); $stmt->bindValue(2, $offset, PDO::PARAM_INT); $stmt->execute();
        return ['items' => $stmt->fetchAll(), 'limit' => $limit, 'offset' => $offset];
    }

    private function createUser(array $input, array $client): array
    {
        if (\Vereinskiste\Ledger\TextInput::isBlank($input['name'])) { throw new ApiError(422, 'invalid_input', 'Name erforderlich.', ['name' => 'foundation.required']); }
        $email = UserRepository::normalizeEmail($input['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new ApiError(422, 'invalid_input', 'Ungültige E-Mail-Adresse.', ['email' => 'validation.email.invalid']); }
        $roles = $input['roles'] ?? [];
        if (array_diff($roles, $client['allowed_roles'])) { throw new ApiError(403, 'role_denied', 'Die gewünschte Benutzerrolle ist für diesen Zugang nicht freigegeben.'); }
        // A random, undisclosed password prevents the integration from impersonating the user.
        $id = (new UserRepository($this->pdo))->create($input['name'], $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $roles, (int) $client['created_by']);
        return ['id' => $id, 'roles' => $roles, 'active' => true, 'password_setup_required' => true];
    }

    private function booking(int $id): array
    {
        $booking = (new BookingRepository($this->pdo))->find($id);
        if (!$booking) { throw new ApiError(404, 'not_found', 'Buchung nicht gefunden.'); }
        return ['booking' => $booking, 'reviews' => (new BookingReviews($this->pdo))->forBooking($id)];
    }
}
