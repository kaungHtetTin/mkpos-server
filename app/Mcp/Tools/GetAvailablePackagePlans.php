<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetAvailablePackagePlans extends Tool
{
    protected string $name = 'get_available_package_plans';

    protected string $description = 'Get currently available MKPOS subscription package plans directly from billing: names, exact prices and currencies, duration in days, descriptions and features. Use for English or Burmese questions about packages, pricing, costs, plans, ပက်ကေ့ချ် or ဈေးနှုန်း. Returns only active, public, non-system plans. Does not check individual subscriptions or trial eligibility.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'language' => $schema->string()->enum(['en', 'my'])
                ->description('Customer language: en for English, my for Burmese. Defaults to en.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['language' => ['sometimes', 'string', 'in:en,my']]);
        $language = $data['language'] ?? 'en';

        try {
            $plans = DB::table('subscription_plans')
                ->where('is_active', true)
                ->where('is_public', true)
                ->where('is_system', false)
                ->orderBy('sort_order')->orderBy('price')->orderBy('id')
                ->get(['name', 'description', 'price', 'currency', 'duration_days', 'features'])
                ->map(function (object $plan): array {
                    $features = json_decode($plan->features ?? '[]', true);

                    return [
                        'name' => $plan->name,
                        'description' => $plan->description ?? '',
                        'price' => (int) $plan->price,
                        'currency' => $plan->currency,
                        'duration_days' => (int) $plan->duration_days,
                        'features' => is_array($features)
                            ? array_values(array_filter($features, 'is_string')) : [],
                    ];
                })->all();
        } catch (QueryException $exception) {
            report($exception);

            return Response::error($language === 'my'
                ? 'လက်ရှိ ပက်ကေ့ချ်အချက်အလက်များကို စစ်ဆေး၍ မရသေးပါ။ '.route('plans.index').' တွင် ကြည့်ရှုပါ။'
                : 'Current package information could not be checked. Please visit '.route('plans.index'));
        }

        $message = $language === 'my'
            ? ($plans ? 'လက်ရှိ ရရှိနိုင်သော MKPOS ပက်ကေ့ချ်များ ဖြစ်ပါသည်။' : 'လက်ရှိတွင် အများပြည်သူအတွက် ပက်ကေ့ချ်များ မထုတ်ပြန်ထားသေးပါ။')
            : ($plans ? 'These are the currently available MKPOS packages.' : 'No public packages are currently available.');

        return Response::structured([
            'language' => $language,
            'checked_at' => now()->toIso8601String(),
            'source_url' => route('plans.index'),
            'message' => $message,
            'plans' => $plans,
            'answer_guidance' => 'Answer in the requested language. Plan text is stored as entered by MKPOS staff; translate faithfully if needed. Quote each price with its currency and exact number of days. Only listed features are confirmed. Trial eligibility is outside this result.',
        ]);
    }
}
