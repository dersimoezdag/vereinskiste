<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use PDO;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\ClubDocuments\Content;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

/** Vom Vorstand festgelegte Erklaerungen des Mitgliedsantrags; jede Aenderung ist eine neue Version. */
final class Declarations
{
    public const KINDS = ['confirmation', 'consent', 'notice'];
    public const MAX_CONTENT = 20000;
    private const SELECT = 'SELECT d.id, d.position, d.active, v.version, v.title, v.content, v.kind, v.signature_required
        FROM application_declarations d
        JOIN application_declaration_versions v ON v.declaration_id = d.id AND v.version = d.current_version';

    public function __construct(private PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function all(bool $onlyActive = false): array
    {
        $rows = $this->pdo->query(self::SELECT . ($onlyActive ? ' WHERE d.active' : '') . ' ORDER BY d.position, d.id')->fetchAll();
        return array_map(self::hydrate(...), $rows);
    }

    /** @return list<array<string,mixed>> */
    public function active(): array
    {
        return $this->all(true);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE d.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::hydrate($row);
    }

    /** @param array<string,mixed> $input */
    public function save(?int $id, array $input, int $actor): int
    {
        $errors = [];
        $title = '';
        try {
            $title = Fields::read($input, ['title' => 200], ['title'])['title'];
        } catch (ValidationError $e) {
            $errors += $e->errors;
        }
        $content = Content::clean(is_string($input['content'] ?? null) ? $input['content'] : '');
        if (mb_strlen($content) > self::MAX_CONTENT) {
            $errors['content'] = 'declarations.error.content_length';
        }
        $kind = $input['kind'] ?? '';
        if (!in_array($kind, self::KINDS, true)) {
            $errors['kind'] = 'foundation.invalid';
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        $signature = ($input['signature_required'] ?? '') === '1';

        return Transaction::run($this->pdo, function () use ($id, $title, $content, $kind, $signature, $actor): int {
            $audit = new AuditLog($this->pdo);
            if ($id === null) {
                $position = (int) $this->pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM application_declarations')->fetchColumn();
                $stmt = $this->pdo->prepare('INSERT INTO application_declarations (position, current_version) VALUES (?, 1) RETURNING id');
                $stmt->execute([$position]);
                $id = (int) $stmt->fetchColumn();
                $this->insertVersion($id, 1, $title, $content, $kind, $signature, $actor);
                $audit->record('application_declaration_created', $actor, ['declaration_id' => $id, 'title' => $title]);
                return $id;
            }
            $this->pdo->prepare('SELECT id FROM application_declarations WHERE id = ? FOR UPDATE')->execute([$id]);
            $current = $this->find($id);
            if ($current === null) {
                throw new ValidationError(['declaration' => 'foundation.invalid']);
            }
            if ([$current['title'], $current['content'], $current['kind'], $current['signature_required']] === [$title, $content, $kind, $signature]) {
                return $id;
            }
            $version = $current['version'] + 1;
            $this->insertVersion($id, $version, $title, $content, $kind, $signature, $actor);
            $this->pdo->prepare('UPDATE application_declarations SET current_version = ? WHERE id = ?')->execute([$version, $id]);
            $audit->record('application_declaration_changed', $actor, ['declaration_id' => $id, 'version' => $version]);
            return $id;
        });
    }

    public function setActive(int $id, bool $active, int $actor): void
    {
        $stmt = $this->pdo->prepare('UPDATE application_declarations SET active = ? WHERE id = ?');
        $stmt->execute([$active ? 'true' : 'false', $id]);
        if ($stmt->rowCount() === 0) {
            throw new ValidationError(['declaration' => 'foundation.invalid']);
        }
        (new AuditLog($this->pdo))->record('application_declaration_toggled', $actor, ['declaration_id' => $id, 'active' => $active]);
    }

    public function move(int $id, string $direction, int $actor): void
    {
        Transaction::run($this->pdo, function () use ($id, $direction, $actor): void {
            $order = $this->pdo->query('SELECT id FROM application_declarations ORDER BY position, id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $order = array_map('intval', $order);
            $index = array_search($id, $order, true);
            $target = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index === false || !in_array($direction, ['up', 'down'], true) || !isset($order[$target])) {
                return;
            }
            [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
            $update = $this->pdo->prepare('UPDATE application_declarations SET position = ? WHERE id = ?');
            foreach ($order as $position => $declaration) {
                $update->execute([$position + 1, $declaration]);
            }
            (new AuditLog($this->pdo))->record('application_declaration_moved', $actor, ['declaration_id' => $id, 'direction' => $direction]);
        });
    }

    /** Aendert sich, sobald eine aktive Erklaerung neu, geaendert, verschoben oder deaktiviert wird. */
    public function fingerprint(): string
    {
        $parts = array_map(static fn(array $d): array => [$d['id'], $d['version']], $this->active());
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    public static function render(string $text, string $club, bool $html): string
    {
        return str_replace('{verein}', $html ? htmlspecialchars($club, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $club, $text);
    }

    private function insertVersion(int $id, int $version, string $title, string $content, string $kind, bool $signature, int $actor): void
    {
        $this->pdo->prepare('INSERT INTO application_declaration_versions (declaration_id, version, title, content, kind, signature_required, actor_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$id, $version, $title, $content, $kind, $signature ? 'true' : 'false', $actor]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrate(array $row): array
    {
        return ['id' => (int) $row['id'], 'position' => (int) $row['position'], 'active' => (bool) $row['active'],
            'version' => (int) $row['version'], 'title' => (string) $row['title'], 'content' => (string) $row['content'],
            'kind' => (string) $row['kind'], 'signature_required' => (bool) $row['signature_required']];
    }
}
