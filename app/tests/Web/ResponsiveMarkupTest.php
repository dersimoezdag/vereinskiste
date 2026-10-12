<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use DOMDocument;
use DOMXPath;
use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Mail\Mailer;
use Vereinskiste\Tests\TestCase;

final class ResponsiveMarkupTest extends TestCase
{
    use WebHarness;
    private PDO $pdo;

    public function test_pages_have_viewport_labels_csrf_unique_ids_and_skip_target(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->bootApp();
        $user = $this->loginAs(['vorstand']);
        $this->pdo->exec("INSERT INTO accounts (name, type, opening_date) VALUES ('Kasse', 'cash', '2026-01-01')");
        $this->pdo->exec("INSERT INTO categories (name) VALUES ('Spenden')");
        $booking = (new BookingService($this->pdo))->create(['account_id' => '1', 'booking_date' => '2026-10-01',
            'amount' => '10,00', 'direction' => 'in', 'description' => 'Spende'], $user);
        $paths = ['/', '/accounts', '/categories', '/bookings', '/bookings/new',
            '/bookings/' . $booking['id'], '/bookings/' . $booking['id'] . '/edit',
            '/corrections/new', '/transfers/new', '/reports/summary', '/settings/closing', '/audit-log',
            '/users', '/users/new', '/users/' . $user . '/edit', '/account/password', '/update'];
        foreach ($paths as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->check((string) $response->getBody(), $path);
        }
        $this->assertStringContainsString('table-cards', (string) $this->get('/bookings')->getBody());
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['pdo' => $this->pdo, 'session_store' => $this->store,
            'base_url' => 'https://verein.example.org',
            'mailer' => new Mailer(['host' => 'smtp.example.org', 'from' => 'verein@example.org'], static function (): void {})]);
        foreach (['/login', '/password-reset', '/password-reset/invalid'] as $path) {
            $this->check((string) $this->get($path)->getBody(), $path);
        }
        $this->app = Kernel::createInstaller(['session_store' => new ArraySessionStore()]);
        $this->check((string) $this->get('/install')->getBody(), '/install');
    }

    private function check(string $html, string $path): void
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//meta[@name="viewport"]')->length, $path);
        $this->assertSame(1, $xpath->query('//main[@id="main-content"]')->length, $path);
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $id = $node->getAttribute('id');
            $this->assertNotContains($id, $ids, $path . ': duplicate id ' . $id);
            $ids[] = $id;
        }
        foreach ($xpath->query('//input[not(@type="hidden")] | //select | //textarea') as $field) {
            $labels = $xpath->query('ancestor::label', $field);
            $labelled = $labels->length > 0 || $field->hasAttribute('aria-label') || $field->hasAttribute('aria-labelledby');
            if (!$labelled && $field->hasAttribute('id')) {
                foreach ($xpath->query('//label[@for]') as $label) {
                    if ($label->getAttribute('for') === $field->getAttribute('id')) { $labelled = true; }
                }
            }
            $this->assertTrue($labelled, $path . ': label missing for ' . $field->getAttribute('name'));
        }
        foreach ($xpath->query('//form') as $form) {
            if (strtolower($form->getAttribute('method')) === 'post') {
                $this->assertSame(1, $xpath->query('.//input[@name="_csrf"]', $form)->length, $path);
            }
        }
    }
}
