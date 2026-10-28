<?php

namespace App\Http\Middleware;

use App\Support\RateLimitIdentity;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ScopedThrottleRequests extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        // Route templates prevent changing an ID from bypassing an action limit.
        return hash('sha256', $request->method().'|'.$request->route()?->getDomain().'|'.$request->route()?->uri().'|'.RateLimitIdentity::forRequest($request));
    }

    protected function buildException($request, $key, $maxAttempts, $responseCallback = null)
    {
        if (is_callable($responseCallback)) {
            return parent::buildException($request, $key, $maxAttempts, $responseCallback);
        }
        $retry = max(1, $this->getTimeUntilNextRetry($key));
        $scope = $request->attributes->get('_rate_limit_scope', 'action');
        $requestId = (string) Str::uuid();
        // Log at most once per exhausted bucket per minute; never log credentials or bodies.
        if (Cache::add('rate-limit-log:'.hash('sha256', $key), true, 60)) {
            Log::notice('HTTP rate limit reached', ['request_id' => $requestId, 'scope' => $scope,
                'bucket' => hash('sha256', $key), 'method' => $request->method(), 'route' => $request->route()?->uri(), 'retry_after' => $retry]);
        }
        $headers = $this->getHeaders($maxAttempts, 0, $retry) + ['X-RateLimit-Scope' => $scope, 'X-Request-ID' => $requestId];

        return new HttpResponseException(response()->json([
            'message' => 'Too Many Attempts.', 'code' => 'rate_limited', 'rate_limit_scope' => $scope,
            'retry_after' => $retry, 'request_id' => $requestId,
        ], 429, $headers));
    }

    public function handle($request, \Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        $previous = $request->attributes->get('_rate_limit_scope');
        $request->attributes->set('_rate_limit_scope', is_numeric($maxAttempts) ? 'action' : (string) $maxAttempts);
        try {
            return parent::handle(...func_get_args());
        } finally {
            $request->attributes->set('_rate_limit_scope', $previous);
        }
    }
}
