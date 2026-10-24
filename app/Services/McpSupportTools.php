<?php

namespace App\Services;

use App\Http\Controllers\Ecommerce\CustomerAccountController;
use App\Http\Controllers\Office\BusinessSubscriptionController;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class McpSupportTools
{
    public function run(string $tool, string $audience, bool $write, array $data): array
    {
        if ($audience === 'public') {
            return app(McpSupportKnowledge::class)->run($tool, $data);
        }
        $context = request()->attributes->get('mcp_support');
        abort_unless($context && (($context['type'] === 'admin') === ($audience === 'admin')), 403, 'This tool requires a linked account with the correct role.');
        abort_unless(in_array($write ? 'write' : 'read', $context['scopes'], true), 403, 'This connection has no permission for this operation. Reconnect from the signed-in app with the required consent.');
        if ($audience === 'account' && ! in_array($tool, ['get_my_profile', 'update_my_profile'], true)) {
            abort_unless($context['type'] === 'owner', 403, 'Subscription tools require a linked business owner account. Shop customer accounts have no business subscription.');
        }
        if (! $write) {
            return $this->execute($tool, $context, $data);
        }

        return DB::transaction(function () use ($tool, $context, $data) {
            $table = ['owner' => 'users', 'customer' => 'platform_customers', 'admin' => 'platform_admins'][$context['type']];
            DB::table($table)->where('id', $context['id'])->lockForUpdate()->first();
            $arguments = $data;
            unset($arguments['language']);
            ksort($arguments);
            $hash = hash('sha256', json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $lookup = ['actor_type' => $context['type'], 'actor_id' => $context['id'], 'tool' => $tool, 'idempotency_key' => $data['idempotency_key']];
            $previous = DB::table('mcp_support_actions')->where($lookup)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->arguments_hash, $hash), 409, 'This idempotency key was already used with different arguments.');

                return json_decode($previous->result, true);
            }
            $result = $this->execute($tool, $context, $data);
            DB::table('mcp_support_actions')->insert($lookup + ['arguments_hash' => $hash,
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $result;
        }, 3);
    }

    private function execute(string $tool, array $context, array $data): array
    {
        $businessId = $context['type'] === 'owner' ? (int) $context['actor']->business_id : null;
        if ($tool === 'get_my_profile') {
            $fields = $context['type'] === 'owner' ? ['id', 'name', 'email', 'role'] : ['id', 'name', 'email', 'phone', 'address'];

            return ['account_type' => $context['type'], 'profile' => array_intersect_key((array) ($context['actor'] instanceof Model ? $context['actor']->getAttributes() : $context['actor']), array_flip($fields))];
        }
        if ($tool === 'update_my_profile') {
            return $this->updateProfile($context, $data);
        }
        if ($tool === 'get_my_subscription_status') {
            $status = app(SubscriptionService::class)->status($businessId);
            // Explicitly whitelist customer fields; the service also has internal review data.
            $safe = array_intersect_key($status, array_flip(['is_valid', 'can_mutate', 'reason', 'access_type', 'is_trial', 'starts_at', 'ends_at', 'server_now', 'days_remaining', 'notice_code', 'capabilities']));
            $safe['plan'] = isset($status['subscription']) ? array_intersect_key($status['subscription'], array_flip(['id', 'plan_name', 'plan_slug', 'duration_days', 'features', 'status', 'access_type'])) : null;

            return ['subscription' => $safe];
        }
        if (in_array($tool, ['list_my_subscription_requests', 'get_my_subscription_request'], true)) {
            $query = DB::table('subscription_requests as r')->join('subscription_plans as p', 'p.id', '=', 'r.subscription_plan_id')
                ->where('r.business_id', $businessId)->select('r.id', 'r.status', 'r.subscription_plan_id', 'p.name as plan_name',
                    'r.message', 'r.created_at', 'r.reviewed_at');
            // admin_note is internal and may contain credentials or unredacted operator notes.
            if ($tool === 'get_my_subscription_request') {
                $row = $query->where('r.id', $data['request_id'])->first();
                abort_unless($row, 404, 'Request not found for this business.');

                return ['request' => (array) $row];
            }

            return $this->page($query->orderByDesc('r.id'), $data);
        }
        if ($tool === 'get_my_billing_history') {
            return $this->page(DB::table('subscription_payments as b')->join('subscription_plans as p', 'p.id', '=', 'b.subscription_plan_id')
                ->where('b.business_id', $businessId)->orderByDesc('b.id')
                ->select('b.id', 'b.type', 'b.amount', 'b.currency', 'b.duration_days', 'b.paid_at', 'p.name as plan_name'), $data);
        }
        if ($tool === 'submit_subscription_payment_proof') {
            return $this->submitProof($context, $data);
        }
        if ($tool === 'request_refund') {
            return $this->requestRefund($businessId, $data);
        }
        if ($tool === 'get_my_refund_status') {
            $row = DB::table('subscription_refund_requests')->where('business_id', $businessId)->where('id', $data['refund_id'])
                ->first(['id', 'subscription_payment_id', 'amount', 'currency', 'reason', 'status', 'review_note', 'transfer_reference', 'transferred_at', 'created_at', 'updated_at']);
            abort_unless($row, 404, 'Refund request not found for this business.');

            return ['refund' => (array) $row, 'note' => 'Approved means accepted for processing. Only transferred records a completed external transfer. Refunds do not automatically change subscription access.'];
        }
        if (in_array($tool, ['approve_subscription_payment', 'reject_subscription_payment'], true)) {
            return $this->reviewPayment($tool, $context, $data);
        }
        if ($tool === 'get_pending_refund_requests') {
            return $this->page(DB::table('subscription_refund_requests')->whereIn('status', ['pending', 'approved'])->orderBy('id')
                ->select('id', 'business_id', 'subscription_payment_id', 'amount', 'currency', 'reason', 'status', 'created_at'), $data);
        }
        if ($tool === 'process_refund_request') {
            return $this->processRefund($context, $data);
        }
        abort(404, 'Unknown account support tool.');
    }

    private function page($query, array $data): array
    {
        $limit = (int) ($data['limit'] ?? 5);
        $offset = (int) ($data['offset'] ?? 0);
        $rows = $query->offset($offset)->limit($limit + 1)->get();

        return ['items' => $rows->take($limit)->map(fn ($row) => (array) $row)->values()->all(),
            'has_more' => $rows->count() > $limit, 'next_offset' => $rows->count() > $limit ? $offset + $limit : null];
    }

    private function updateProfile(array $context, array $data): array
    {
        $name = trim($data['name']);
        abort_if($name === '', 422, 'Name cannot be blank.');
        if ($context['type'] === 'owner') {
            abort_unless(in_array('profile_write', $context['scopes'], true), 403, 'Reauthenticate in the owner app before allowing profile edits. Never provide passwords in chat.');
            abort_if(isset($data['phone']) || isset($data['address']), 422, 'Owner profile supports name only here. Use the app for other changes.');
            DB::table('users')->where('id', $context['id'])->update(['name' => $name, 'updated_at' => now()]);

            return ['updated' => true, 'profile' => ['name' => $name], 'changed_fields' => ['name']];
        }
        $actor = DB::table('platform_customers')->where('id', $context['id'])->first();
        $http = Request::create('/', 'PUT', ['name' => $name, 'phone' => array_key_exists('phone', $data) ? $data['phone'] : $actor->phone,
            'address' => array_key_exists('address', $data) ? $data['address'] : $actor->address]);
        $http->attributes->set('platform_customer', $actor);

        return ['updated' => true, 'profile' => app(CustomerAccountController::class)->profile($http)['customer']];
    }

    private function submitProof(array $context, array $data): array
    {
        $upload = DB::table('mcp_support_evidence')->where('id', $data['upload_id'])->where('actor_type', 'owner')
            ->where('actor_id', $context['id'])->whereNull('consumed_at')->where('expires_at', '>', now())->lockForUpdate()->first();
        abort_unless($upload && Storage::disk('local')->exists($upload->file_path), 404, 'Unexpired private evidence upload not found for this owner. Upload through POST /mcp/evidence first.');
        $plan = DB::table('subscription_plans')->where('id', $data['subscription_plan_id'])->where('is_active', true)
            ->where('is_public', true)->where('is_system', false)->first();
        $method = DB::table('payment_methods')->where('id', $data['payment_method_id'])->where('is_active', true)->first();
        abort_unless($plan && $method, 422, 'Choose a currently active public plan and payment method.');
        $businessId = (int) $context['actor']->business_id;
        DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
        DB::table('subscription_requests')->where('business_id', $businessId)->where('status', 'pending')
            ->update(['status' => 'rejected', 'admin_note' => 'Replaced by a newer request', 'reviewed_at' => now(), 'updated_at' => now()]);
        // Reuse the private uploaded image; no model-supplied path or remote URL is accepted.
        $id = DB::table('subscription_requests')->insertGetId([
            'business_id' => $businessId, 'subscription_plan_id' => $plan->id, 'payment_method_id' => $method->id,
            'payment_bank' => $method->bank, 'payment_account_name' => $method->account_name, 'payment_account_no' => $method->account_no,
            'status' => 'pending', 'message' => $data['message'] ?? '', 'payment_screenshot_path' => $upload->file_path,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('mcp_support_evidence')->where('id', $upload->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
        app(RealtimeActivity::class)->record('office', $businessId, 'subscription.requested', [
            'title' => 'New subscription request', 'message' => DB::table('businesses')->where('id', $businessId)->value('name'),
            'target' => 'subscription-request', 'entity_id' => (int) $id,
        ], 'subscription-request:'.$id);

        return ['request_id' => $id, 'status' => 'pending', 'funds_confirmed' => false,
            'note' => 'Evidence submitted for review. This does not approve a subscription or confirm receipt of funds.'];
    }

    private function reviewPayment(string $tool, array $context, array $data): array
    {
        $http = Request::create('/', 'POST', array_intersect_key($data, array_flip(['admin_note', 'price_paid'])));
        abort_if(trim($data['admin_note']) === '', 422, 'A review note is required.');
        $guard = Auth::guard('office');
        $previous = $guard->user();
        $guard->setUser($context['actor']);
        try {
            $controller = app(BusinessSubscriptionController::class);
            $result = $tool === 'approve_subscription_payment' ? $controller->approve($http, (int) $data['request_id'])
                : $controller->reject($http, (int) $data['request_id']);

            DB::table('office_activity_logs')->insert([
                'admin_id' => $context['actor']->id, 'admin_name' => $context['actor']->name, 'admin_email' => $context['actor']->email,
                'action' => 'McpSupportTools@'.$tool, 'method' => 'POST', 'path' => 'mcp/subscription-requests/'.$data['request_id'],
                'details' => json_encode(array_intersect_key($data, array_flip(['request_id', 'price_paid', 'admin_note']))), 'created_at' => now(),
            ]);

            // Avoid exposing internal subscription notes returned by the existing controller.
            return ['request_id' => (int) $data['request_id'], 'status' => $tool === 'approve_subscription_payment' ? 'approved' : 'rejected', 'ok' => $result['ok']];
        } finally {
            $guard->forgetUser();
            if ($previous) {
                $guard->setUser($previous);
            }
        }
    }

    private function requestRefund(int $businessId, array $data): array
    {
        $payment = DB::table('subscription_payments')->where('business_id', $businessId)->where('id', $data['payment_id'])->lockForUpdate()->first();
        abort_unless($payment, 404, 'Payment not found for this business.');
        $reserved = (int) DB::table('subscription_refund_requests')->where('subscription_payment_id', $payment->id)
            ->whereIn('status', ['pending', 'approved', 'transferred'])->sum('amount');
        abort_if($data['amount'] > (int) $payment->amount - $reserved, 422, 'Refund amount exceeds the unreserved original payment amount.');
        abort_if(mb_strlen(trim($data['reason'])) < 5, 422, 'Explain the refund request in at least five characters.');
        $id = DB::table('subscription_refund_requests')->insertGetId([
            'business_id' => $businessId, 'subscription_payment_id' => $payment->id, 'amount' => $data['amount'],
            'currency' => $payment->currency, 'reason' => trim($data['reason']), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['refund_id' => $id, 'status' => 'pending', 'amount' => (int) $data['amount'], 'currency' => $payment->currency,
            'note' => 'A refund request was recorded. No money was transferred and subscription access is unchanged.'];
    }

    private function processRefund(array $context, array $data): array
    {
        $row = DB::table('subscription_refund_requests')->where('id', $data['refund_id'])->lockForUpdate()->first();
        abort_unless($row, 404, 'Refund request not found.');
        $next = ['approve' => 'approved', 'reject' => 'rejected', 'record_transfer' => 'transferred'][$data['action']];
        $allowed = ($row->status === 'pending' && in_array($next, ['approved', 'rejected'], true))
            || ($row->status === 'approved' && in_array($next, ['transferred', 'rejected'], true));
        abort_unless($allowed, 409, 'This refund state does not allow the requested action.');
        abort_if(mb_strlen(trim($data['review_note'])) < 5, 422, 'A review note is required.');
        $update = ['status' => $next, 'review_note' => trim($data['review_note']), 'reviewed_by_admin_id' => $context['id'], 'updated_at' => now()];
        if ($next === 'transferred') {
            abort_unless(trim($data['transfer_reference'] ?? '') !== '' && isset($data['transferred_at']), 422, 'An actual external transfer reference and completion time are required.');
            $time = Carbon::parse($data['transferred_at']);
            abort_if($time->isFuture() || $time->lt(Carbon::parse($row->created_at)), 422, 'Transfer completion must be between the request creation time and now.');
            abort_if(DB::table('subscription_refund_requests')->where('transfer_reference', trim($data['transfer_reference']))->exists(), 409, 'This transfer reference is already recorded.');
            $update += ['transfer_reference' => trim($data['transfer_reference']), 'transferred_at' => $time];
        } else {
            abort_if(isset($data['transfer_reference']) || isset($data['transferred_at']), 422, 'Transfer fields are only accepted for record_transfer.');
        }
        DB::table('subscription_refund_requests')->where('id', $row->id)->update($update);

        return ['refund_id' => (int) $row->id, 'status' => $next, 'amount' => (int) $row->amount, 'currency' => $row->currency,
            'bank_transfer_initiated' => false, 'subscription_access_changed' => false];
    }
}
