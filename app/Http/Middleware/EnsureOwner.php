<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureOwner
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user('web')?->role === 'owner', 403, 'Only the business owner can perform this action.');

        return $next($request);
    }
}
