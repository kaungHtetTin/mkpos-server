# Immediate AI replies through the existing Node socket server

The Node server can now wake the existing Laravel database queues immediately
after a committed job is enqueued. This covers Sales AI, Kaung Kaung, Sales AI
Telegram delivery, and Kaung Kaung receipt notices. It does not move the model,
wallet, customer permissions or payment logic into Node. Wyne Wyne is unchanged.

Laravel sends a short signed notification to Node `/ai-jobs`. Node acknowledges
it immediately and calls the fixed Laravel `/integrations/ai-jobs/process`
endpoint asynchronously. Each callback runs one real Laravel queue job in the
current PHP request. Laravel returns the next available job delay; Node drains
ready work or schedules the next callback for a delayed/released job. It uses no
`proc_open`, shell, child process, database credentials or API keys in Node.

## Deploy

1. Deploy the changed Laravel source, including the new config, service,
   middleware and controller; deploy the Node source including `ai-jobs.js`.
   Rebuild Composer's optimized class map if your deployment uses it. No migration
   or frontend build is needed.
2. Generate a new server-only random secret of at least 32 characters. For example,
   run `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` on your own server.
   Do not use a browser/Vite variable or share the secret in chat.
3. On the Node host, set:

   ```dotenv
   AI_JOBS_CALLBACK_URL=https://mkposmyanmar.com/integrations/ai-jobs/process
   AI_JOBS_SECRET=REPLACE_WITH_THE_NEW_RANDOM_SECRET
   ```

4. Forward POST `/ai-jobs` through the Node reverse proxy, just like `/events`.
   The supplied nginx example includes the route and a Laravel outbound-IP
   allowlist. Replace the example IP with the real Laravel outbound IP. Restart
   Node with the new environment. Keep the existing socket/chat settings.
5. On the Laravel host, set the same secret:

   ```dotenv
   AI_NODE_DISPATCH_ENABLED=true
   AI_NODE_DISPATCH_URL=https://socket.mkposmyanmar.com/ai-jobs
   AI_NODE_DISPATCH_SECRET=REPLACE_WITH_THE_SAME_RANDOM_SECRET
   ```

6. Run `php artisan config:cache` and, if routes were cached,
   `php artisan route:cache`. Run `php artisan queue:restart` for persistent workers;
   their supervisor must start the replacement processes. Cron workers load new
   code on their next invocation.
7. Keep the existing every-minute `schedule:run` and both `cron:work` entries.
   They remain the recovery path if Node restarts, a wakeup fails, or hosting
   interrupts a callback. Remove neither worker cron just to enable this feature.

Both endpoints require a fresh timestamp and HMAC over the exact request body.
Wake and process signatures have different purpose prefixes. Replayed request
IDs are rejected. The only payload is an allowlisted queue name and request UUID;
there is no client-supplied callback URL, command, job class, customer data or
provider credential. Node allows at most one active callback per queue, coalesces
bursts, and uses only its configured callback URL with redirects disabled.
Laravel shares the cron worker's OS lock, so a cron drain and Node callback do not
consume the same queue concurrently. The normal Laravel job/run idempotency and
business serialization remain authoritative.

## Verify and diagnose

- A POST with no signature to Node `/ai-jobs` should return 401 when enabled,
  or 503 when it has not been configured. A reverse-proxy 404 means the route is
  not forwarded. The Laravel callback behaves similarly. These unsigned checks
  do not process work or spend AI credit.
- After deployment, send a new customer message in a conversation set to AI.
  It should start without waiting for the next cron tick. Normal Sales AI jobs
  retain their existing two-second dispatch delay. Model/tool generation time
  is additional; this change removes the cron scheduling wait.
- Laravel logs `AI queue wakeup unavailable; cron will recover` if Node cannot
  accept a wakeup. Node logs `AI queue callback unavailable` (status only) or
  `AI queue callback interrupted` without private response bodies.
- `/api/health/workers` still monitors actual queue work and cron recovery.
  A healthy socket server alone does not prove the callback is configured.
- PHP callbacks still obey the host's HTTP execution, PHP pool and resource
  limits. Node gives a callback up to 370 seconds, but cannot extend a shorter
  hosting timeout. An ambiguous timeout is not immediately retried; cron and
  durable job/run claims recover it under the existing policy.

Disable immediate dispatch with `AI_NODE_DISPATCH_ENABLED=false` and rebuild the
Laravel config cache. Existing cron processing continues. Keep the lock files;
never delete them while processes are running.

## Tests

Run `php vendor/phpunit/phpunit/phpunit tests/Feature/AiNodeQueueTest.php` in
`laravel/` and `npm test` in `realtime-server/`. These use isolated SQLite queues,
real Laravel job execution with mocked runners, local HTTP callback fixtures,
and no live provider calls.
