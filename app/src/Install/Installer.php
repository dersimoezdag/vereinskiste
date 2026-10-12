<?php
declare(strict_types=1);

namespace Vereinskiste\Install;

use RuntimeException;
use Throwable;
use Vereinskiste\Auth\PasswordPolicy;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Db\Connection;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Mail\Mailer;

final class Installer
{
    public function __construct(private string $appDir)
    {
    }

    public function isInstalled(): bool
    {
        return is_file($this->appDir . '/config/installed.lock') || is_file($this->appDir . '/config/config.php');
    }

    /** @param array<string,mixed> $db @param array<string,string> $club @param array<string,string> $admin */
    public function install(array $db, array $club, array $admin, ?array $smtp = null): void
    {
        foreach ((new SystemCheck($this->appDir))->run() as $check) {
            if (!$check['ok']) {
                throw new RuntimeException('Systemprüfung fehlgeschlagen.');
            }
        }
        if ($this->isInstalled()) {
            throw new RuntimeException('Die Installation ist bereits eingerichtet.');
        }
        $name = trim($admin['name'] ?? '');
        $email = UserRepository::normalizeEmail($admin['email'] ?? '');
        $clubName = trim($club['name'] ?? '');
        $baseUrl = rtrim($club['base_url'] ?? '', '/');
        $url = parse_url($baseUrl);
        if ($name === '' || $clubName === '' || mb_strlen($name) > 100 || mb_strlen($clubName) > 100
            || !UserRepository::isStorableText($name) || !UserRepository::isStorableText($clubName)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || PasswordPolicy::check($admin['password'] ?? '', $email, $name) !== null
            || !is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ($url['path'] ?? '') !== '' || ($smtp !== null && !(new Mailer($smtp))->isConfigured())) {
            throw new RuntimeException('Bitte prüfe Vereinsdaten, HTTPS-Adresse, Administrator und SMTP-Einstellungen.');
        }
        // Verbindung pruefen, bevor Dateien oder Schema veraendert werden.
        $pdo = Connection::fromConfig($db);
        $guard = fopen($this->appDir . '/config/setup.lock', 'c');
        if ($guard === false || !flock($guard, LOCK_EX | LOCK_NB)) {
            if (is_resource($guard)) { fclose($guard); }
            throw new RuntimeException('Eine Einrichtung läuft bereits.');
        }
        $temp = $this->appDir . '/config/config.' . bin2hex(random_bytes(8)) . '.tmp';
        $published = false;
        $locked = false;
        try {
            if ($this->isInstalled()) {
                throw new RuntimeException('Die Installation ist bereits eingerichtet.');
            }
            $config = ['db' => $db, 'base_url' => $baseUrl, 'smtp' => $smtp ?? []];
            if (file_put_contents($temp, "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX) === false) {
                throw new RuntimeException('Konfiguration konnte nicht gespeichert werden.');
            }
            chmod($temp, 0600);
            // Auch alle Migrationen gehoeren zur Installations-Transaktion.
            Transaction::run($pdo, function () use ($pdo, $name, $email, $admin, $clubName, $temp, &$published, &$locked): void {
                (new Migrator($pdo, $this->appDir . '/migrations'))->migrate();
                (new UserRepository($pdo))->create($name, $email,
                    password_hash($admin['password'], PASSWORD_DEFAULT), ['vorstand'], null);
                $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute(['club_name', $clubName]);
                if (!rename($temp, $this->appDir . '/config/config.php')) {
                    throw new RuntimeException('Konfiguration konnte nicht aktiviert werden.');
                }
                $published = true;
                if (file_put_contents($this->appDir . '/config/installed.lock', date(DATE_ATOM), LOCK_EX) === false) {
                    throw new RuntimeException('Installationssperre konnte nicht gespeichert werden.');
                }
                $locked = true;
            });
        } catch (Throwable $e) {
            if (is_file($temp)) { unlink($temp); }
            if ($published) { unlink($this->appDir . '/config/config.php'); }
            if ($locked) { unlink($this->appDir . '/config/installed.lock'); }
            throw $e;
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }
}
