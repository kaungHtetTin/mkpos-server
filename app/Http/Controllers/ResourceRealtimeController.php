<?php

namespace App\Http\Controllers;

use App\Services\ShopChatRealtime;
use Illuminate\Http\Request;

class ResourceRealtimeController extends Controller
{
    public function config(Request $request, ShopChatRealtime $realtime): array
    {
        return array_replace($realtime->settings(), ['enabled' => true, 'channel' => $this->channel($request)]);
    }

    public function auth(Request $request, ShopChatRealtime $realtime): array
    {
        $channel = $this->channel($request);
        $data = $request->validate(['channel_name' => 'required|string|max:200']);
        abort_unless($data['channel_name'] === $channel, 403);
        $identity = $channel === 'public-platform.branding' ? ['role' => 'public'] : [
            'role' => 'business', 'business_id' => (int) $request->user('web')->business_id,
            'user_id' => (int) $request->user('web')->id,
        ];
        return $realtime->auth($channel, $identity, false);
    }

    private function channel(Request $request): string
    {
        return $request->is('api/branding/realtime/*') ? 'public-platform.branding'
            : 'private-business.resources.'.(int) $request->user('web')->business_id;
    }
}
