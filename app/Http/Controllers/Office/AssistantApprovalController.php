<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\McpAdminProposals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AssistantApprovalController extends Controller
{
    public function index(Request $request): array
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'in:10,25,50,100']]);
        $rows = DB::table('mcp_admin_proposals as p')->leftJoin('platform_admins as a', 'a.id', '=', 'p.admin_id')
            ->where('p.status', 'pending')->where('p.expires_at', '>', now('UTC'))
            ->orderBy('p.id')->select('p.*', 'a.name as proposer_name')->paginate($data['per_page'] ?? 25);
        $items = $rows->getCollection()->map(function ($row) {
            $arguments = json_decode($row->arguments, true);
            $refund = $row->tool === 'process_refund_request';
            $subjectId = $arguments[$refund ? 'refund_id' : 'request_id'];
            $subject = DB::table($refund ? 'subscription_refund_requests' : 'subscription_requests')->where('id', $subjectId)->first();
            $business = $subject ? DB::table('businesses')->where('id', $subject->business_id)->first(['id', 'name']) : null;
            $plan = ! $refund && $subject ? DB::table('subscription_plans')->where('id', $subject->subscription_plan_id)->first() : null;
            $label = match ($row->tool) {
                'approve_subscription_payment' => 'Approve subscription payment',
                'reject_subscription_payment' => 'Reject subscription payment',
                default => match ($arguments['action']) { 'approve' => 'Approve refund for processing', 'reject' => 'Reject refund', default => 'Record completed refund transfer' },
            };

            return ['id' => (int) $row->id, 'label' => $label, 'subject_id' => (int) $subjectId,
                'business_id' => $business?->id, 'business_name' => $business?->name ?? 'Unavailable business',
                'proposer_name' => $row->proposer_name, 'expires_at' => \Carbon\Carbon::parse($row->expires_at, 'UTC')->toIso8601String(),
                'amount' => $refund ? $subject?->amount : ($arguments['price_paid'] ?? $plan?->price),
                'currency' => $refund ? $subject?->currency : $plan?->currency,
                'note' => $arguments[$refund ? 'review_note' : 'admin_note'],
                'transfer_reference' => $arguments['transfer_reference'] ?? null, 'transferred_at' => $arguments['transferred_at'] ?? null,
                'funds_verification_required' => $row->tool === 'approve_subscription_payment'];
        })->all();

        return ['items' => $items, 'total' => $rows->total(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage()];
    }

    public function approve(Request $request, int $id): array
    {
        abort_if(array_diff(array_keys($request->all()), ['confirmed', 'funds_verified']), 422, 'Action details cannot be changed during approval.');
        $row = DB::table('mcp_admin_proposals')->where('id', $id)->first();
        abort_unless($row, 404);
        $request->validate(['confirmed' => ['required', 'accepted'], 'funds_verified' => [$row->tool === 'approve_subscription_payment' ? 'required' : 'sometimes', 'accepted']]);

        return app(McpAdminProposals::class)->review($id, Auth::guard('office')->user(), true);
    }

    public function reject(int $id): array
    {
        return app(McpAdminProposals::class)->review($id, Auth::guard('office')->user(), false);
    }
}
