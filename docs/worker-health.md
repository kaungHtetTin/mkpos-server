# Worker health endpoint

Open `GET /api/health/workers` on the Laravel backend, for example:

```text
http://127.0.0.1:8000/api/health/workers
```

The existing `GET /api/health` continues to check Laravel and MySQL. The new
endpoint returns HTTP **200** only when all required worker groups have recent
heartbeats and no detected stuck work. Otherwise it returns HTTP **503**.
Responses have `Cache-Control: no-store`; poll every 15–30 seconds. Public
responses contain worker status and last-seen timestamps, without queue payloads,
hostnames, PIDs, customer information, credentials or exception text.

`GET /api/office/health/workers` requires an authenticated Office administrator
and adds active instance counts, last success/failure times and aggregate queue
statistics. It accepts existing Office sessions or Office bearer authentication.
Opening the Office route without Office authentication returns JSON HTTP 401,
including normal browser navigation. A business-owner session does not grant
Office access. A new browser tab does not automatically attach an Office bearer
token stored by the Office app. Use the public route for a check without login.

## What is checked

| Worker | Required | Evidence |
| --- | --- | --- |
| `sales_ai` | Yes | Dedicated `sales-ai` connection/queue loop and job events |
| `wyne_wyne` | No | Replies run directly in HTTP; old worker diagnostics are optional |
| `customer_service` | When Telegram customer service is enabled | Dedicated `customer-service` queue, configuration and stuck-work checks |
| `scheduler` | Yes | Actual `schedule:run` start/finish; failed scheduled tasks fail the tick |
| `payment_validation` | When legacy work is queued/processing, or explicitly configured | Legacy `system-agent:validate-payments` start/finish |
| `default_queue` | When the default connection uses an asynchronous driver, separate from the two AI queues | Configured default connection and queue events |

Wyne Wyne runs directly in HTTP. The response identifies
it as `worker_required: false` and `mode: http`. Current payment-proof submission
checks still run during web requests and do not need queue workers.
To require an intentionally deployed legacy payment
worker even without pending tasks, set
`WORKER_HEALTH_PAYMENT_VALIDATION_REQUIRED=true` and reload configuration.

Queue loops write at most once every ten seconds while idle; idle heartbeats
expire after 45 seconds. Periodic scheduler/legacy-command heartbeats expire
after three minutes. A queue job receives a bounded busy grace period based on
its timeout, so an active AI request does not look like a stopped idle worker.
The legacy payment command receives a bounded allowance based on its batch limit.
Grace periods cannot prove a process is alive if it was forcibly killed; stale
or missing heartbeats then fail after the relevant deadline. When multiple queue
workers run, one healthy instance is sufficient for that queue group.

Database queues also fail health if ready work has waited over ten minutes or a
reservation has outlived its queue retry interval. Delayed jobs are measured from
their availability time. Lost Sales AI dispatches and legacy AI runs processing
longer than ten minutes are detected from the durable run records. Redis/SQS and other
non-database default queues currently report worker liveness only.

Worker states include `healthy`, `missing`, `stale`, `stopped`, `failed`,
`backlogged`, and `not_required`. Missing monitor storage returns
`monitor_not_ready`; database/query failures return `monitor_unavailable`, both
with HTTP 503. The route never starts a worker, enqueues a reply, calls an AI
provider, validates a payment, or updates a heartbeat. Heartbeat failures are
logged without interrupting normal background work.

This checks worker execution and queue progress. It does not validate provider
credentials, AI credit or response quality. The separate realtime server has
its own `/health` endpoint.

## Deployment

Apply the additive migration:

```sh
php artisan migrate --path=database/migrations/2026_10_26_000003_create_worker_heartbeats.php --force
```

Deploy the new code/configuration and restart long-running queue workers with
`php artisan queue:restart`; the process supervisor must start their replacement.
On Windows, run `start-sales-ai.cmd` again once its previous worker exits.
Stop the old Wyne Wyne worker; it is no longer used. Existing
workers loaded before this change cannot emit the new heartbeats.

Keep the minute scheduler active via cron/Task Scheduler using
`php artisan schedule:run`, or locally through `start-shop-scheduler.cmd`.
The next actual scheduler tick supplies its heartbeat. Do not run paid recovery
commands against live pending work just to make the health route green.

Rows are stored centrally in MySQL, so HTTP and worker processes can run on
different machines without sharing a filesystem cache. Queue rows use a hashed
host/process identity; periodic commands use a stable hashed host identity.
Successful scheduler ticks prune heartbeat rows older than seven days.

Run the isolated feature checks with:

```sh
php vendor/phpunit/phpunit/phpunit tests/Feature/WorkerHealthTest.php
```
