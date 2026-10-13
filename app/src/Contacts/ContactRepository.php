<?php
declare(strict_types=1);

namespace Vereinskiste\Contacts;

use PDO;
use Vereinskiste\Finance\BankDetails;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Foundation\Conflict;

final class ContactRepository
{
    public const FIELDS = ['name' => 200, 'address' => 200, 'zip' => 20, 'city' => 100,
        'country' => 100, 'email' => 254, 'phone' => 50, 'iban' => 50, 'bic' => 11, 'notes' => 1000];

    public function __construct(private PDO $pdo) {}

    public function list(string $search = '', string $state = 'active', int $page = 1): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_contacts WHERE (name ILIKE ?)
            AND (?::boolean IS NULL OR active = ?::boolean) ORDER BY lower(name), id LIMIT 50 OFFSET ?');
        $active = match ($state) { 'active' => 'true', 'archived' => 'false', default => null };
        $stmt->execute(['%' . mb_substr($search, 0, 200) . '%', $active, $active, (max(1, min($page, 100000)) - 1) * 50]);
        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public function find(int $id, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_contacts WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::hydrate($row);
    }

    public function duplicates(string $name, string $email): array
    {
        if (!\Vereinskiste\Auth\UserRepository::isStorableText($name . $email)) { return []; }
        $stmt = $this->pdo->prepare("SELECT id,name FROM finance_contacts WHERE lower(name)=lower(?)
            OR (?<>'' AND lower(data->>'email')=lower(?)) ORDER BY id LIMIT 5");
        $stmt->execute([mb_substr(trim($name), 0, 200), mb_substr(trim($email), 0, 254), mb_substr(trim($email), 0, 254)]);
        return $stmt->fetchAll();
    }

    public function save(?int $id, array $input, int $version, int $actor, string $reason): int
    {
        $data = Fields::read($input, self::FIELDS, ['name']);
        $data = array_replace($data, BankDetails::read($data['iban'], $data['bic']));
        $kind = $input['kind'] ?? '';
        if (!in_array($kind, ['person', 'company'], true)) { throw new ValidationError(['kind' => 'foundation.invalid']); }
        return Transaction::run($this->pdo, function () use ($id, $input, $data, $kind, $version, $actor, $reason): int {
            $before = $id === null ? null : $this->find($id, true);
            if ($id !== null && ($before === null || $before['version'] !== $version)) { throw new Conflict(); }
            $active = ($input['active'] ?? ($id === null ? '1' : '')) === '1';
            $after = $data + ['kind' => $kind, 'active' => $active];
            if ($id === null) {
                $stmt = $this->pdo->prepare('INSERT INTO finance_contacts (kind,name,data,active) VALUES (?,?,?::jsonb,?) RETURNING id');
                $stmt->execute([$kind, $data['name'], json_encode($data, JSON_THROW_ON_ERROR), $active ? 'true' : 'false']);
                $id = (int) $stmt->fetchColumn();
            } else {
                $this->pdo->prepare('UPDATE finance_contacts SET kind=?,name=?,data=?::jsonb,active=?,version=version+1 WHERE id=?')
                    ->execute([$kind, $data['name'], json_encode($data, JSON_THROW_ON_ERROR), $active ? 'true' : 'false', $id]);
            }
            (new History($this->pdo))->record('contact', $id, $before === null ? [] : $before['data']
                + ['kind' => $before['kind'], 'active' => $before['active']], $after, $actor, $reason);
            return $id;
        });
    }

    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id']; $row['version'] = (int) $row['version'];
        $row['data'] = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR)
            + array_fill_keys(array_keys(self::FIELDS), '');
        return $row;
    }
}
