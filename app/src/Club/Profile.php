<?php
declare(strict_types=1);

namespace Vereinskiste\Club;

use PDO;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Foundation\Conflict;

final class Profile
{
    public const FIELDS = ['name' => 100, 'address' => 200, 'zip' => 20, 'city' => 100,
        'country' => 100, 'email' => 254, 'phone' => 50, 'website_url' => 1000,
        'constitution_url' => 1000, 'contribution_url' => 1000, 'privacy_url' => 1000,
        'locale' => 5, 'year_start' => 5, 'color' => 7];

    public function __construct(private PDO $pdo) {}

    public function get(bool $lock = false): array
    {
        $row = $this->pdo->query('SELECT data, version, logo_id FROM club_profile WHERE id = 1'
            . ($lock ? ' FOR UPDATE' : ''))->fetch();
        $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
        if (!isset($data['name'])) {
            $data['name'] = $this->pdo->query("SELECT value FROM settings WHERE key = 'club_name'")->fetchColumn() ?: 'Verein';
        }
        return ['data' => $data + ['locale' => 'de', 'year_start' => '01-01', 'color' => '']
            + array_fill_keys(array_keys(self::FIELDS), ''), 'version' => (int) $row['version'],
            'logo_id' => $row['logo_id'] === null ? null : (int) $row['logo_id']];
    }

    public function name(): string { return $this->get()['data']['name']; }

    public function period(?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $start = $today->format('Y') . '-' . $this->get()['data']['year_start'];
        if ($start > $today->format('Y-m-d')) {
            $start = ((int) $today->format('Y') - 1) . '-' . $this->get()['data']['year_start'];
        }
        $end = (new \DateTimeImmutable($start))->modify('+1 year -1 day')->format('Y-m-d');
        return ['from' => $start, 'to' => $end];
    }

    public function setLogo(int $fileId, int $version, int $actor, string $reason): void
    {
        Transaction::run($this->pdo, function () use ($fileId, $version, $actor, $reason): void {
            $profile = $this->get(true);
            if ($profile['version'] !== $version) { throw new Conflict(); }
            $stmt = $this->pdo->prepare("SELECT 1 FROM stored_files WHERE id=? AND root='documents' AND mime IN ('image/png','image/jpeg')");
            $stmt->execute([$fileId]);
            if ($stmt->fetchColumn() === false) { throw new ValidationError(['receipt' => 'foundation.invalid']); }
            (new History($this->pdo))->record('club', 1, ['logo_id' => $profile['logo_id']], ['logo_id' => $fileId], $actor, $reason);
            $this->pdo->prepare('UPDATE club_profile SET logo_id=?,version=version+1 WHERE id=1')->execute([$fileId]);
        });
    }

    public function save(array $input, int $version, int $actor, string $reason): void
    {
        $values = Fields::read($input, self::FIELDS, ['name']);
        $errors = [];
        if (!in_array($values['locale'], ['de', 'en'], true)) { $errors['locale'] = 'foundation.invalid'; }
        if (!preg_match('/^([0-9]{2})-([0-9]{2})\z/', $values['year_start'], $date)
            || !checkdate((int) $date[1], (int) $date[2], 2025)) { $errors['year_start'] = 'foundation.invalid'; }
        if ($values['color'] !== '' && !preg_match('/^#[a-fA-F0-9]{6}\z/', $values['color'])) {
            $errors['color'] = 'foundation.invalid';
        }
        if ($errors !== []) { throw new ValidationError($errors); }
        Transaction::run($this->pdo, function () use ($values, $version, $actor, $reason): void {
            $before = $this->get(true);
            if ($before['version'] !== $version) { throw new Conflict(); }
            $values += array_diff_key($before['data'], self::FIELDS);
            (new History($this->pdo))->record('club', 1, $before['data'], $values, $actor, $reason);
            if ($before['data'] == $values) { return; }
            $this->pdo->prepare('UPDATE club_profile SET data = ?::jsonb, version = version + 1 WHERE id = 1')
                ->execute([json_encode($values, JSON_THROW_ON_ERROR)]);
            $this->pdo->prepare("INSERT INTO settings (key,value) VALUES ('club_name',?)
                ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value")->execute([$values['name']]);
        });
    }
}
