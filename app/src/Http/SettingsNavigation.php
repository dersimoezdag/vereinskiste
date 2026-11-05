<?php
declare(strict_types=1);

namespace Vereinskiste\Http;

/** One catalogue for the settings sidebar, contextual tabs and active sections. */
final class SettingsNavigation
{
    public static function forPath(string $path): array
    {
        $groups = [
            ['label' => 'settings.section.system', 'tabs' => [
                ['/settings', 'settings.version_updates', ['/settings', '/update', '/settings/update', '/settings/check-updates', '/settings/auto-update']],
                ['/settings/backup', 'settings.backup', ['/settings/backup']],
            ]],
            ['label' => 'settings.section.club', 'tabs' => [
                ['/settings/club', 'foundation.club', ['/settings/club']],
                ['/settings/club-roles', 'nav.club_roles', ['/settings/club-roles']],
            ]],
            ['label' => 'applications.title', 'tabs' => [
                ['/settings/declarations', 'declarations.title', ['/settings/declarations']],
                ['/settings/declarations/options', 'applications.settings.title', ['/settings/declarations/options', '/settings/declarations/settings']],
            ]],
            ['label' => 'settings.section.email', 'tabs' => [
                ['/settings/email', 'mail.settings.title', ['/settings/email']],
            ]],
            ['label' => 'privacy.title', 'tabs' => [
                ['/settings/privacy', 'privacy.title', ['/settings/privacy']],
            ]],
            ['label' => 'settings.section.finance', 'tabs' => [
                ['/settings/payments', 'intake.payment.settings', ['/settings/payments']],
                ['/settings/closing', 'nav.closing', ['/settings/closing']],
            ]],
            ['label' => 'integrations.title', 'tabs' => [
                ['/settings/integrations', 'integrations.access', ['/settings/integrations']],
                ['/settings/integrations/connection', 'integrations.connection', ['/settings/integrations/connection']],
                ['/settings/integrations/activity', 'integrations.activity', ['/settings/integrations/activity']],
            ]],
            ['label' => 'settings.section.access', 'tabs' => [
                ['/users', 'settings.users', ['/users'], 'users.manage'],
                ['/audit-log', 'nav.audit', ['/audit-log']],
            ]],
        ];
        $activeGroup = null;
        $bestMatch = 0;
        foreach ($groups as $groupIndex => &$group) {
            $tabs = [];
            foreach ($group['tabs'] as $tab) {
                [$url, $label, $prefixes] = $tab;
                $tabs[] = ['url' => $url, 'label' => $label, 'right' => $tab[3] ?? 'settings.manage', 'active' => false];
                foreach ($prefixes as $prefix) {
                    if (($path === $prefix || ($prefix !== '/settings' && str_starts_with($path, $prefix . '/'))) && strlen($prefix) > $bestMatch) {
                        $activeGroup = $groupIndex;
                        $activeTab = count($tabs) - 1;
                        $bestMatch = strlen($prefix);
                    }
                }
            }
            $group['tabs'] = $tabs;
            $group['active'] = false;
        }
        unset($group);
        if ($activeGroup !== null) {
            $groups[$activeGroup]['active'] = true;
            $groups[$activeGroup]['tabs'][$activeTab]['active'] = true;
        }
        $isSettings = $activeGroup !== null || str_starts_with($path, '/settings/');
        return ['is_settings' => $isSettings, 'groups' => $groups, 'current' => $activeGroup !== null ? $groups[$activeGroup] : null];
    }
}
