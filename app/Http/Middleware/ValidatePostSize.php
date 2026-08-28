<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize as LaravelValidatePostSize;

class ValidatePostSize extends LaravelValidatePostSize
{
    public function handle($request, Closure $next)
    {
        if ($request->isMethod('PUT') && $request->is('api/office/app-releases/*')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
