<?php

namespace App\Services;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Invoice evidence creates a reviewed draft only; native Purchase Save posts it. */
class AiInvoicePurchases
{
    public function prepare(User $actor, ?string $conversation, ?int $message, array $args): array
    {
        app(AiBusinessAdvisor::class)->authorize($actor, ['purchases', 'products']);
        abort_unless($conversation && $message, 422, 'An authenticated conversation is required.');
        $action = app(AiBusinessActions::class)->prepare($actor, $conversation, $message, 'create_purchase_draft', ['conversation_id' => $conversation, ...$args]);

        return ['action' => $action, 'status' => 'awaiting_owner_review', 'posted' => false, 'payment_verified' => false, 'instruction' => 'Review all source lines, matched products, units, quantities and costs. Confirming saves only a draft. Open the draft in New Purchase and explicitly save there to receive stock or record payment.'];
    }

    public function build(array $input, int $userId): array
    {
        abort_unless(in_array('products', config('mkpos.enabled_modules', []), true) && in_array('purchases', config('mkpos.enabled_modules', []), true), 403, 'Products and purchases modules are required.');
        $allowed = ['conversation_id', 'image_message_id', 'supplier_id', 'supplier_name', 'invoice_reference', 'invoice_total', 'currency', 'items'];
        abort_if(array_diff(array_keys($input), $allowed), 422, 'Unsupported invoice draft fields.');
        $a = validator($input, ['conversation_id' => 'required|uuid', 'image_message_id' => 'required|integer|min:1', 'supplier_id' => 'sometimes|nullable|integer|min:1', 'supplier_name' => 'sometimes|nullable|string|max:255', 'invoice_reference' => 'sometimes|nullable|string|max:120', 'invoice_total' => 'required|integer|min:0|max:1000000000000', 'currency' => 'required|string|max:10', 'items' => 'required|array|min:1|max:50', 'items.*' => 'array:product_id,source_name,unit_name,quantity,foc_quantity,unit_cost', 'items.*.product_id' => 'required|integer|min:1', 'items.*.source_name' => 'required|string|max:255', 'items.*.unit_name' => 'required|string|max:50', 'items.*.quantity' => 'required|numeric|min:0.001|max:1000000', 'items.*.foc_quantity' => 'sometimes|numeric|min:0|max:1000000', 'items.*.unit_cost' => 'required|integer|min:0|max:1000000000'])->validate();
        $business = app(TenantContext::class)->requireId();
        abort_unless(DB::table('ai_conversations')->where('id', $a['conversation_id'])->where('user_id', $userId)->where('business_id', $business)->exists(), 404);
        $image = DB::table('ai_messages')->where('conversation_id', $a['conversation_id'])->where('id', $a['image_message_id'])->where('role', 'user')->first();
        $meta = $image?->attachment ? json_decode($image->attachment, true) : null;
        abort_unless(($meta['type'] ?? '') === 'image', 422, 'Choose an invoice image from this conversation.');
        [$bytes] = app(AiChatImages::class)->read($meta, $business, $a['conversation_id']);
        $currency = DB::table('businesses')->where('id', $business)->value('currency');
        $normalize = fn ($v) => in_array(strtoupper(trim($v)), ['MMK', 'KS', 'KYAT'], true) ? 'MMK' : strtoupper(trim($v));
        abort_unless($normalize($currency) === $normalize($a['currency']), 422, 'Invoice currency differs from business currency. Currency conversion is not inferred.');
        $supplier = null;
        if (! empty($a['supplier_id'])) {
            $supplier = DB::table('suppliers')->where('business_id', $business)->where('id', $a['supplier_id'])->where('is_active', true)->first();
            abort_unless($supplier, 422, 'Choose an active supplier in this business.');
        }
        $supplierName = $supplier?->name ?? trim($a['supplier_name'] ?? '');
        abort_unless($supplierName !== '', 422, 'Read or ask for the supplier name.');
        $items = [];
        $baseline = ['image_hash' => hash('sha256', $bytes), 'products' => [], 'supplier' => $supplier ? (array) $supplier : null];
        $preview = [];
        $total = 0;
        $preview[] = ['name' => 'Invoice draft (not posted)', 'before' => null, 'after' => ['supplier' => $supplierName, 'invoice_reference' => $a['invoice_reference'] ?? '', 'invoice_total' => $a['invoice_total'], 'currency' => $currency, 'payment_status' => 'Unverified; choose payment details in New Purchase']];
        foreach ($a['items'] as $i) {
            $p = DB::table('products')->where('business_id', $business)->where('id', $i['product_id'])->where('is_active', true)->first();
            abort_unless($p, 422, 'Every invoice line must match an active product in this business.');
            $unit = $i['unit_name'];
            $factor = $unit === $p->base_unit ? 1 : ($p->purchase_unit && $unit === $p->purchase_unit ? (float) $p->purchase_conversion_factor : 0);
            abort_unless($factor > 0, 422, 'Invoice unit must match the product base or configured purchase unit.');
            $quantity = (float) $i['quantity'];
            $foc = (float) ($i['foc_quantity'] ?? 0);
            abort_if(abs($quantity * 1000 - round($quantity * 1000)) > 0.00001 || abs($foc * 1000 - round($foc * 1000)) > 0.00001, 422, 'Quantities support up to 3 decimal places.');
            $line = $quantity * (int) $i['unit_cost'];
            $total += $line;
            $items[] = ['product_id' => $p->id, 'product_name' => $p->name, 'source_name' => $i['source_name'], 'unit_name' => $unit, 'conversion_factor' => $factor, 'quantity' => $quantity, 'foc_quantity' => $foc, 'unit_cost' => (int) $i['unit_cost'], 'line_total' => (int) round($line)];
            $baseline['products'][$p->id] = (array) $p;
            $preview[] = ['record_id' => $p->id, 'name' => $p->name, 'before' => null, 'after' => ['invoice_name' => $i['source_name'], 'unit' => $unit, 'conversion_factor' => $factor, 'quantity' => $quantity, 'foc_quantity' => $foc, 'unit_cost' => (int) $i['unit_cost'], 'line_total' => (int) round($line)]];
        }
        abort_unless((int) round($total) === (int) $a['invoice_total'], 422, 'Extracted lines do not match invoice total. Resolve discounts, tax, missing or unclear lines before preparing a draft.');

        return ['payload' => ['invoice_input' => $a, 'form' => ['supplier_id' => $supplier?->id, 'supplier_name' => $supplierName, 'note' => trim('Invoice '.($a['invoice_reference'] ?? '')), 'payment_method' => 'Cash', 'paid_amount' => 0, 'items' => $items], 'total' => (int) round($total)], 'baseline' => $baseline, 'preview' => $preview];
    }

    public function save(array $payload, int $userId): array
    {
        $id = (string) Str::uuid();
        $a = $payload['invoice_input'];
        DB::table('ai_purchase_drafts')->insert(['id' => $id, 'business_id' => app(TenantContext::class)->requireId(), 'user_id' => $userId, 'image_message_id' => $a['image_message_id'], 'conversation_id' => $a['conversation_id'], 'data' => json_encode($payload['form'], JSON_THROW_ON_ERROR), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return ['purchase_draft_id' => $id, 'posted' => false, 'payment_verified' => false, 'modules' => [], 'message' => 'Invoice purchase draft saved. Stock and payments are unchanged. Open New Purchase to review and explicitly post.'];
    }

    public function show(User $actor, string $id): array
    {
        app(AiBusinessAdvisor::class)->authorize($actor, ['purchases', 'products']);
        $row = DB::table('ai_purchase_drafts')->where('id', $id)->where('business_id', $actor->business_id)->where('user_id', $actor->id)->first();
        abort_unless($row, 404);
        $form = json_decode($row->data, true, flags: JSON_THROW_ON_ERROR);
        foreach ($form['items'] as &$item) {
            $p = DB::table('products')->where('id', $item['product_id'])->where('business_id', $actor->business_id)->where('is_active', true)->first();
            abort_unless($p, 409, 'A matched product is unavailable; prepare a new draft.');
            $factor = $item['unit_name'] === $p->base_unit ? 1 : ($item['unit_name'] === $p->purchase_unit ? (float) $p->purchase_conversion_factor : 0);
            abort_unless($factor > 0 && abs($factor - $item['conversion_factor']) < 0.0000001, 409, 'Product units changed; prepare a new draft.');
            $item['product'] = (array) $p;
        }unset($item);

        return ['id' => $row->id, 'form' => $form, 'posted' => false, 'payment_verified' => false];
    }
}
