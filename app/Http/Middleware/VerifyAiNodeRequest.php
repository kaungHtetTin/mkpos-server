<?php

namespace App\Http\Middleware;

use App\Services\AiQueueWakeup;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class VerifyAiNodeRequest
{
    public function handle(Request $request, Closure $next)
    {
        $secret = (string) config('ai_node.secret');
        abort_unless(config('ai_node.enabled') && strlen($secret) >= 32, 503, 'AI Node dispatch is disabled.');
        $body = $request->getContent();
        abort_if(strlen($body) > 1024, 413);
        $timestamp = $request->header('X-AI-Timestamp', '');
        $signature = $request->header('X-AI-Signature', '');
        abort_unless(preg_match('/^\d{10}$/D', $timestamp) && abs(time() - (int) $timestamp) <= 60
            && preg_match('/^[a-f0-9]{64}$/D', $signature)
            && hash_equals(hash_hmac('sha256', "process\n".$timestamp."\n".$body, $secret), $signature), 401);
        $data = json_decode($body, true);
        abort_unless(is_array($data) && count($data) === 2 && is_string($data['queue'] ?? null)
            && in_array($data['queue'], AiQueueWakeup::QUEUES, true)
            && is_string($data['request_id'] ?? null) && Str::isUuid($data['request_id']), 422);
        abort_unless(Cache::add('ai-node-request:'.$data['request_id'], true, 180), 409, 'Request already received.');
        $request->attributes->set('ai_node_queue', $data['queue']);

        return $next($request);
    }
}
