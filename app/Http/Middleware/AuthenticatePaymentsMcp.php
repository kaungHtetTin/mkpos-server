<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePaymentsMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('mcp_payments.token');
        if (strlen($expected) < 32) {
            return response()->json(['message' => 'Payments MCP is not configured.'], 503);
        }
        $provided = (string) $request->bearerToken();
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
