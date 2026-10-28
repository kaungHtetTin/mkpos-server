<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Psr\Log\LogLevel;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<Throwable>, LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'admin_pin',
        'authorization_pin',
        'owner_password',
        'current_password',
        'password',
        'password_confirmation',
        'api_key',
        'bot_token',
        'webhook_secret',
    ];

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        // Opening an API URL in a browser must not invoke an HTML login route.
        if ($request->is('api/*')) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return parent::unauthenticated($request, $exception);
    }

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->renderable(fn (AdminPinRequired $error) => response()->json([
            'message' => $error->getMessage(), 'code' => 'admin_pin_required',
            'allow_owner_recovery' => $error->allowOwnerRecovery,
        ], 403));
        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
