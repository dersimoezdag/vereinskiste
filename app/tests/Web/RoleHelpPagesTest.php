<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use DOMDocument;
use DOMXPath;
use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;

final class RoleHelpPagesTest extends TestCase
{
    use WebHarness;
    private PDO $pdo;

    public function test_list_create_and_edit_show_the_same_accessible_role_comparison(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->bootApp();
        $user = $this->loginAs(['admin']);
        foreach (['/users', '/users/new', '/users/' . $user . '/edit'] as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response->getStatusCode());
            $html = (string) $response->getBody();
            $this->assertStringContainsString('data-bs-target="#roles-help-modal"', $html);
            $this->assertStringContainsString('aria-labelledby="roles-help-title"', $html);
            $this->assertStringContainsString('Admin kann anschließend unabhängig entzogen werden', $html);
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try { $document->loadHTML('<?xml encoding="UTF-8">' . $html); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            $xpath = new DOMXPath($document);
            foreach ([['bookings.write', 'admin', 'Ja'], ['bookings.write', 'kassenpruefer', 'Nein'],
                ['users.manage', 'vorstand', 'Nein'], ['members.write', 'schriftfuehrer', 'Ja'],
                ['imports.write', 'kassenwart', 'Ja']] as [$permission, $role, $expected]) {
                $nodes = $xpath->query('//div[@id="roles-help-modal"]//tr[@data-permission="' . $permission . '"]/td[@data-role="' . $role . '"]');
                $this->assertSame(1, $nodes->length);
                $this->assertSame($expected, trim($nodes->item(0)->textContent));
            }
        }
    }
}
