<?php

namespace App\Services;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiQueueWakeup
{
    public const QUEUES = ['sales-ai', 'customer-service'];

    public function queued(JobQueued $event): void
    {
        if (! config('ai_node.enabled') || ! in_array($event->connectionName, self::QUEUES, true)
            || $event->queue !== $event->connectionName) {
            return;
        }
        DB::afterCommit(fn () => $this->send($event->queue));
    }

    private function send(string $queue): void
    {
        try {
            $secret = (string) config('ai_node.secret');
            $url = (string) config('ai_node.url');
            if (strlen($secret) < 32 || ! filter_var($url, FILTER_VALIDATE_URL)
                || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
                || (parse_url($url, PHP_URL_SCHEME) === 'http' && ! in_array(parse_url($url, PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]', '::1'], true))
                || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
                || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
                throw new \RuntimeException('AI Node dispatch is not configured.');
            }
            $body = json_encode(['queue' => $queue, 'request_id' => (string) Str::uuid()], JSON_THROW_ON_ERROR);
            $timestamp = (string) time();
            $response = Http::connectTimeout(1)->timeout(2)->withoutRedirecting()->withHeaders([
                'X-AI-Timestamp' => $timestamp,
                'X-AI-Signature' => hash_hmac('sha256', "wake\n".$timestamp."\n".$body, $secret),
            ])->withBody($body, 'application/json')->post($url);
            if ($response->status() !== 202) {
                throw new \RuntimeException('AI Node wakeup was not accepted.');
            }
        } catch (\Throwable $error) {
            // Durable queue work remains available to the cron worker.
            Log::warning('AI queue wakeup unavailable; cron will recover', ['queue' => $queue, 'exception_class' => get_class($error)]);
        }
    }
}
