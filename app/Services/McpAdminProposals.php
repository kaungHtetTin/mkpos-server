<?php

namespace App\Services;

use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class McpAdminProposals
{
    public const TOOLS = ['approve_subscription_payment', 'reject_subscription_payment', 'process_refund_request'];

    public function prepare(string $tool, array $context, array $data): array
    {
        abort_unless(in_array($tool, self::TOOLS, true), 403, 'This administrator action is unavailable.');
        abort_unless(Schema::hasTable('mcp_admin_proposals'), 503, 'Office approval setup is required. No action was performed.');
        unset($data['language']);
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tool, $context, $data, $hash) {
            PlatformAdmin::whereKey($context['id'])->lockForUpdate()->firstOrFail();
            $lookup = ['admin_id' => $context['id'], 'tool' => $tool, 'idempotency_key' => $data['idempotency_key']];
            $row = DB::table('mcp_admin_proposals')->where($lookup)->first();
            if ($row) {
                abort_unless(hash_equals($row->arguments_hash, $hash), 409, 'This idempotency key belongs to different action details.');

                return $this->response($row);
            }
            $subject = $this->subject($tool, $data, true);
            $data['_subject_hash'] = $this->subjectHash($tool, $subject);
            $id = DB::table('mcp_admin_proposals')->insertGetId($lookup + [
                'token_id' => $context['token_id'], 'credential_version' => hash('sha256', $context['actor']->password),
                'arguments_hash' => $hash, 'arguments' => json_encode($data, JSON_THROW_ON_ERROR),
                'status' => 'pending', 'expires_at' => now('UTC')->addMinutes(30), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);

            return $this->response(DB::table('mcp_admin_proposals')->where('id', $id)->first());
        });
    }

    private function response(object $row): array
    {
        $status = $row->status === 'pending' && ! now('UTC')->lt($row->expires_at) ? 'expired' : $row->status;

        return ['proposal_id' => (int) $row->id, 'status' => $status === 'pending' ? 'awaiting_office_approval' : $status,
            'approval_required' => $status === 'pending', 'expires_at' => \Carbon\Carbon::parse($row->expires_at, 'UTC')->toIso8601String(),
            'office_url' => rtrim((string) (config('clients.office_url') ?: url('/office')), '/').'#/payments',
            'note' => 'The chatbot prepared an action. Only an administrator reviewing it in Office can apply it. This does not initiate a bank transfer.',
            'result' => $row->result ? json_decode($row->result, true) : null];
    }

    public function subject(string $tool, array $data, bool $validate = false): object
    {
        $refund = $tool === 'process_refund_request';
        $row = DB::table($refund ? 'subscription_refund_requests' : 'subscription_requests')
            ->where('id', $data[$refund ? 'refund_id' : 'request_id'])->when($validate, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($row, 404, 'Payment or refund request not found.');
        if ($validate) {
            if ($refund) {
                $allowed = ($row->status === 'pending' && in_array($data['action'], ['approve', 'reject'], true))
                    || ($row->status === 'approved' && in_array($data['action'], ['reject', 'record_transfer'], true));
                abort_unless($allowed, 409, 'This refund state does not allow the requested action.');
            } else {
                abort_unless($row->status === 'pending', 409, 'This payment request is no longer pending.');
            }
        }

        return $row;
    }

    private function subjectHash(string $tool, object $subject): string
    {
        $plan = $tool === 'process_refund_request' ? null : DB::table('subscription_plans')->where('id', $subject->subscription_plan_id)->lockForUpdate()->first();

        return hash('sha256', json_encode([$subject, $plan], JSON_THROW_ON_ERROR));
    }

    public function review(int $id, PlatformAdmin $reviewer, bool $approve): array
    {
        return DB::transaction(function () use ($id, $reviewer, $approve) {
            $row = DB::table('mcp_admin_proposals')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404, 'Assistant proposal not found.');
            if ($row->status === 'approved' && $approve) return ['status' => 'approved', 'result' => json_decode($row->result, true)];
            abort_unless($row->status === 'pending', 409, 'This proposal has already been reviewed.');
            abort_unless(now('UTC')->lt($row->expires_at), 409, 'This proposal has expired. Ask the assistant to prepare a new action.');
            $result = null;
            if ($approve) {
                $actor = PlatformAdmin::whereKey($row->admin_id)->lockForUpdate()->first();
                $token = DB::table('mcp_support_tokens')->where('id', $row->token_id)->lockForUpdate()->first();
                abort_unless($actor && $actor->is_active && hash_equals($row->credential_version, hash('sha256', $actor->password))
                    && $token && $token->actor_type === 'admin' && (int) $token->actor_id === (int) $actor->id
                    && now()->lt($token->expires_at) && in_array('write', json_decode($token->scopes, true), true),
                    409, 'The originating assistant connection has expired or changed. Prepare a new action.');
                $data = json_decode($row->arguments, true, flags: JSON_THROW_ON_ERROR);
                $subject = $this->subject($row->tool, $data, true);
                abort_unless(isset($data['_subject_hash']) && hash_equals($data['_subject_hash'], $this->subjectHash($row->tool, $subject)),
                    409, 'The payment or refund details changed. Prepare a new action before approval.');
                unset($data['_subject_hash']);
                $result = app(McpSupportTools::class)->executeApprovedAdminAction($row->tool, $reviewer, $data);
            }
            $status = $approve ? 'approved' : 'rejected';
            DB::table('mcp_admin_proposals')->where('id', $id)->update(['status' => $status,
                'reviewed_by_admin_id' => $reviewer->id, 'result' => $result ? json_encode($result, JSON_THROW_ON_ERROR) : null, 'updated_at' => now('UTC')]);

            return ['status' => $status, 'result' => $result];
        }, 3);
    }
}
