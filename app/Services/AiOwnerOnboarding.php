<?php

namespace App\Services;

use App\Ai\Skills\OwnerOnboarding;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Read-only, explicitly scoped evidence. Empty businesses are valid onboarding states. */
class AiOwnerOnboarding
{
    private function enabled(string $module): bool
    {
        return in_array($module, config('mkpos.enabled_modules', []), true);
    }

    private function table(string $table)
    {
        return DB::table($table)->where($table.'.business_id', app(TenantContext::class)->requireId());
    }

    public function screen(string $screen): array
    {
        return app(AiPageLinks::class)->screen($screen);
    }

    public function guide(string $topic): array
    {
        $guide = OwnerOnboarding::GUIDES[$topic];
        abort_unless(! $guide['modules'] || array_intersect($guide['modules'], config('mkpos.enabled_modules', [])), 403, 'This guide module is unavailable.');
        $screen = $guide['screen'];
        if ($topic === 'credit_accounts' && ! $this->enabled('customers')) {
            $screen = 'suppliers';
        }

        return ['topic' => $topic, ...$guide, 'screen' => $screen,
            'action' => $this->enabled(AiPageLinks::pages()[$screen]['module'] ?? $screen) ? $this->screen($screen) : null,
            'available_skills' => array_keys(OwnerOnboarding::GUIDES),
            'guidance' => 'Explain one relevant step at a time. These are app workflows, not proof that this business completed a step. Use the MKPOS knowledge tool for detailed field help. No practice/fake transactions in live data.'];
    }

    public function progress(): array
    {
        $steps = [];
        if ($this->enabled('settings')) {
            $profile = app(BusinessProfile::class)->read(app(TenantContext::class)->requireId());
            $name = trim($profile['shop_name']);
            $identity = $name !== '' && ! in_array(mb_strtolower($name), ['mkpos shop', 'your shop', 'my shop']);
            $steps[] = $this->step('business_identity', 'Business details', $identity ? 'complete' : 'needs_attention', 'settings',
                $identity ? 'Business name is configured. Review currency, phone and address for your business.' : 'Review the default or missing business name.', ! $identity);
            $payments = trim((string) $this->table('settings')->where('key', 'payment_methods')->value('value'));
            $steps[] = $this->step('payment_methods', 'Accepted payment methods', $payments !== '' ? 'complete' : 'review', 'settings',
                $payments !== '' ? 'Payment method names are saved; this does not verify bank/provider connectivity.' : 'Review the default accepted payment method names. No custom selection is saved.');
        }
        if ($this->enabled('products')) {
            $products = $this->table('products')->where('is_active', true);
            $count = (clone $products)->count();
            $steps[] = $this->step('products', 'Products', $count ? 'complete' : 'needs_attention', 'products', $count ? "$count active products are saved." : 'Add your first real product with its unit, cost and selling price.', ! $count);
            if ($count) {
                $stocked = (clone $products)->where('stock', '>', 0)->exists();
                $steps[] = $this->step('opening_inventory', 'Inventory review', $stocked ? 'complete' : 'review', 'products',
                    $stocked ? 'Some products have positive stock; verify actual quantities and units.' : 'No active product has positive stock. Review opening inventory or record an actual purchase. Service/free-item workflows may be intentional.');
                $negative = (clone $products)->where('stock', '<', 0)->count();
                if ($negative) {
                    $steps[] = $this->step('negative_stock', 'Negative stock', 'review', 'products', "$negative active products have negative stock. Review real movements before correcting.");
                }
                $zeroPrices = (clone $products)->where('price', '<=', 0)->count();
                if ($zeroPrices) {
                    $steps[] = $this->step('selling_prices', 'Selling price review', 'review', 'products', "$zeroPrices active products have zero or negative primary prices. Free products may be intentional; use pricing diagnostics for details.");
                }
            }
        }
        if ($this->enabled('sell') && $this->enabled('sales')) {
            $sale = $this->table('sales')->where('status', 'completed')->exists();
            $steps[] = $this->step('first_sale', 'First sale', $sale ? 'complete' : 'not_started', 'sell',
                $sale ? 'At least one completed sale exists.' : 'No completed sale yet. Use the first-sale guide when a real customer is ready. Do not create a test sale in live data.');
        }
        $complete = count(array_filter($steps, fn ($step) => $step['status'] === 'complete'));
        $next = collect($steps)->firstWhere('status', 'needs_attention') ?? collect($steps)->firstWhere('status', 'review') ?? collect($steps)->firstWhere('status', 'not_started');

        return ['steps' => $steps, 'completed' => $complete, 'total' => count($steps), 'next_step' => $next,
            'all_checked_steps_complete' => count($steps) > 0 && $complete === count($steps),
            'scope' => 'Current business and enabled modules only. Completion reflects saved evidence, not owner attestation, legal compliance, printer connectivity or a complete business audit.',
            'writes_performed' => false];
    }

    private function step(string $id, string $label, string $status, string $screen, string $evidence, bool $required = false): array
    {
        return compact('id', 'label', 'status', 'evidence', 'required') + ['action' => $this->screen($screen)];
    }

    public function run(string $tool, array $args): array
    {
        if ($tool === 'get_feature_guide') {
            return $this->guide($args['topic'] ?? 'business_setup');
        }
        if ($tool === 'open_app_screen') {
            return $this->screen($args['screen'] ?? 'settings');
        }
        $progress = $this->progress();
        if ($tool === 'get_next_setup_step') {
            return ['next_step' => $progress['next_step'], 'completed' => $progress['completed'], 'total' => $progress['total'], 'writes_performed' => false];
        }
        if ($tool === 'check_business_readiness') {
            return ['findings' => array_values(array_filter($progress['steps'], fn ($step) => $step['status'] !== 'complete')),
                'has_required_setup_gaps' => count(array_filter($progress['steps'], fn ($step) => $step['required'] && $step['status'] === 'needs_attention')) > 0,
                'scope' => $progress['scope'], 'writes_performed' => false];
        }

        return $progress;
    }
}
