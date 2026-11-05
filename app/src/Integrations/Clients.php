<?php
declare(strict_types=1);
namespace Vereinskiste\Integrations;

use PDO;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Auth\Permissions;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class Clients
{
    public function __construct(private PDO $pdo) {}

    public function create(array $input, int $actor): array
    {
        $values = Fields::read($input, ['name' => 100, 'valid_days' => 3], ['name', 'valid_days']);
        if (!preg_match('/^[1-9][0-9]{0,2}$/D', $values['valid_days']) || (int) $values['valid_days'] > 365) { throw new ValidationError(['valid_days' => 'foundation.invalid']); }
        $scopes = self::selection($input['scopes'] ?? [], array_keys(Catalog::SCOPES), 'scopes');
        $protocols = self::selection($input['protocols'] ?? [], ['api', 'mcp'], 'protocols');
        $roles = self::selection($input['allowed_roles'] ?? [], Catalog::ROLES, 'allowed_roles', false);
        if (in_array('bookings.review', $scopes, true) && !in_array('bookings.read', $scopes, true)) { throw new ValidationError(['scopes' => 'integrations.review_requires_read']); }
        if ($roles && !in_array('users.create', $scopes, true)) { throw new ValidationError(['allowed_roles' => 'integrations.roles_require_create']); }
        $owner = (new UserRepository($this->pdo))->findById($actor);
        $rights = $owner && $owner['active'] ? Permissions::for($owner['roles']) : [];
        foreach ($scopes as $scope) {
            if (!in_array(Catalog::SCOPES[$scope]['permission'], $rights, true)) { throw new ValidationError(['scopes' => 'foundation.invalid']); }
        }
        $token = 'vk_api_' . bin2hex(random_bytes(32));
        $id = Transaction::run($this->pdo, function () use ($values, $scopes, $protocols, $roles, $actor, $token): int {
            $stmt = $this->pdo->prepare("INSERT INTO integration_clients(name,token_hash,token_prefix,scopes,protocols,allowed_roles,created_by,expires_at) VALUES(?,?,?,?::jsonb,?::jsonb,?::jsonb,?,CURRENT_TIMESTAMP + (? * INTERVAL '1 day')) RETURNING id");
            $stmt->execute([$values['name'], hash('sha256', $token), substr($token, 0, 15), json_encode($scopes), json_encode($protocols), json_encode($roles), $actor, (int) $values['valid_days']]);
            $id = (int) $stmt->fetchColumn();
            (new AuditLog($this->pdo))->record('integration_created', $actor, ['client_id' => $id, 'scopes' => $scopes, 'protocols' => $protocols, 'allowed_roles' => $roles]);
            return $id;
        });
        return ['id' => $id, 'name' => $values['name'], 'token' => $token];
    }

    public function all(): array
    {
        $rows = $this->pdo->query('SELECT id,name,token_prefix,scopes,protocols,allowed_roles,created_by,created_at,expires_at,revoked_at,last_used_at,expires_at<=CURRENT_TIMESTAMP AS expired FROM integration_clients ORDER BY id DESC')->fetchAll();
        return array_map(self::decode(...), $rows);
    }

    public function revoke(int $id, int $actor): bool
    {
        return Transaction::run($this->pdo, function () use ($id, $actor): bool {
            $stmt = $this->pdo->prepare('UPDATE integration_clients SET revoked_at=CURRENT_TIMESTAMP WHERE id=? AND revoked_at IS NULL');
            $stmt->execute([$id]);
            if ($stmt->rowCount() !== 1) { return false; }
            (new AuditLog($this->pdo))->record('integration_revoked', $actor, ['client_id' => $id]);
            return true;
        });
    }

    public function authenticate(string $token, string $protocol): array
    {
        if (!preg_match('/^vk_api_[a-f0-9]{64}$/D', $token)) { throw self::unauthorized(); }
        $stmt = $this->pdo->prepare('SELECT id FROM integration_clients WHERE token_hash=?');
        $stmt->execute([hash('sha256', $token)]);
        $id = $stmt->fetchColumn();
        if ($id === false) { throw self::unauthorized(); }
        return $this->forUse((int) $id, $protocol);
    }

    /** Rechecked under a row lock for each operation, serialized against revocation. */
    public function forUse(int $id, string $protocol, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare('SELECT id,name,scopes,protocols,allowed_roles,created_by FROM integration_clients WHERE id=? AND revoked_at IS NULL AND expires_at>CURRENT_TIMESTAMP' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) { throw self::unauthorized(); }
        $client = self::decode($row);
        $owner = (new UserRepository($this->pdo))->findById((int) $client['created_by']);
        if (!$owner || !$owner['active']) { throw self::unauthorized(); }
        if (!in_array($protocol, $client['protocols'], true)) { throw new ApiError(403, 'protocol_denied', 'Dieser Zugang ist für die Schnittstelle nicht freigegeben.'); }
        $rights = Permissions::for($owner['roles']);
        $client['scopes'] = array_values(array_filter($client['scopes'], static fn (string $scope): bool => isset(Catalog::SCOPES[$scope]) && in_array(Catalog::SCOPES[$scope]['permission'], $rights, true)));
        return $client;
    }

    private static function decode(array $row): array
    {
        foreach (['scopes', 'protocols', 'allowed_roles'] as $field) { $row[$field] = json_decode($row[$field], true, 512, JSON_THROW_ON_ERROR); }
        return $row;
    }

    private static function selection(mixed $values, array $allowed, string $field, bool $required = true): array
    {
        if (!is_array($values) || !array_is_list($values) || ($required && !$values)) { throw new ValidationError([$field => 'foundation.invalid']); }
        foreach ($values as $value) {
            if (!is_string($value) || !in_array($value, $allowed, true)) { throw new ValidationError([$field => 'foundation.invalid']); }
        }
        return array_values(array_unique($values));
    }

    private static function unauthorized(): ApiError { return new ApiError(401, 'unauthorized', 'Gültiger Bearer-Zugang erforderlich.'); }
}
