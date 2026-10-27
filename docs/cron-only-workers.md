# AI workers on cron-only hosting

Wyne Wyne uses direct HTTP replies and requires no worker cron. Remove any old
`cron:work wyne-wyne` entry. The scheduler recovers Telegram jobs but does not consume its queue.
Upload the updated PHP source before adding these jobs. No migration is required for
this command (the existing worker-heartbeat migration must already be installed).

Keep the existing `artisan schedule:run` cron. Add separate Custom cron entries,
each running every minute (`* * * * *`), using the real absolute artisan path:

```sh
/opt/alt/php83/usr/bin/php /ABSOLUTE/LARAVEL/PATH/artisan cron:work sales-ai
```

If Telegram customer service is enabled, add:

```sh
/opt/alt/php83/usr/bin/php /ABSOLUTE/LARAVEL/PATH/artisan cron:work customer-service
```

Each command holds a nonblocking OS file lock for its queue on this server. A
concurrent cron invocation exits successfully without processing work or writing
a heartbeat. Use shared storage and supervised workers for multi-server deployments.
An idle worker exits immediately; a worker with jobs stops between jobs after 45
seconds. An in-flight job can take up to 360 seconds, so the hosting execution limit
must permit it. Provider-imposed process limits can still interrupt AI replies.
Do not delete the lock files while workers are running.

The cron worker records `cron_idle` with a stable per-host heartbeat. Health allows
180 seconds between runs; busy jobs retain their existing timeout plus 60 seconds
allowance. Missing/stale/failed workers and old backlogs still fail health. Existing
persistent workers retain the 45-second idle threshold. Cron replies can wait about
a minute before starting; they do not have persistent-worker latency.

Adding these jobs processes queued replies and may use AI credit. For a failed
scheduler, inspect the latest cron output and `storage/logs/laravel.log`; queue
workers do not fix failures in other scheduled tasks. An earlier successful run
does not establish that the latest run succeeded.

This patch is newer than the original 3.0.0 release archives; those archives must
be rebuilt or these PHP files uploaded separately.
