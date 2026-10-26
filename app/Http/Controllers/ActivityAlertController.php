<?php

namespace App\Http\Controllers;

use App\Services\ShopChatRealtime;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityAlertController extends Controller
{
    private function scope(Request $request): array
    {
        if ($request->is('api/office/*')) {
            return ['audience' => 'office', 'channel' => 'private-office.activity',
                'identity' => ['role' => 'office', 'admin_id' => (int) $request->user('office')->id]];
        }
        $business = (int) $request->user('web')->business_id;
        return ['audience' => 'owner', 'business_id' => $business, 'channel' => 'private-shop.activity.business.'.$business,
            'identity' => ['role' => 'owner', 'business_id' => $business]];
    }
    public function config(Request $request, ShopChatRealtime $realtime): array
    {
        return array_replace($realtime->settings(), ['enabled' => true, 'channel' => $this->scope($request)['channel']]);
    }
    public function auth(Request $request, ShopChatRealtime $realtime): array
    {
        $scope = $this->scope($request);
        $data = $request->validate(['channel_name' => ['required', 'string', 'max:200']]);
        abort_unless($data['channel_name'] === $scope['channel'], 403);
        return $realtime->auth($scope['channel'], $scope['identity'], false);
    }
    public function index(Request $request): array
    {
        $scope = $this->scope($request);
        $data = $request->validate(['after_id' => ['nullable', 'integer', 'min:0']]);
        $query = DB::table('realtime_activity_events')->where('audience', $scope['audience']);
        if (isset($scope['business_id'])) $query->where('business_id', $scope['business_id']);
        if (!isset($data['after_id'])) return ['cursor' => (int) $query->max('id'), 'items' => [], 'has_more' => false];
        $rows = $query->where('id', '>', $data['after_id'])->orderBy('id')->limit(51)->get();
        $items = $rows->take(50)->map(fn ($row) => ['id' => (int) $row->id, 'event' => $row->event, 'created_at' => $row->created_at] + json_decode($row->payload, true));
        $result = ['items' => $items->values(), 'cursor' => $items->isEmpty() ? (int) $data['after_id'] : $items->last()['id'], 'has_more' => $rows->count() > 50];
        if (isset($scope['business_id']) && $items->contains(fn ($item) => str_starts_with($item['event'], 'subscription.'))) {
            $result['subscription'] = app(SubscriptionService::class)->status($scope['business_id']);
        }
        if (isset($scope['business_id']) && $items->contains(fn ($item) => str_starts_with($item['event'], 'sales_ai.'))) {
            $ids = $items->where('target', 'sales-ai-topup')->pluck('entity_id')->unique()->all();
            $result['sales_ai'] = [
                'wallet' => app(\App\Services\SalesAi\Wallet::class)->view($scope['business_id']),
                'wallet_changed' => $items->contains(fn ($item) => in_array($item['event'], ['sales_ai.topup.approved', 'sales_ai.wallet.adjusted'], true)),
                'requests' => DB::table('sales_ai_topups')->where('business_id', $scope['business_id'])->whereIn('id', $ids)
                    ->get()->map(fn ($row) => app(\App\Services\SalesAi\Topups::class)->present($row))->all(),
            ];
        }
        return $result;
    }
}
