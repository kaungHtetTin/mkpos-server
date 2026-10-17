<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShopChatRealtime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionAlertController extends Controller
{
    private function channel(Request $request): string
    {
        return 'private-shop.subscription.business.'.(int) $request->user('web')->business_id;
    }

    public function config(Request $request, ShopChatRealtime $realtime): array
    {
        return array_replace($realtime->settings(), ['enabled' => true, 'channel' => $this->channel($request)]);
    }

    public function auth(Request $request, ShopChatRealtime $realtime): array
    {
        $data = $request->validate(['channel_name' => ['required', 'string', 'max:200']]);
        abort_unless($data['channel_name'] === $this->channel($request), 403);
        return $realtime->auth($data['channel_name'], ['role' => 'owner', 'business_id' => (int) $request->user('web')->business_id], false);
    }

    public function index(Request $request): array
    {
        $data = $request->validate(['after_id' => ['nullable', 'integer', 'min:0']]);
        $query = DB::table('subscription_approval_events')->where('business_id', $request->user('web')->business_id);
        if (!isset($data['after_id'])) return ['cursor' => (int) $query->max('id'), 'items' => [], 'has_more' => false];
        $rows = $query->where('id', '>', $data['after_id'])->orderBy('id')->limit(51)->get(['id', 'subscription_request_id', 'plan_name', 'created_at']);
        $items = $rows->take(50)->map(fn ($row) => array_replace((array) $row, ['id' => (int) $row->id]));
        return ['items' => $items->values(), 'cursor' => $items->isEmpty() ? (int) $data['after_id'] : $items->last()['id'], 'has_more' => $rows->count() > 50]
            + ($items->isEmpty() ? [] : ['subscription' => app(\App\Services\SubscriptionService::class)->status((int) $request->user('web')->business_id)]);
    }
}
