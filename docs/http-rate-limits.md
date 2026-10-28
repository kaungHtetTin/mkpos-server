# HTTP 429 diagnosis and deployment

Numeric route throttles now count requests by method, route template and validated actor. For example, `POST /chat/presence` cannot exhaust the customer login counter, and changing a thread ID cannot bypass a messages limit. Existing numeric thresholds remain in place. Guests retain IP limits; staff, Office admins and authenticated platform customers have separate identities. Custom customer and Office authentication runs before throttling.

The API limiter separates normal reads, writes, AI delivery checks, chat background work, notification background work, product images and checkout work. Shop public and action throttles still apply in addition to the API limiter. General reads and writes each have a finite 60 requests/minute budget; AI delivery checks also have a separate 60/minute budget. These limits are application instance/cache limits: multiple PHP servers should use a shared cache for consistent enforcement.

## Response and log inspection

Application throttle responses return HTTP 429 with `code: rate_limited`, `rate_limit_scope`, `retry_after` (seconds), and `request_id`. Headers include `Retry-After`, `X-RateLimit-Scope`, and `X-Request-ID`; CORS exposes them. AI weekly quotas instead return `code: ai_weekly_quota`, weekly usage and the next reset time in `Retry-After`. A rejected order/action is never automatically resubmitted.

In browser DevTools → Network, inspect the failing request's method, URL, response body and these headers. A 429 without these markers may originate from hosting, a proxy, or another application quota; HTTP status alone does not establish which one. Each SPA exposes safe diagnostics as `error.rateLimit` (`source`, `scope`, `requestId`) and the delay as `error.retryAfterMs`.

Search the configured Laravel log for `HTTP rate limit reached`. With the default single channel this is `storage/logs/laravel.log`; a daily channel writes `storage/logs/laravel-YYYY-MM-DD.log`. The diagnostic is logged at **notice** level, at most once per exhausted bucket per minute. Ensure the configured log level includes notice. Entries contain the route template, method, scope, retry interval, request ID and a hashed bucket; they do not contain bearer tokens, customer text, tracking credentials or uploaded files. Because logging is sampled, not every 429 request ID has a separate log entry.

If a 429 has no application markers and no matching application diagnostics, inspect the hosting access/error/security logs. Proxy trust must match the actual deployment; do not trust arbitrary forwarded IP headers or globally trust every proxy to work around a limit.

## Browser background behavior

Desktop web, mobile, Office and Shop background feeds share polling/socket work between same-origin tabs with the same account scope using Web Locks and BroadcastChannel. A visible tab owns the feed; another tab can take over after it closes or becomes hidden. Unsupported browsers retain ordinary polling. Guest tracking feeds remain independent so tracking credentials never become cross-tab keys or payloads. There is no persistent response cache or token broadcast. Login/logout invalidates the corresponding shared feed generation, including late results. Only short-lived cooldown metadata is shared for matching authenticated account scopes.

Socket notification bursts are collapsed into a 150 ms refresh window. Ordinary polling pauses in hidden/offline pages. AI delivery reconciliation is also shared per account/conversation/message and honors `Retry-After`, including online/visibility wakeups, backs off connection failures and only reads the saved request's status. Local simultaneous background GETs are coalesced; requests with independent abort signals retain their own lifecycle. Writes, order confirmation and payment submission are never replayed after a 429. Existing form and receipt state remains available for a deliberate user retry.

## Deploy

Deploy the Laravel source and rebuilt `public/app`, `public/mobile`, `public/shop`, and `public/office` directories together. No migration or new environment variable is required. From the deployed Laravel directory run:

```sh
php artisan optimize:clear
php artisan queue:restart
```

Rebuild the production configuration/route caches if your normal deployment uses them, then reload browser tabs to load the new assets. The Node socket service does not need a code change for this patch.

Local verification includes backend isolation/quota/CORS tests, SPA network/feed/receipt retention tests, and `node scripts/check-background-coordination.cjs`, which exercises real Chromium tabs with local fixtures and no live orders or provider calls.
