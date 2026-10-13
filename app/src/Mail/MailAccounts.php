<?php
declare(strict_types=1);

namespace Vereinskiste\Mail;

use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\ValidationError;

/** Purpose-based SMTP accounts, stored outside the public directory alongside instance configuration. */
final class MailAccounts
{
    public const FIELDS = ['host' => 253, 'port' => 5, 'from' => 254, 'from_name' => 100,
        'username' => 254, 'encryption' => 3];

    public function __construct(private string $directory, private array $legacy = []) {}

    public function get(string $purpose = 'system'): array
    {
        $state = $this->load();
        return ['version' => $state['version'], 'config' => $state['accounts'][$purpose] ??
            ($purpose === 'system' ? $this->legacy + ['enabled' => (new Mailer($this->legacy))->isConfigured()] : [])];
    }

    public function configuration(string $purpose = 'system'): array
    {
        $config = $this->get($purpose)['config'];
        return !empty($config['enabled']) ? $config : [];
    }

    public function saveSystem(array $input, int $version): void
    {
        $config = Fields::read($input, self::FIELDS);
        $config['enabled'] = ($input['enabled'] ?? '') === '1';
        $password = $input['password'] ?? '';
        if (!is_string($password) || !mb_check_encoding($password, 'UTF-8') || strlen($password) > 1000 || str_contains($password, "\0")) {
            throw new ValidationError(['password' => 'foundation.invalid']);
        }
        $errors = [];
        if ($config['from'] !== '' && filter_var($config['from'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['from'] = 'foundation.invalid';
        }
        if ($config['host'] !== '' && !preg_match('/^[a-zA-Z0-9._:-]+\z/', $config['host'])) {
            $errors['host'] = 'foundation.invalid';
        }
        if (!preg_match('/^[0-9]{1,5}\z/', $config['port']) || (int) $config['port'] < 1 || (int) $config['port'] > 65535) {
            $errors['port'] = 'foundation.invalid';
        }
        if (!in_array($config['encryption'], ['tls', 'ssl'], true)) { $errors['encryption'] = 'foundation.invalid'; }
        if ($config['enabled'] && $config['host'] === '') { $errors['host'] = 'foundation.required'; }
        if ($config['enabled'] && $config['from'] === '') { $errors['from'] = 'foundation.required'; }
        if ($errors !== []) { throw new ValidationError($errors); }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('E-Mail-Einstellungen können nicht gespeichert werden.');
        }
        $lock = fopen($this->directory . '/mail-accounts.lock', 'c');
        if ($lock === false) { throw new \RuntimeException('E-Mail-Einstellungen können nicht gesperrt werden.'); }
        chmod($this->directory . '/mail-accounts.lock', 0600);
        $temp = null;
        try {
            if (!flock($lock, LOCK_EX)) { throw new \RuntimeException('E-Mail-Einstellungen können nicht gesperrt werden.'); }
            $current = $this->get();
            if ($current['version'] !== $version) { throw new Conflict(); }
            $config['password'] = ($input['clear_password'] ?? '') === '1' ? ''
                : ($password === '' ? ($current['config']['password'] ?? '') : $password);
            $state = $this->load();
            $state['version']++;
            $state['accounts']['system'] = $config;
            $temp = tempnam($this->directory, '.mail-accounts-');
            if ($temp === false || !chmod($temp, 0600)) { throw new \RuntimeException('E-Mail-Einstellungen können nicht gespeichert werden.'); }
            $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (file_put_contents($temp, $json) !== strlen($json) || !rename($temp, $this->directory . '/mail-accounts.json')) {
                throw new \RuntimeException('E-Mail-Einstellungen können nicht gespeichert werden.');
            }
        } finally {
            if (is_string($temp) && is_file($temp)) { unlink($temp); }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load(): array
    {
        $path = $this->directory . '/mail-accounts.json';
        if (!is_file($path)) { return ['version' => 0, 'accounts' => []]; }
        $contents = file_get_contents($path);
        if ($contents === false) { throw new \RuntimeException('E-Mail-Einstellungen können nicht gelesen werden.'); }
        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
