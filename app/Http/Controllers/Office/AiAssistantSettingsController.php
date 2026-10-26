<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\AiAssistantSettings;
use App\Services\AiProviderCredentials;
use App\Services\AiUsageReport;
use App\Services\DeepSeekPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AiAssistantSettingsController extends Controller
{
    public function show(AiAssistantSettings $settings): array
    {
        return ['settings' => $settings->get(), 'pricing' => app(DeepSeekPricing::class)->get(), 'usage' => [
            'today_requests' => app(AiUsageReport::class)->runs()->where('created_at', '>=', now(AiUsageReport::TIMEZONE)->startOfDay()->utc())->where('created_at', '<=', now('UTC'))->count(),
            'today_failures' => app(AiUsageReport::class)->runs()->where('created_at', '>=', now(AiUsageReport::TIMEZONE)->startOfDay()->utc())->where('created_at', '<=', now('UTC'))->where('status', 'failed')->count(),
            'input_tokens' => app(AiUsageReport::class)->runs()->sum('input_tokens'), 'output_tokens' => app(AiUsageReport::class)->runs()->sum('output_tokens'), 'cached_input_tokens' => app(AiUsageReport::class)->runs()->sum('cached_input_tokens')],
            'usage_charts' => app(AiUsageReport::class)->charts(),
            'recent_runs' => DB::table('ai_runs')->orderByDesc('created_at')->limit(20)->get(['id', 'status', 'model', 'tools', 'input_tokens', 'output_tokens', 'cached_input_tokens', 'created_at'])];
    }

    public function update(Request $request, AiAssistantSettings $settings): array
    {
        $data = $request->validate(['enabled' => 'sometimes|required|boolean', 'model' => 'sometimes|required|string|max:100|regex:/^[a-zA-Z0-9._-]+$/', 'weekly_limit' => 'sometimes|required|integer|min:1|max:500', 'max_tokens' => 'sometimes|required|integer|min:256|max:8000',
            'usd_to_mmk_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999999999.999999', 'decimal:0,6'],
            'sales_ai_markup_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1000', 'decimal:0,2']]);
        abort_if(($data['enabled'] ?? false) && ! $settings->get()['configured'], 422, 'Configure a DeepSeek API key before enabling the assistant.');
        DB::table('ai_assistant_settings')->where('id', 1)->update($data + ['updated_at' => now('UTC')]);

        return $this->show($settings);
    }

    public function replaceKey(Request $request, AiProviderCredentials $credentials, AiAssistantSettings $settings): array
    {
        $data = $request->validate(['api_key' => ['required', 'string', 'min:10', 'max:512', 'regex:/^[\x21-\x7E]+$/']]);
        $credentials->replace($data['api_key']);

        return ['settings' => $settings->get()];
    }

    public function testConnection(Request $request, AiProviderCredentials $credentials): array
    {
        $data = $request->validate(['api_key' => ['nullable', 'string', 'min:10', 'max:512', 'regex:/^[\x21-\x7E]+$/']]);
        $key = $data['api_key'] ?? $credentials->key();
        abort_unless(filled($key), 422, 'Enter an API key or configure one first.');
        try {
            $response = Http::acceptJson()->withToken($key)->connectTimeout(5)->timeout(15)->get('https://api.deepseek.com/models');
        } catch (\Throwable) {
            abort(503, 'Could not connect to DeepSeek. Try again shortly.');
        }
        abort_if(in_array($response->status(), [401, 403], true), 422, 'DeepSeek rejected this API key. Check the key and try again.');
        abort_unless($response->successful() && is_array($response->json('data')), 503, 'DeepSeek is unavailable. Try again shortly.');

        return ['message' => 'Connection successful.'];
    }
}
