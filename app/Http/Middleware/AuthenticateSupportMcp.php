<?php

namespace App\Http\Middleware;

use App\Services\McpSupportAccess;
use Closure;
use Illuminate\Http\Request;

class AuthenticateSupportMcp
{
    public function handle(Request $request, Closure $next, string $audience = 'account')
    {
        // Private MCP/evidence endpoints are APIs, including multipart validation errors.
        if (! $request->expectsJson()) {
            $request->headers->set('Accept', 'application/json');
        }
        $context = app(McpSupportAccess::class)->resolve($request->bearerToken(), $audience);
        abort_unless($context, 401, 'A valid, account-linked MCP support token is required.');
        $request->attributes->set('mcp_support', $context);
        try {
            return $next($request);
        } finally {
            $request->attributes->remove('mcp_support');
        }
    }
}
