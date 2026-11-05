<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class DataGridTemplateTest extends TestCase
{
    private function view(): Environment
    {
        $view = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $texts = require dirname(__DIR__, 2) . '/src/Text/de.php';
        $view->addFunction(new TwigFunction('t', static fn (string $key): string => $texts[$key] ?? $key));
        $view->addFunction(new TwigFunction('can', static fn (): bool => true));
        $view->addFunction(new TwigFunction('csrf_field', static fn (): string => '<input name="_csrf" value="test">', ['is_safe' => ['html']]));
        $view->addFunction(new TwigFunction('system_mail_available', static fn (): bool => true));
        $view->addFilter(new TwigFilter('eur', static fn ($value): string => $value . ' EUR'));
        return $view;
    }

    public function test_generic_cells_escape_content_and_format_zero_money_and_dates(): void
    {
        $html = $this->view()->render('components/data_grid.twig', [
            'label' => 'Test', 'empty_text' => 'Leer',
            'columns' => [['key' => 'name', 'label' => 'Name'], ['key' => 'created_at', 'label' => 'Datum', 'type' => 'date'], ['key' => 'amount', 'label' => 'Betrag', 'type' => 'money']],
            'rows' => [['name' => '<script>alert(1)</script>', 'created_at' => '2026-11-05 12:00:00+00', 'amount' => 0]],
        ]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('05.11.2026', $html);
        $this->assertStringContainsString('0 EUR', $html);
        $this->assertStringContainsString('data-grid-search-control hidden', $html);
    }

    public function test_server_paged_grid_preserves_inactive_badges_and_disabled_sorting(): void
    {
        $html = $this->view()->render('components/data_grid.twig', [
            'label' => 'Test', 'empty_text' => 'Leer', 'server_paged' => true,
            'columns' => [
                ['key' => 'name', 'label' => 'Name', 'sortable' => false],
                ['key' => 'active', 'label' => 'Status', 'type' => 'badge', 'values' => [
                    0 => ['label' => 'Inaktiv', 'tone' => 'text-muted'],
                    1 => ['label' => 'Aktiv', 'tone' => 'text-success'],
                ]],
            ],
            'rows' => [['name' => 'Erika', 'active' => false]],
        ]);
        $this->assertStringContainsString('data-grid-server-paged', $html);
        $this->assertStringContainsString('data-grid-value="Inaktiv"', $html);
        $this->assertStringContainsString('<span class="badge text-muted">Inaktiv</span>', $html);
        $this->assertStringNotContainsString('data-grid-sort="0"', $html);
        $this->assertStringContainsString('data-grid-sort="1"', $html);
    }

    public function test_application_embed_renders_custom_cells_actions_and_shared_status(): void
    {
        $html = $this->view()->render('members/_applications_table.twig', [
            'filter' => 'pending', 'applications' => [[
                'id' => 7, 'applicant_name' => 'Erika Muster', 'email' => 'erika@example.org',
                'created_at' => '2026-11-05 12:00:00+00', 'status' => 'pending', 'data' => [],
            ]],
        ]);
        $this->assertStringContainsString('Erika Muster', $html);
        $this->assertStringContainsString('/members/applications/7/decision', $html);
        $this->assertStringContainsString('05.11.2026', $html);
        $this->assertStringContainsString('data-grid-value="Offen"', $html);
        $this->assertSame(1, substr_count($html, 'data-grid-row'));
    }

    public function test_message_embed_keeps_retry_and_details_and_escapes_subject(): void
    {
        $html = $this->view()->render('members/_application_messages.twig', ['outbox' => [[
            'id' => 9, 'application_id' => 7, 'kind' => 'submitted', 'recipient' => 'erika@example.org',
            'subject' => '<img src=x>', 'status' => 'uncertain', 'version' => 2,
            'created_at' => '2026-11-05 12:00:00+00', 'sent_at' => null,
        ]]]);
        $this->assertStringContainsString('Eingangsbestätigung', $html);
        $this->assertStringContainsString('Zustellung unklar', $html);
        $this->assertStringContainsString('/members/application-mails/9/retry', $html);
        $this->assertStringContainsString('name="version" value="2"', $html);
        $this->assertStringContainsString('&lt;img src=x&gt;', $html);
        $this->assertStringNotContainsString('<img src=x>', $html);
    }
}
