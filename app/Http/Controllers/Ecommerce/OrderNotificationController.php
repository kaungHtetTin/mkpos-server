<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\ShopChatRealtime;
use App\Services\AccessService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderNotificationController extends Controller
{
    private function channel(): string
    {
        return 'private-shop.orders.business.'.app(TenantContext::class)->requireId();
    }

    public function config(ShopChatRealtime $realtime): array
    {
        return array_replace($realtime->settings(), ['enabled' => true, 'channel' => $this->channel()]);
    }

    public function auth(Request $request, ShopChatRealtime $realtime): array
    {
        $data = $request->validate(['channel_name' => ['required', 'string', 'max:200']]);
        abort_unless($data['channel_name'] === $this->channel(), 403);
        return $realtime->auth($this->channel(), ['role' => 'owner',
            'business_id' => app(TenantContext::class)->requireId()], false);
    }

    public function index(Request $request): array
    {
        $data = $request->validate(['after_id' => ['nullable', 'integer', 'min:0'], 'after_event_id' => ['nullable', 'integer', 'min:0']]);
        $query = DB::table('ecommerce_orders')->where('business_id', app(TenantContext::class)->requireId());
        $events = DB::table('ecommerce_order_events')->where('business_id', app(TenantContext::class)->requireId());
        $eventCursor = isset($data['after_event_id']) ? (int) $data['after_event_id'] : (int) (clone $events)->max('id');
        $changes = isset($data['after_event_id']) ? $events->where('id', '>', $eventCursor)->orderBy('id')->limit(51)->get(['id', 'order_id']) : collect();
        $updates = ['event_cursor' => $changes->take(50)->last()->id ?? $eventCursor,
            'changes' => $changes->take(50)->map(fn ($event) => ['event_id' => (int) $event->id, 'order_id' => (int) $event->order_id])->values(),
            'has_more_changes' => $changes->count() > 50];
        $attention = $this->attentionCount($request);
        // Opening the app establishes a baseline; only subsequent submissions alert.
        if (!isset($data['after_id'])) return $updates + ['cursor' => (int) $query->max('id'), 'items' => [], 'has_more' => false, 'action_required_count' => $attention];
        $rows = $query->where('id', '>', $data['after_id'])->orderBy('id')->limit(51)
            ->get(['id', 'reference', 'customer_name', 'total', 'currency']);
        $items = $rows->take(50)->map(fn ($row) => array_replace((array) $row, ['id' => (int) $row->id]));
        return $updates + ['items' => $items->values(), 'cursor' => $items->isEmpty() ? (int) $data['after_id'] : $items->last()['id'],
            'has_more' => $rows->count() > 50, 'action_required_count' => $attention];
    }

    private function attentionCount(Request $request): int
    {
        $businessId = app(TenantContext::class)->requireId();
        $permissions = app(AccessService::class)->permissions($request->user('web'));
        return DB::table('ecommerce_orders')->where('business_id', $businessId)->where(function ($query) use ($permissions, $businessId) {
            $query->whereRaw('0 = 1');
            if (in_array('ecommerce_process', $permissions, true)) {
                $query->orWhereIn('status', ['pending', 'accepted', 'packing', 'ready_for_pickup', 'dispatched', 'return_pending'])
                    ->orWhere(fn ($q) => $q->where('status', 'expired')->where('payment_status', 'proof_submitted'))
                    ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('shop_order_requests as cr')
                        ->whereColumn('cr.order_id', 'ecommerce_orders.id')->where('cr.business_id', $businessId)
                        ->where(fn ($q) => $q->where('cr.status', 'pending')->orWhere(fn ($q) => $q->where('cr.type', 'return')->where('cr.status', 'approved'))));
            }
            if (in_array('ecommerce_payments', $permissions, true)) {
                $query->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('payment_method->provider')->orWhere('payment_method->provider', 'manual'))
                    ->where(fn ($q) => $q->where(fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled', 'expired'])->whereIn('payment_status', ['unpaid', 'proof_submitted']))
                        ->orWhere(fn ($q) => $q->whereIn('status', ['cancelled', 'expired'])->where('payment_status', 'proof_submitted'))));
            }
            if (in_array('ecommerce_refunds', $permissions, true)) {
                $query->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('payment_method->provider')->orWhere('payment_method->provider', 'manual'))->where('payment_status', 'refund_pending'));
            }
        })->count();
    }
}
