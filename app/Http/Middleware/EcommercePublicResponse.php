<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EcommercePublicResponse
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
