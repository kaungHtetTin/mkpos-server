<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Pipeline;

class EnsureFrontendRequestsAreStatefulUnlessToken
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->header('X-MKPOS-Auth') === 'token') {
            return $next($request);
        }

        config([
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);

        $middleware = array_values(array_filter(array_unique([
            config('sanctum.middleware.encrypt_cookies', \App\Http\Middleware\EncryptCookies::class),
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            config('sanctum.middleware.verify_csrf_token', \App\Http\Middleware\VerifyCsrfToken::class),
        ])));

        array_unshift($middleware, function ($request, $next) {
            $request->attributes->set('sanctum', true);

            return $next($request);
        });

        return (new Pipeline(app()))->send($request)->through($middleware)->then($next);
    }
}
