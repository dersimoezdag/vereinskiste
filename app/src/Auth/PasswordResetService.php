<?php
declare(strict_types=1);

namespace Vereinskiste\Auth;

use Closure;
use PDO;
use Throwable;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Mail\Mailer;

final class PasswordResetService
{
    /** @param Closure():PDO $pdo @param array<string,string> $texts */
    public function __construct(
        private Closure $pdo,
        private Mailer $mailer,
        private string $baseUrl,
        private array $texts
    ) {
    }

    public function isConfigured(): bool
    {
        $url = parse_url($this->baseUrl);
        return $this->mailer->isConfigured() && is_array($url)
            && ($url['scheme'] ?? '') === 'https' && !empty($url['host'])
            && !isset($url['user']) && !isset($url['pass']) && !isset($url['query']) && !isset($url['fragment'])
            && ($url['path'] ?? '') === '';
    }

    /** Die Antwort bleibt auch bei unbekanntem Nutzer oder Versandfehler gleich. */
    public function request(string $email): void
    {
        if (!$this->isConfigured()) {
            return;
        }
        $pdo = ($this->pdo)();
        $user = (new UserRepository($pdo))->findByEmail(trim($email));
        if ($user === null || !$user['active']) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare(
            "INSERT INTO password_resets (user_id, token_hash, credential_hash) VALUES (?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE SET token_hash = EXCLUDED.token_hash,
                credential_hash = EXCLUDED.credential_hash, created_at = now(), expires_at = now() + interval '1 hour'
             WHERE password_resets.created_at <= now() - interval '1 minute'
             RETURNING user_id"
        );
        $stmt->execute([$user['id'], $hash, self::credentials($user)]);
        if ($stmt->fetchColumn() === false) {
            return;
        }
        try {
            $this->mailer->send($user['email'], $this->texts['reset.mail.subject'],
                $this->texts['reset.mail.body'] . "\n\n" . $this->baseUrl . '/password-reset/' . $token);
        } catch (Throwable) {
            $pdo->prepare('DELETE FROM password_resets WHERE token_hash = ?')->execute([$hash]);
            // Keine Transportdetails: koennten Empfaenger oder Zugangsdaten enthalten.
            error_log('Vereinskiste: Passwort-Reset-Mail konnte nicht versendet werden.');
        }
    }

    public function isValid(string $token): bool
    {
        return $this->isConfigured() && $this->find($token) !== null;
    }

    /** Passwortwechsel und Tokenverbrauch sind atomar, auch bei parallelen Formularen. */
    public function reset(string $token, string $password, string $confirmation): bool
    {
        if (!$this->isConfigured() || !self::validToken($token)) {
            return false;
        }
        $pdo = ($this->pdo)();
        return Transaction::run($pdo, function () use ($pdo, $token, $password, $confirmation): bool {
            $user = $this->find($token, true);
            if ($user === null) {
                return false;
            }
            $error = PasswordPolicy::check($password, $user['email'], $user['name']);
            if ($error !== null) {
                throw new ValidationError(['password' => $error]);
            }
            if ($password !== $confirmation) {
                throw new ValidationError(['confirmation' => 'validation.password.mismatch']);
            }
            $repo = new UserRepository($pdo);
            $repo->updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);
            $repo->audit('password_reset_completed', (int) $user['id']);
            return true;
        });
    }

    /** @return array<string,mixed>|null */
    private function find(string $token, bool $lock = false): ?array
    {
        if (!self::validToken($token)) {
            return null;
        }
        $stmt = ($this->pdo)()->prepare(
            'SELECT u.id, u.email, u.name, u.password_hash, r.credential_hash
             FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.expires_at > now() AND u.active'
             . ($lock ? ' FOR UPDATE OF u, r' : '')
        );
        $stmt->execute([hash('sha256', $token)]);
        $user = $stmt->fetch();
        return $user !== false && hash_equals($user['credential_hash'], self::credentials($user)) ? $user : null;
    }

    private static function validToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}\z/', $token) === 1;
    }

    /** Ein Passwort- oder E-Mail-Wechsel macht bereits versendete Links ungueltig. */
    private static function credentials(array $user): string
    {
        return hash('sha256', $user['email'] . "\0" . $user['password_hash']);
    }
}
