<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ValidateMcpOrigin
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('mcp', 'mcp/*') && $request->headers->has('Origin')) {
            $origins = $request->headers->all('Origin');
            abort_unless(count($origins) === 1 && is_string($origins[0]) && in_array($origins[0], config('cors.allowed_origins', []), true), 403, 'Origin is not allowed.');
        }

        return $next($request);
    }
}
