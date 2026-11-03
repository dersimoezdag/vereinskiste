<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use PDO;
use Vereinskiste\Ledger\TextInput;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Privacy\Consents;

/** Die zu einem Antrag abgegebenen Erklaerungen mit Snapshot und Unterschrift. */
final class ApplicationSignatures
{
    public function __construct(private PDO $pdo) {}

    /** @param array<string,mixed> $input @return array{place:string,rows:list<array<string,mixed>>} */
    public function read(array $input, string $club): array
    {
        $declarations = (new Declarations($this->pdo))->active();
        $answers = is_array($input['declaration'] ?? null) ? $input['declaration'] : [];
        $signatures = is_array($input['signature'] ?? null) ? $input['signature'] : [];
        $place = is_string($input['signed_place'] ?? null) ? trim($input['signed_place']) : '';
        $errors = $rows = [];
        $needsPlace = false;
        if ($declarations === []) {
            $errors['declarations'] = 'declarations.error.none';
        }
        foreach ($declarations as $d) {
            $key = (string) $d['id'];
            $answer = $answers[$key] ?? '';
            $granted = null;
            if ($d['kind'] === 'confirmation') {
                $granted = $answer === '1' ? true : null;
                if ($granted === null) { $errors['declaration_' . $key] = 'declarations.error.confirm'; }
            } elseif ($d['kind'] === 'consent') {
                $granted = match ($answer) { 'yes' => true, 'no' => false, default => null };
                if ($granted === null) { $errors['declaration_' . $key] = 'declarations.error.choose'; }
            }
            $png = null;
            if ($d['signature_required']) {
                $needsPlace = true;
                $raw = $signatures[$key] ?? '';
                if (!is_string($raw) || $raw === '') {
                    $errors['signature_' . $key] = 'declarations.error.signature_required';
                } else {
                    try {
                        $png = SignatureImage::fromDataUrl($raw);
                    } catch (ValidationError) {
                        $errors['signature_' . $key] = 'declarations.error.signature_invalid';
                    }
                }
            }
            $rows[] = ['declaration_id' => $d['id'], 'version' => $d['version'], 'kind' => $d['kind'], 'granted' => $granted, 'png' => $png,
                'snapshot' => ['title' => Declarations::render($d['title'], $club, false), 'content' => Declarations::render($d['content'], $club, true),
                    'kind' => $d['kind'], 'version' => $d['version']]];
        }
        if (mb_strlen($place) > 100) {
            $errors['signed_place'] = 'foundation.invalid';
        } elseif ($needsPlace && TextInput::isBlank($place)) {
            $errors['signed_place'] = 'foundation.required';
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        return ['place' => $place, 'rows' => $rows];
    }

    /** @param array{place:string,rows:list<array<string,mixed>>} $read */
    public function store(int $applicationId, array $read): void
    {
        $insert = $this->pdo->prepare('INSERT INTO application_signatures
            (application_id, declaration_id, version, granted, snapshot, snapshot_hash, signature_png, signature_sha256, signed_place)
            VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?)');
        $consents = new Consents($this->pdo);
        foreach ($read['rows'] as $row) {
            $json = json_encode($row['snapshot'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $png = $row['png'];
            $insert->bindValue(1, $applicationId, PDO::PARAM_INT);
            $insert->bindValue(2, $row['declaration_id'], PDO::PARAM_INT);
            $insert->bindValue(3, $row['version'], PDO::PARAM_INT);
            $insert->bindValue(4, $row['granted'] === null ? null : ($row['granted'] ? 'true' : 'false'), $row['granted'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(5, $json);
            $insert->bindValue(6, hash('sha256', $json));
            $insert->bindValue(7, $png, $png === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $insert->bindValue(8, $png === null ? null : hash('sha256', $png), $png === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(9, $read['place']);
            $insert->execute();
            if ($row['granted'] !== null) {
                $consents->fromDeclaration($applicationId, $row['declaration_id'], $row['granted'], $row['snapshot']);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function forApplication(int $applicationId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, declaration_id, version, granted, snapshot, snapshot_hash, signature_png IS NOT NULL AS has_signature,
            signature_sha256, signed_place, signed_at FROM application_signatures WHERE application_id = ?
            ORDER BY (SELECT position FROM application_declarations d WHERE d.id = declaration_id), id');
        $stmt->execute([$applicationId]);
        return array_map(static function (array $r): array {
            $snapshot = json_decode((string) $r['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            return ['id' => (int) $r['id'], 'declaration_id' => (int) $r['declaration_id'], 'version' => (int) $r['version'],
                'kind' => (string) ($snapshot['kind'] ?? ''), 'granted' => $r['granted'] === null ? null : (bool) $r['granted'],
                'snapshot' => $snapshot, 'snapshot_hash' => (string) $r['snapshot_hash'], 'has_signature' => (bool) $r['has_signature'],
                'signature_sha256' => $r['signature_sha256'], 'signed_place' => (string) $r['signed_place'], 'signed_at' => (string) $r['signed_at']];
        }, $stmt->fetchAll());
    }

    public function png(int $applicationId, int $signatureId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT signature_png FROM application_signatures WHERE application_id = ? AND id = ? AND signature_png IS NOT NULL');
        $stmt->execute([$applicationId, $signatureId]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }
        return is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
    }

    /** Nur innerhalb einer Anonymisierung (Trigger verlangt anonymized_at). */
    public function redact(int $applicationId): void
    {
        $this->pdo->prepare("UPDATE application_signatures SET signature_png = NULL, signature_sha256 = NULL, signed_place = '',
            snapshot = '{}'::jsonb, snapshot_hash = ? WHERE application_id = ?")->execute([hash('sha256', '{}'), $applicationId]);
    }
}
