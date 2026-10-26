<?php

namespace App\Services;

use App\Ai\Agents\SystemAgent;
use App\Support\ThemePalette;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlatformTheme
{
    public function preview(object $admin, string $primary, string $key): array
    {
        $primary = strtolower($primary);
        $lock = Cache::lock('platform-theme:'.$admin->id, 120);
        abort_unless($lock->get(), 409, 'A palette is already being generated. Please wait.');
        try {
            $previous = DB::table('platform_theme_previews')->where('id', $key)->first();
            if ($previous) {
                abort_unless($previous->admin_id === $admin->id && $previous->primary_color === $primary, 409, 'Use a new request key for a different color.');

                return $this->view($previous);
            }
            $settings = app(AiAssistantSettings::class)->get();
            abort_unless($settings['enabled'] && $settings['configured'], 503, 'Enable and configure the System Agent in AI assistant settings to generate a theme.');
            $book = app(AiBalanceBook::class);
            $book->funded();
            $pricing = $book->pricing('deepseek-flash');
            try {
                app(AiProviderCredentials::class)->activate();
                $answer = (new SystemAgent('theme'))->prompt('Design matching light and dark theme seeds for primary color '.$primary.'.', provider: 'deepseek', model: 'deepseek-flash', timeout: 90);
                $data = validator($answer->toArray(), [...array_fill_keys(['secondary', 'neutral', 'success', 'warning', 'danger', 'info'], ['required', 'regex:/^#[0-9a-fA-F]{6}$/']), 'rationale' => 'required|string|max:240'])->validate();
                // Semantic colors retain recognizable meaning even if a provider supplies a poor seed.
                $defaults = ['success' => '#168252', 'warning' => '#bd790e', 'danger' => '#c23a4b', 'info' => '#3577b8'];
                foreach ($defaults as $role => $color) {
                    $data[$role] = ThemePalette::mix($color, $data[$role], .20);
                }
                $palettes = ThemePalette::generate($primary, $data);

                return DB::transaction(function () use ($key, $admin, $primary, $data, $palettes, $book, $answer, $pricing) {
                    $cost = $book->chargeSystem($key, $admin, 'deepseek-flash', $answer->usage->inputTokens, $answer->usage->outputTokens, $answer->usage->cacheReadInputTokens, $pricing, 'System theme palette generation');
                    DB::table('platform_theme_previews')->insert(['id' => $key, 'admin_id' => $admin->id, 'primary_color' => $primary,
                        'palettes' => json_encode($palettes), 'rationale' => $data['rationale'], 'estimated_cost_usd' => $cost,
                        'input_tokens' => $answer->usage->inputTokens, 'output_tokens' => $answer->usage->outputTokens, 'cached_input_tokens' => $answer->usage->cacheReadInputTokens,
                        'created_at' => now('UTC')]);

                    return $this->view(DB::table('platform_theme_previews')->where('id', $key)->first());
                });
            } catch (\Throwable $e) {
                Log::warning('Theme generation failed', ['exception_class' => get_class($e)]);
                abort(503, 'The System Agent could not generate a palette. Your current theme is unchanged. Please retry.');
            }
        } finally {
            $lock->release();
        }
    }

    private function view(object $row): array
    {
        return ['preview_id' => $row->id, 'primary_color' => $row->primary_color, 'palettes' => json_decode($row->palettes, true),
            'rationale' => $row->rationale, 'estimated_cost_usd' => $row->estimated_cost_usd];
    }
}
