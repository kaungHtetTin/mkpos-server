<?php

namespace App\Services;

use App\Models\User;

/** Navigation only: no private business figures, arbitrary URLs, record IDs or business mutations. */
class AiPageLinks
{
    public static function pages(): array
    {
        return json_decode(file_get_contents(resource_path('ai/app-pages.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function screen(string $id): array
    {
        $page = self::pages()[$id] ?? null;
        abort_unless($page && in_array($page['module'], config('mkpos.enabled_modules', []), true), 403, 'This screen module is unavailable.');

        return ['screen' => $id, 'module' => $page['module'], 'label' => $page['label'],
            'markdown' => '['.$page['label'].'](#mkpos-screen='.$id.')', 'navigation_only' => true, 'opened' => false,
            'instruction' => 'Include this exact Markdown button in the reply. Clicking opens the requested screen or form; no records are saved.'];
    }

    public function forActor(User $actor, string $id): array
    {
        $access = app(AiAssistantAccess::class)->context($actor);
        abort_unless($access['can_read_business_data'] || ($access['role'] === 'staff' && $access['access_reason'] === 'owner_required'), 403, 'Your current business session cannot open app pages.');
        $link = $this->screen($id);
        $page = self::pages()[$id];
        abort_if(($page['owner_only'] ?? false) && $access['role'] !== 'owner', 403, 'This page is available to the business owner only.');
        $staffSettings = $access['role'] === 'staff' && in_array($id, ['settings', 'settings_account', 'settings_receipt', 'settings_barcode', 'settings_printer'], true);
        abort_unless($staffSettings || in_array($link['module'], $access['module_permissions'], true), 403, 'Your assigned role does not permit this page. Contact the business owner.');

        return $link;
    }
}
