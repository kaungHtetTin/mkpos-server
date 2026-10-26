<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Durable proposals and an owner-app-only confirmation boundary. */
class AiBusinessActions
{
    public function __construct(private AiBusinessOperations $operations) {}

    public function ready(): bool
    {
        return Schema::hasTable('ai_business_actions');
    }

    public function authorize(User $actor, string $conversation, bool $write = false, ?string $operation = null): void
    {
        app(AiBusinessReader::class)->run($actor, 'get_business_context', []);
        abort_unless(DB::table('ai_conversations')->where('id', $conversation)->where('business_id', $actor->business_id)->where('user_id', $actor->id)->exists(), 404);
        abort_unless($this->ready(), 503, 'Business actions are not installed yet.');
        if ($write) {
            abort_unless(app(AiAssistantSettings::class)->get()['enabled'], 503, 'MKPOS AI is disabled.');
            abort_unless(app(SubscriptionService::class)->status((int) $actor->business_id)['can_mutate'], 402, 'An active subscription is required to save business changes.');
            if ($operation && ! app(AiManagementOperations::class)->supports($operation)) {
                abort_unless(in_array($this->operations->module($operation), config('mkpos.enabled_modules', []), true), 403, 'This business module is unavailable.');
            }
        }
    }

    private function query(User $actor, string $conversation): Builder
    {
        return DB::table('ai_business_actions')->where('business_id', $actor->business_id)->where('user_id', $actor->id)->where('conversation_id', $conversation);
    }

    public function prepare(User $actor, string $conversation, int $messageId, string $operation, array $input): array
    {
        $this->authorize($actor, $conversation, true, $operation);
        abort_unless(DB::table('ai_messages')->where('id', $messageId)->where('conversation_id', $conversation)->where('role', 'user')->exists(), 404);
        if ($operation === 'create_purchase_draft') {
            abort_unless(($input['conversation_id'] ?? '') === $conversation, 422, 'Use an invoice from this conversation.');
        }
        $requestKey = hash('sha256', $operation.'|'.$this->encode($this->canonical($input)));
        $id = DB::transaction(function () use ($actor, $conversation, $messageId, $operation, $input, $requestKey) {
            // Serializes this business's AI writers, including duplicate confirmations/imports.
            $business = DB::table('businesses')->where('id', $actor->business_id)->lockForUpdate()->first();
            $existing = $this->query($actor, $conversation)->where('message_id', $messageId)->where('request_key', $requestKey)->first();
            if ($existing) {
                return $existing->id;
            }
            abort_if($this->query($actor, $conversation)->where('status', 'pending')->where('expires_at', '>', now('UTC'))->count() >= 10, 429, 'Review or cancel your pending changes before preparing more.');
            $built = $this->operations->build($operation, $input, (int) $actor->id);
            $id = (string) Str::uuid();
            $review = ['items' => $built['preview'], 'requires_pin' => $built['requires_pin'] ?? false, 'requires_payment_verification' => $built['requires_payment_verification'] ?? false, 'requires_goods_verification' => $built['requires_goods_verification'] ?? false, 'import_summary' => $built['import_summary'] ?? null, 'currency' => $business->currency, 'timezone' => $business->timezone];
            $hash = hash('sha256', $this->encode([$operation, $built['payload'], $built['baseline'], $review]));
            DB::table('ai_business_actions')->insert(['id' => $id, 'business_id' => $actor->business_id, 'user_id' => $actor->id,
                'conversation_id' => $conversation, 'message_id' => $messageId, 'request_key' => $requestKey,
                'operation' => $operation, 'status' => 'pending', 'payload' => $this->encode($built['payload']), 'baseline' => $this->encode($built['baseline']),
                'preview' => $this->encode($review),
                'preview_hash' => $hash, 'expires_at' => now('UTC')->addMinutes(30), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

            return $id;
        });

        return $this->get($actor, $conversation, $id);
    }

    public function listing(User $actor, string $conversation, int $offset = 0): array
    {
        $this->authorize($actor, $conversation);
        $q = $this->query($actor, $conversation)->orderByDesc('created_at')->orderByDesc('id');
        $total = (clone $q)->count();
        $items = $q->offset($offset)->limit(20)->get()->map(fn ($row) => $this->serialize($row, 0, 0))->all();

        return ['items' => $items, 'total' => $total, 'has_more' => $offset + count($items) < $total, 'next_offset' => $offset + count($items) < $total ? $offset + count($items) : null];
    }

    public function get(User $actor, string $conversation, string $id, int $offset = 0, int $limit = 20): array
    {
        $this->authorize($actor, $conversation);
        $row = $this->query($actor, $conversation)->where('id', $id)->first();
        abort_unless($row, 404, 'Action unavailable.');

        return $this->serialize($row, $offset, $limit);
    }

    private function serialize(object $row, int $offset, int $limit): array
    {
        $preview = json_decode($row->preview, true, flags: JSON_THROW_ON_ERROR);
        $total = count($preview['items']);
        $items = array_slice($preview['items'], $offset, $limit);

        return ['id' => $row->id, 'operation' => $row->operation,
            'status' => $row->status === 'pending' && Carbon::parse($row->expires_at, 'UTC')->isPast() ? 'expired' : $row->status,
            'requires_pin' => $preview['requires_pin'] ?? false, 'requires_payment_verification' => $preview['requires_payment_verification'] ?? false, 'requires_goods_verification' => $preview['requires_goods_verification'] ?? false, 'message_id' => $row->message_id, 'preview_hash' => $row->preview_hash,
            'target_names' => array_column(array_slice($preview['items'], 0, 3), 'name'),
            'currency' => $preview['currency'] ?? null,
            'business_timezone' => $preview['timezone'] ?? null,
            'preview' => ['items' => $items, 'total' => $total, 'offset' => $offset, 'has_more' => $offset + count($items) < $total, 'next_offset' => $offset + count($items) < $total ? $offset + count($items) : null],
            'import_summary' => $preview['import_summary'], 'result' => $row->result ? json_decode($row->result, true, flags: JSON_THROW_ON_ERROR) : null,
            'expires_at' => Carbon::parse($row->expires_at, 'UTC')->toISOString(), 'confirmed_at' => $row->confirmed_at, 'completed_at' => $row->completed_at];
    }

    /** Called only by the authenticated app endpoint, never a model tool. */
    public function confirm(User $actor, string $conversation, string $id, string $previewHash, int $reviewedCount, array $approval = []): array
    {
        $this->authorize($actor, $conversation);
        DB::transaction(function () use ($actor, $conversation, $id, $previewHash, $reviewedCount, $approval) {
            $business = DB::table('businesses')->where('id', $actor->business_id)->lockForUpdate()->first();
            $q = $this->query($actor, $conversation)->where('id', $id);
            $row = (clone $q)->lockForUpdate()->first();
            abort_unless($row, 404, 'Action unavailable.');
            abort_unless(hash_equals($row->preview_hash, $previewHash), 409, 'The reviewed proposal does not match. Reopen the preview.');
            if ($row->status === 'completed') {
                return;
            } // A replay remains free and cannot post again.
            $this->authorize($actor, $conversation, true, $row->operation);
            abort_unless($row->status === 'pending', 409, 'This action is no longer pending.');
            abort_if(Carbon::parse($row->expires_at, 'UTC')->isPast(), 409, 'This proposal expired. Ask the assistant to prepare it again.');
            $preview = json_decode($row->preview, true, flags: JSON_THROW_ON_ERROR);
            abort_unless($business->currency === ($preview['currency'] ?? null) && $business->timezone === ($preview['timezone'] ?? null), 409, 'Business currency or timezone changed. Prepare a fresh proposal.');
            abort_unless($reviewedCount === count($preview['items']), 422, 'Review every proposed change before confirming.');
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            $baseline = json_decode($row->baseline, true, flags: JSON_THROW_ON_ERROR);
            abort_unless($this->operations->unchanged($row->operation, $payload, $baseline, (int) $actor->id), 409, 'Records changed since this preview. Nothing was saved; prepare a fresh proposal.');
            abort_if(($preview['requires_payment_verification'] ?? false) && ! ($approval['verified_payment'] ?? false), 422, 'Verify the actual payment in the owner form before approving.');
            abort_if(($preview['requires_goods_verification'] ?? false) && ! ($approval['verified_goods_return'] ?? false), 422, 'Confirm physical return of goods in the owner form before approving.');
            $result = $this->operations->execute($row->operation, $payload, (int) $actor->id, $approval);
            if (app(AiManagementOperations::class)->supports($row->operation)) {
                DB::table('ai_management_audits')->insert(['action_id' => $row->id, 'business_id' => $actor->business_id, 'user_id' => $actor->id, 'operation' => $row->operation, 'review' => $row->preview, 'result' => $this->encode($result), 'created_at' => now('UTC')]);
            }
            $q->update(['status' => 'completed', 'result' => $this->encode($result), 'confirmed_at' => now('UTC'), 'completed_at' => now('UTC'), 'updated_at' => now('UTC')]);
        }, 3);

        return $this->get($actor, $conversation, $id);
    }

    public function cancel(User $actor, string $conversation, string $id): array
    {
        $this->authorize($actor, $conversation);
        DB::transaction(function () use ($actor, $conversation, $id) {
            $q = $this->query($actor, $conversation)->where('id', $id);
            $row = (clone $q)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->status === 'completed', 409, 'This action was already saved.');
            if ($row->status === 'pending') {
                $q->update(['status' => 'cancelled', 'updated_at' => now('UTC')]);
            }
        });

        return $this->get($actor, $conversation, $id);
    }

    public function modelExecutionStatus(User $actor, string $conversation, string $id): array
    {
        // A model-provided boolean, hash, token or chat "yes" never crosses the approval boundary.
        $action = $this->get($actor, $conversation, $id);

        return $action + ['message' => $action['status'] === 'completed' ? 'The owner confirmed and this action is already saved.' : 'The owner must review and confirm this proposal in the MKPOS app. This tool cannot grant confirmation or save pending changes.'];
    }

    /** Editing prepares a new immutable proposal; approval is a separate app action. */
    public function revise(User $actor, string $conversation, string $id, string $hash, array $values): array
    {
        $this->authorize($actor, $conversation, true, 'manage_app_settings');

        return DB::transaction(function () use ($actor, $conversation, $id, $hash, $values) {
            DB::table('businesses')->where('id', $actor->business_id)->lockForUpdate()->first();
            $row = $this->query($actor, $conversation)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row && $row->operation === 'manage_app_settings', 404);
            abort_unless($row->status === 'pending' && ! Carbon::parse($row->expires_at, 'UTC')->isPast() && hash_equals($row->preview_hash, $hash), 409, 'Reopen a current pending settings proposal.');
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            abort_unless(in_array($payload['action'], ['create', 'update']), 422, 'Reset proposals cannot be edited.');
            abort_unless(count($values) === count($payload['values']) && ! array_diff(array_keys($values), array_keys($payload['values'])), 422, 'Edit only the proposed fields.');
            abort_if($values == $payload['values'], 422, 'Change a value before refreshing the review.');
            // Cancelling first releases a pending slot; rollback restores it if validation fails.
            $this->query($actor, $conversation)->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now('UTC')]);
            $next = $this->prepare($actor, $conversation, (int) $row->message_id, 'manage_app_settings', ['action' => $payload['action'], 'values' => $values]);
            abort_unless($next['status'] === 'pending', 409, 'This revision was already used. Prepare a new proposal.');

            return $next + ['replaces_id' => $id];
        });
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function canonical(array $input): array
    {
        if (! array_is_list($input)) {
            ksort($input);
        }
        foreach ($input as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $input;
    }
}
