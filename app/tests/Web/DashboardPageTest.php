<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\TestCase;

final class DashboardPageTest extends TestCase
{
    use WebHarness;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['pdo' => $this->pdo, 'session_store' => $this->store,
            'require_https' => false, 'security_dir' => $this->securityDir()]);
    }

    private function seed(int $user): void
    {
        $this->pdo->exec("INSERT INTO accounts(name,type,opening_date) VALUES ('Kasse','cash','2026-01-01')");
        $this->pdo->exec("INSERT INTO categories(name) VALUES ('</script><b>Spenden')");
        $this->pdo->prepare("INSERT INTO bookings(account_id,booking_date,amount,direction,category_id,description,created_by)
            VALUES (1,'2026-03-05',25.00,'in',1,'Spende',?)")->execute([$user]);
        $this->pdo->prepare("INSERT INTO bookings(account_id,booking_date,amount,direction,description,created_by)
            VALUES (1,'2026-04-01',10.00,'out','Material',?)")->execute([$user]);
    }

    public function test_dashboard_shows_charts_data_and_escapes_category_names(): void
    {
        $user = $this->loginAs(['admin']);
        $this->seed($user);
        $response = $this->get('/reports/dashboard?from=2026-01-01&to=2026-12-31');
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('id="dashboard-data"', $body);
        $this->assertStringContainsString('/assets/vendor/chartjs/chart.umd.min.js', $body);
        $this->assertStringContainsString('id="chart-monthly"', $body);
        $this->assertStringContainsString('"months":["2026-01"', $body);
        $this->assertStringNotContainsString('</script><b>', $body);
        $this->assertStringContainsString('&lt;/script&gt;&lt;b&gt;Spenden', $body);
    }

    public function test_empty_period_shows_message_without_scripts(): void
    {
        $this->loginAs(['admin']);
        $body = (string) $this->get('/reports/dashboard?from=2026-01-01&to=2026-12-31')->getBody();
        $this->assertStringContainsString('In diesem Zeitraum gibt es keine Buchungen.', $body);
        $this->assertStringNotContainsString('chart.umd.min.js', $body);
    }

    public function test_invalid_range_is_rejected_with_message(): void
    {
        $this->loginAs(['admin']);
        $this->assertSame(422, $this->get('/reports/dashboard?from=2026-02-01&to=2026-01-01')->getStatusCode());
        $response = $this->get('/reports/dashboard?from=1990-01-01&to=2026-12-31');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('höchstens 120 Monate', (string) $response->getBody());
    }

    public function test_rights_follow_reports_read(): void
    {
        $this->loginAs(['auditor']);
        $this->assertSame(200, $this->get('/reports/dashboard')->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='secretary'");
        $this->assertSame(403, $this->get('/reports/dashboard')->getStatusCode());
    }
}
