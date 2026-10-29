<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use App\Support\DatabaseAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        // Never retry a failed write here: it may already have committed.
        $this->renderable(function (Throwable $error, Request $request) {
            if (! DatabaseAvailability::unavailable($error) || ! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json([
                'message' => 'The database is temporarily unavailable. Please try again shortly.',
                'code' => 'database_unavailable',
                'request_id' => $this->databaseRequestId($request),
            ], 503, ['Retry-After' => '15', 'X-Request-ID' => $this->databaseRequestId($request), 'Cache-Control' => 'no-store']);
        });
        $this->reportable(function (Throwable $error) {
            if (! DatabaseAvailability::unavailable($error)) {
                return null;
            }
            $request = $this->container->bound('request') ? $this->container->make('request') : null;
            $driver = $error;
            while ($driver->getPrevious()) {
                $driver = $driver->getPrevious();
            }
            Log::error('Database connection unavailable', [
                'request_id' => $request ? $this->databaseRequestId($request) : (string) Str::uuid(),
                'method' => $request?->method(),
                'route' => $request?->path(),
                'server' => gethostname(),
                'process_id' => getmypid(),
                'environment' => config('app.env'),
                'driver_code' => $driver instanceof \PDOException ? ($driver->errorInfo[1] ?? $driver->getCode()) : $driver->getCode(),
                'sqlstate' => $driver instanceof \PDOException ? ($driver->errorInfo[0] ?? null) : null,
                // The original driver error has no query text or bindings.
                'driver_message' => $driver instanceof \PDOException && ! $driver instanceof \Illuminate\Database\QueryException ? $driver->getMessage() : null,
            ]);

            return false;
        });
        $this->renderable(fn (AdminPinRequired $error) => response()->json([
            'message' => $error->getMessage(), 'code' => 'admin_pin_required',
            'allow_owner_recovery' => $error->allowOwnerRecovery,
        ], 403));
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    private function databaseRequestId(Request $request): string
    {
        if (! $request->attributes->has('_database_request_id')) {
            $request->attributes->set('_database_request_id', (string) Str::uuid());
        }

        return $request->attributes->get('_database_request_id');
    }
}
