<?php
declare(strict_types=1);
namespace Vereinskiste\Integrations;

use PDO;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class Dispatcher
{
    public function __construct(private PDO $pdo) {}

    public function call(array $principal, string $protocol, string $operation, array $input, bool $query = false): array
    {
        $definition = Catalog::operations()[$operation] ?? null;
        if (!$definition) { throw new ApiError(404, 'unknown_operation', 'Unbekannte Aktion.'); }
        try {
            return Transaction::run($this->pdo, function () use ($principal, $protocol, $operation, $input, $query, $definition): array {
                $client = (new Clients($this->pdo))->forUse((int) $principal['id'], $protocol, true);
                if (!in_array($definition['scope'], $client['scopes'], true)) { throw new ApiError(403, 'scope_denied', 'Diese Aktion ist für den Zugang nicht freigegeben.'); }
                $values = Input::read($input, $definition, $query);
                $requestId = $values['request_id'] ?? null;
                $hash = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
                if ($requestId !== null) {
                    $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))');
                    $lock->execute([$client['id'] . ':' . $requestId]);
                    $stmt = $this->pdo->prepare('SELECT operation,input_hash,result FROM integration_results WHERE client_id=? AND request_id=?');
                    $stmt->execute([$client['id'], $requestId]);
                    $cached = $stmt->fetch();
                    if ($cached) {
                        if ($cached['operation'] !== $operation || !hash_equals($cached['input_hash'], $hash)) { throw new ApiError(409, 'request_conflict', 'Diese request_id wurde bereits für eine andere Eingabe verwendet.'); }
                        $this->log($client, $operation, $protocol, 201, true);
                        return json_decode($cached['result'], true, 512, JSON_THROW_ON_ERROR);
                    }
                }
                $result = (new Operations($this->pdo))->execute($operation, $values, $client);
                if ($requestId !== null) {
                    $stmt = $this->pdo->prepare('INSERT INTO integration_results(client_id,request_id,operation,input_hash,result) VALUES(?,?,?,?,?::jsonb)');
                    $stmt->execute([$client['id'], $requestId, $operation, $hash, json_encode($result, JSON_THROW_ON_ERROR)]);
                }
                $this->log($client, $operation, $protocol, $definition['method'] === 'GET' ? 200 : 201);
                return $result;
            });
        } catch (ApiError|ValidationError $e) {
            $status = $e instanceof ApiError ? $e->status : 422;
            $this->log($principal, $operation, $protocol, $status);
            if ($e instanceof ValidationError) { throw new ApiError(422, 'invalid_input', 'Eingaben konnten nicht gespeichert werden.', $e->errors); }
            throw $e;
        }
    }
    private function log(array $client, string $operation, string $protocol, int $status, bool $replay = false): void
    {
        (new AuditLog($this->pdo))->record('integration_access', (int) $client['created_by'], ['client_id' => (int) $client['id'], 'operation' => $operation, 'protocol' => $protocol, 'status' => $status, 'replay' => $replay]);
    }
}
