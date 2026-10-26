<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiAssistantSettings
{
    public function get(): array
    {
        $defaults = ['enabled' => config('ai_assistant.enabled', false), 'model' => config('ai_assistant.model'), 'weekly_limit' => 50, 'max_tokens' => 1600, 'usd_to_mmk_rate' => null, 'sales_ai_markup_percent' => '5.00'];
        $ready = Schema::hasTable('ai_assistant_settings');
        $row = $ready ? DB::table('ai_assistant_settings')->where('id', 1)->first() : null;

        $public = $row ? array_intersect_key((array) $row, $defaults) : [];
        if (isset($public['usd_to_mmk_rate'])) {
            $public['usd_to_mmk_rate'] = (string) $public['usd_to_mmk_rate'];
        }
        $public['sales_ai_markup_percent'] = number_format((float) ($public['sales_ai_markup_percent'] ?? 5), 2, '.', '');

        return array_merge($defaults, $public, ['provider' => 'deepseek', 'enabled' => (bool) ($row->enabled ?? $defaults['enabled']), 'configured' => filled(app(AiProviderCredentials::class)->key()), 'storage_ready' => $ready]);
    }
}
