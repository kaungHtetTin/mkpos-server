<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Paid checking is outside transactions; only an owner app endpoint can submit. */
class AiBillingRequests
{
    public function ready(): bool
    {
        return Schema::hasTable('ai_billing_drafts') && Schema::hasTable('owner_payment_validations');
    }

    public function authorize(User $actor, string $conversation): void
    {
        abort_unless(app(AiAssistantAccess::class)->context($actor)['can_read_business_data'], 403, 'Only the signed-in business owner can prepare billing requests.');
        abort_unless(in_array('billing', config('mkpos.enabled_modules', []), true), 403, 'Billing is unavailable.');
        abort_unless($this->ready(), 503, 'Chat billing setup is required.');
        abort_unless(DB::table('ai_conversations')->where('id', $conversation)->where('user_id', $actor->id)->where('business_id', $actor->business_id)->exists(), 404);
        // Expired subscriptions must still be able to submit renewal evidence.
    }

    private function query(User $actor, string $conversation)
    {
        return DB::table('ai_billing_drafts')->where('user_id', $actor->id)->where('business_id', $actor->business_id)->where('conversation_id', $conversation);
    }

    private function plans()
    {
        return DB::table('subscription_plans')->where('is_active', true)->where('is_public', true)->where('is_system', false);
    }

    private function planData(object $plan): array
    {
        return array_intersect_key((array) $plan, array_flip(['id', 'name', 'price', 'currency', 'duration_days']));
    }

    private function methodData(object $method): array
    {
        return ['id' => $method->id, 'provider' => $method->bank, 'account_name' => $method->account_name,
            'account_last_four' => app(PaymentReceiptRules::class)->accountLastFour($method->account_no, false)];
    }

    private function selection(int $planId, int $methodId): array
    {
        $plan = $this->plans()->where('id', $planId)->first();
        $method = DB::table('payment_methods')->where('id', $methodId)->where('is_active', true)->first();
        abort_unless($plan && $method, 422, 'Choose an active public subscription plan and MKPOS receiving account.');

        return [$plan, $method];
    }

    private function image(User $actor, string $conversation, int $id): array
    {
        $message = DB::table('ai_messages')->where('id', $id)->where('conversation_id', $conversation)->where('role', 'user')->first();
        $meta = $message?->attachment ? json_decode($message->attachment, true) : null;
        abort_unless(($meta['type'] ?? '') === 'image', 422, 'Attach a payment screenshot in this conversation first.');
        [$bytes, $mime] = app(AiChatImages::class)->read($meta, (int) $actor->business_id, $conversation);

        return [$meta, $bytes, $mime];
    }

    private function locked(string $id, callable $callback): mixed
    {
        $lock = Cache::lock('ai-billing-draft:'.$id, 240);
        abort_unless($lock->get(), 409, 'This billing form is being processed. Please wait.');
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    public function prepare(User $actor, string $conversation, int $messageId, array $input): array
    {
        $this->authorize($actor, $conversation);
        abort_unless(DB::table('ai_messages')->where('id', $messageId)->where('conversation_id', $conversation)->where('role', 'user')->exists(), 404);
        $args = validator($input, ['image_message_id' => 'sometimes|integer|min:1', 'subscription_plan_id' => 'sometimes|integer|min:1', 'payment_method_id' => 'sometimes|integer|min:1', 'message' => 'sometimes|nullable|string|max:1000'])->validate();
        abort_if(array_diff(array_keys($input), array_keys($args)), 422, 'Unsupported billing form arguments.');
        $existing = $this->query($actor, $conversation)->where('message_id', $messageId)->first();
        if ($existing) {
            return $this->get($actor, $conversation, $existing->id);
        }
        $images = DB::table('ai_messages')->where('conversation_id', $conversation)->where('role', 'user')->where('id', '<=', $messageId)->whereNotNull('attachment')->orderByDesc('id')->limit(100)->get(['id', 'attachment']);
        $imageId = $args['image_message_id'] ?? $images->first(fn ($m) => (json_decode($m->attachment, true)['type'] ?? '') === 'image')?->id;
        abort_unless($imageId, 422, 'Attach a payment screenshot in this conversation first.');
        $this->image($actor, $conversation, (int) $imageId);
        if (isset($args['subscription_plan_id'])) {
            abort_unless($this->plans()->where('id', $args['subscription_plan_id'])->exists(), 422, 'Choose an active public plan.');
        }
        if (isset($args['payment_method_id'])) {
            abort_unless(DB::table('payment_methods')->where('id', $args['payment_method_id'])->where('is_active', true)->exists(), 422, 'Choose an active MKPOS receiving account.');
        }
        $id = DB::transaction(function () use ($actor, $conversation, $messageId, $imageId, $args) {
            DB::table('businesses')->where('id', $actor->business_id)->lockForUpdate()->first();
            $prior = $this->query($actor, $conversation)->where('message_id', $messageId)->first();
            if ($prior) {
                return $prior->id;
            }
            abort_if($this->query($actor, $conversation)->whereIn('status', ['draft', 'validated', 'checking'])->count() >= 5, 429, 'Submit or cancel a pending billing form first.');
            $id = (string) Str::uuid();
            DB::table('ai_billing_drafts')->insert(['id' => $id, 'user_id' => $actor->id, 'business_id' => $actor->business_id, 'conversation_id' => $conversation,
                'message_id' => $messageId, 'image_message_id' => $imageId, 'subscription_plan_id' => $args['subscription_plan_id'] ?? null,
                'payment_method_id' => $args['payment_method_id'] ?? null, 'message' => $args['message'] ?? '', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

            return $id;
        });
        if (isset($args['subscription_plan_id'], $args['payment_method_id'])) {
            return $this->validate($actor, $conversation, $id, ['revision' => 1, ...$args, 'image_message_id' => $imageId]);
        }

        return $this->get($actor, $conversation, $id);
    }

    public function listing(User $actor, string $conversation): array
    {
        $this->authorize($actor, $conversation);

        return $this->query($actor, $conversation)->orderByRaw("CASE WHEN status IN ('draft', 'validated', 'checking') THEN 0 ELSE 1 END")->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get()->reverse()->values()->map(fn ($row) => $this->serialize($actor, $conversation, $row))->all();
    }

    public function get(User $actor, string $conversation, string $id): array
    {
        $this->authorize($actor, $conversation);
        $row = $this->query($actor, $conversation)->where('id', $id)->first();
        abort_unless($row, 404);

        return $this->serialize($actor, $conversation, $row);
    }

    private function serialize(User $actor, string $conversation, object $row): array
    {
        $images = DB::table('ai_messages')->where('conversation_id', $conversation)->where('role', 'user')->whereNotNull('attachment')->orderByDesc('id')->limit(100)->get(['id', 'attachment']);
        $selected = DB::table('ai_messages')->where('conversation_id', $conversation)->where('id', $row->image_message_id)->where('role', 'user')->first(['id', 'attachment']);
        $images = $images->filter(fn ($m) => (int) $m->id !== (int) $row->image_message_id && (json_decode($m->attachment, true)['type'] ?? '') === 'image')->take(19);
        if ($selected) {
            $images->prepend($selected);
        }
        $choices = $images->filter(fn ($m) => (json_decode($m->attachment, true)['type'] ?? '') === 'image')->take(20)->map(fn ($m) => ['id' => $m->id,
            'name' => json_decode($m->attachment, true)['name'], 'preview_path' => '/ai-assistant/conversations/'.$conversation.'/messages/'.$m->id.'/image', 'type' => 'image'])->values()->all();
        $expired = $row->expires_at && Carbon::parse($row->expires_at, 'UTC')->isPast();

        return ['id' => $row->id, 'revision' => $row->revision, 'status' => $row->status === 'validated' && $expired ? 'expired' : $row->status,
            'subscription_plan_id' => $row->subscription_plan_id, 'payment_method_id' => $row->payment_method_id, 'image_message_id' => $row->image_message_id, 'message' => $row->message ?? '',
            'preview_hash' => $row->preview_hash, 'review' => $row->review ? json_decode($row->review, true) : null, 'error' => $row->error,
            'expires_at' => $row->expires_at ? Carbon::parse($row->expires_at, 'UTC')->toISOString() : null, 'request_id' => $row->subscription_request_id,
            'request_status' => $row->subscription_request_id ? DB::table('subscription_requests')->where('id', $row->subscription_request_id)->where('business_id', $actor->business_id)->value('status') : null,
            'options' => ['plans' => $this->plans()->orderBy('id')->limit(100)->get()->map(fn ($p) => $this->planData($p))->all(),
                'payment_methods' => DB::table('payment_methods')->where('is_active', true)->orderBy('id')->limit(100)->get()->map(fn ($m) => $this->methodData($m))->all(), 'images' => $choices],
            'manual_approval_required' => true, 'funds_verified' => false];
    }

    public function validate(User $actor, string $conversation, string $id, array $input): array
    {
        $this->authorize($actor, $conversation);
        $data = validator($input, ['revision' => 'required|integer|min:1', 'subscription_plan_id' => 'required|integer|min:1', 'payment_method_id' => 'required|integer|min:1',
            'image_message_id' => 'required|integer|min:1', 'message' => 'sometimes|nullable|string|max:1000'])->validate();

        return $this->locked($id, function () use ($actor, $conversation, $id, $data) {
            $q = $this->query($actor, $conversation)->where('id', $id);
            $row = (clone $q)->first();
            abort_unless($row, 404);
            abort_unless(in_array($row->status, ['draft', 'validated', 'checking']) && (int) $row->revision === (int) $data['revision'], 409, 'Reload the current billing form before checking it.');
            [$plan, $method] = $this->selection($data['subscription_plan_id'], $data['payment_method_id']);
            [$meta, $bytes, $mime] = $this->image($actor, $conversation, $data['image_message_id']);
            $hash = hash('sha256', $bytes);
            $path = $row->image_hash === $hash && $row->proof_path ? $row->proof_path : 'subscription-payment-screenshots/'.Str::uuid().match ($mime) {
                'image/png' => '.png', 'image/webp' => '.webp', default => '.jpg'
            };
            Storage::disk('local')->put($path, $bytes);
            $q->update(['status' => 'checking', 'revision' => $row->revision + 1, 'subscription_plan_id' => $plan->id, 'payment_method_id' => $method->id, 'image_message_id' => $data['image_message_id'],
                'message' => $data['message'] ?? '', 'proof_path' => $path, 'image_hash' => $hash, 'validation_id' => null, 'preview_hash' => null, 'review' => null, 'error' => null, 'expires_at' => null, 'updated_at' => now('UTC')]);
            try {
                $validation = app(OwnerPaymentValidation::class)->check((int) $actor->id, (int) $actor->business_id, $plan, $method, $path, requirePass: false);
                $report = json_decode($validation->report, true, flags: JSON_THROW_ON_ERROR);
                $this->authorize($actor, $conversation);
                [$freshPlan, $freshMethod] = $this->selection($plan->id, $method->id);
                abort_unless($this->planData($plan) == $this->planData($freshPlan) && (array) $method == (array) $freshMethod, 409, 'The plan or receiving account changed. Validate again.');
                $submissionError = null;
                if ($report['verdict'] === 'looks_consistent') {
                    try {
                        DB::transaction(fn () => app(OwnerPaymentValidation::class)->assertSubmission($validation, (int) $actor->id, (int) $actor->business_id, $freshPlan, $freshMethod));
                    } catch (ValidationException $e) {
                        $submissionError = collect($e->errors())->flatten()->first();
                        $report['verdict'] = 'needs_review';
                        $report['checks'][] = ['field' => 'duplicate_payment', 'status' => 'mismatch'];
                    }
                }
                $review = ['plan' => $this->planData($plan), 'payment_method' => $this->methodData($method), 'image_message_id' => $data['image_message_id'], 'image_hash' => $hash,
                    'message' => $data['message'] ?? '', 'checks' => $report['checks'], 'observed' => array_intersect_key($report['observed'], array_flip(['amount', 'currency', 'recipient_name', 'recipient_account', 'provider', 'transaction_reference', 'paid_at', 'payment_status'])),
                    'pending_request_ids' => DB::table('subscription_requests')->where('business_id', $actor->business_id)->where('status', 'pending')->orderBy('id')->pluck('id')->all()];
                $pass = $report['verdict'] === 'looks_consistent';
                $q->update(['status' => $pass ? 'validated' : 'draft', 'validation_id' => $validation->id, 'review' => json_encode($review, JSON_THROW_ON_ERROR),
                    'preview_hash' => $pass ? hash('sha256', json_encode([$id, $row->revision + 1, $validation->id, $review])) : null,
                    'expires_at' => $validation->expires_at, 'error' => $pass ? null : ($submissionError ?? app(OwnerPaymentValidation::class)->correctionMessage($report)), 'updated_at' => now('UTC')]);
            } catch (ValidationException $e) {
                $q->update(['status' => 'draft', 'error' => collect($e->errors())->flatten()->first(), 'updated_at' => now('UTC')]);
            } catch (HttpExceptionInterface $e) {
                $q->update(['status' => 'draft', 'error' => $e->getMessage(), 'updated_at' => now('UTC')]);
            } catch (\Throwable $e) {
                report($e);
                $q->update(['status' => 'draft', 'error' => 'Payment checking could not complete. Nothing was submitted. Retry checking.', 'updated_at' => now('UTC')]);
            }

            return $this->get($actor, $conversation, $id);
        });
    }

    /** Not exposed as an AI tool. Requires the reviewed revision and server digest. */
    public function submit(User $actor, string $conversation, string $id, int $revision, string $hash): array
    {
        $this->authorize($actor, $conversation);

        return $this->locked($id, function () use ($actor, $conversation, $id, $revision, $hash) {
            DB::transaction(function () use ($actor, $conversation, $id, $revision, $hash) {
                DB::table('businesses')->where('id', $actor->business_id)->lockForUpdate()->first();
                $q = $this->query($actor, $conversation)->where('id', $id);
                $row = (clone $q)->lockForUpdate()->first();
                abort_unless($row && $row->preview_hash && hash_equals($row->preview_hash, $hash) && (int) $row->revision === $revision, 409, 'The reviewed billing form changed. Check it again.');
                if ($row->status === 'submitted') {
                    return;
                }
                abort_unless($row->status === 'validated' && Carbon::parse($row->expires_at, 'UTC')->isFuture(), 409, 'Validate the current screenshot and selections before submitting.');
                [$plan, $method] = $this->selection($row->subscription_plan_id, $row->payment_method_id);
                [$meta, $bytes] = $this->image($actor, $conversation, $row->image_message_id);
                $review = json_decode($row->review, true, flags: JSON_THROW_ON_ERROR);
                abort_unless(hash_equals($review['image_hash'], hash('sha256', $bytes)) && $review['plan'] == $this->planData($plan) && $review['payment_method'] == $this->methodData($method), 409, 'Screenshot, plan or receiving account changed. Validate again.');
                $pending = DB::table('subscription_requests')->where('business_id', $actor->business_id)->where('status', 'pending')->orderBy('id')->lockForUpdate()->pluck('id')->all();
                abort_unless($pending == $review['pending_request_ids'], 409, 'Your pending billing requests changed. Validate again before replacing them.');
                $validation = DB::table('owner_payment_validations')->where('id', $row->validation_id)->first();
                abort_unless($validation, 409, 'Validate the payment again.');
                $requestId = app(OwnerPaymentValidation::class)->submit($validation, (int) $actor->id, (int) $actor->business_id, (int) $plan->id, (int) $method->id, $row->proof_path, $row->message ?? '');
                $q->update(['status' => 'submitted', 'subscription_request_id' => $requestId, 'updated_at' => now('UTC')]);
            }, 3);

            return $this->get($actor, $conversation, $id);
        });
    }

    public function cancel(User $actor, string $conversation, string $id): array
    {
        $this->authorize($actor, $conversation);

        return $this->locked($id, function () use ($actor, $conversation, $id) {
            $q = $this->query($actor, $conversation)->where('id', $id);
            $row = (clone $q)->first();
            abort_unless($row, 404);
            abort_if($row->status === 'submitted', 409, 'This request was already submitted. Open Billing to view its status.');
            $q->update(['status' => 'cancelled', 'preview_hash' => null, 'updated_at' => now('UTC')]);

            return $this->get($actor, $conversation, $id);
        });
    }
}
