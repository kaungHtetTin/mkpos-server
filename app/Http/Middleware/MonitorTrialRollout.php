<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MonitorTrialRollout
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            if ($request->is('api/auth/register')) {
                $this->write('error', 'trial.registration_failed', $request, [
                    'exception' => get_class($exception),
                ]);
            } elseif ($this->isConversionRequest($request)) {
                $this->write('error', 'trial.conversion_failed', $request, [
                    'exception' => get_class($exception),
                ]);
            }

            throw $exception;
        }

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        if ($response->getStatusCode() === 402) {
            $this->write('warning', 'trial.subscription_required', $request, [
                'reason' => $payload['subscription']['reason'] ?? null,
                'access_type' => $payload['subscription']['access_type'] ?? null,
            ]);
        } elseif ($response->getStatusCode() === 403 && ($payload['code'] ?? null) === 'trial_feature_restricted') {
            $this->write('warning', 'trial.paid_feature_restricted', $request, [
                'capability' => $payload['capability'] ?? null,
            ]);
        }

        return $response;
    }

    private function isConversionRequest(Request $request): bool
    {
        if ($request->isMethod('GET')) {
            return false;
        }

        return $request->is('api/office/businesses/*/subscription*')
            || $request->is('api/office/subscription-requests/*/approve');
    }

    private function write(string $level, string $event, Request $request, array $context = []): void
    {
        Log::channel((string) config('mkpos.trial.monitor_log_channel', 'trial_rollout'))
            ->{$level}($event, array_merge([
                'event' => $event,
                'method' => $request->method(),
                'path' => $request->path(),
                'business_id' => $request->user('web')?->business_id,
                'admin_id' => $request->user('office')?->id,
            ], $context));
    }
}
