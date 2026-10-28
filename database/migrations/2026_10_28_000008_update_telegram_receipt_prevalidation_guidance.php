<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_skills')) return;
        DB::transaction(function () {
            $skill = DB::table('ai_skills')->where('slug', 'sales-telegram-channel')->lockForUpdate()->first();
            if (! $skill || ! $skill->published_version_id) return;
            $marker = 'Telegram checkout receipt prevalidation';
            $addition = <<<'TEXT'

Telegram checkout receipt prevalidation
For non-COD Telegram checkout, ask the customer to select the payment method and upload a clear payment screenshot. The server queues receipt prevalidation on the Sales AI worker before enabling Confirm order. Explain that checking is in progress; do not prepare another order, claim payment is approved, or treat chat text as confirmation.
The server checks the receipt amount and currency against the current quote, the selected bank/wallet and recipient, transfer completion, transaction reference and duplicate use. An unreadable, incomplete, mismatched, duplicate or failed check blocks submission. Ask the customer to correct the receipt or use Check receipt again. A screenshot, payment-method, cart/quote change or expired check requires a fresh valid check. The final server check is authoritative.
After checks pass, ask the customer to review the displayed result and press the signed Confirm order button. For an existing order's receipt, use the same checks before Submit payment proof. Only the customer's signed callback submits; the agent cannot confirm through a tool or a chat yes. Prevalidation does not prove receipt of funds: the order/proof remains pending shop-owner verification. COD does not require a screenshot or this prevalidation.
TEXT;
            $published = json_decode(DB::table('ai_skill_versions')->where('id', $skill->published_version_id)->value('content'), true);
            if (str_contains($published['body'], $marker)) return;
            $published['body'] .= "\n".$addition;
            $draft = json_decode($skill->draft, true);
            if (! str_contains($draft['body'], $marker)) $draft['body'] .= "\n".$addition;
            $number = (int) DB::table('ai_skill_versions')->where('skill_id', $skill->id)->max('number') + 1;
            $id = DB::table('ai_skill_versions')->insertGetId(['skill_id' => $skill->id, 'number' => $number, 'content' => json_encode($published, JSON_UNESCAPED_UNICODE),
                'note' => $marker, 'created_at' => now('UTC')]);
            DB::table('ai_skills')->where('id', $skill->id)->update(['published_version_id' => $id, 'draft' => json_encode($draft, JSON_UNESCAPED_UNICODE),
                'revision' => DB::raw('revision + 1'), 'updated_at' => now('UTC')]);
        });
    }

    public function down(): void
    {
        // Published guidance history and administrator edits are preserved; restore a version in Office.
    }
};
