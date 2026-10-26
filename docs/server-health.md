# Office server health

Super administrators can open **System → Server health**
(`#/system/server-health`). Overview does not load or display diagnostics.

The page uses compact grouped rows with component, status, check type and result.
Search and Filters work locally, groups collapse, and Back preserves the loaded
snapshot, search, filters and group state. Check now and the shared Refresh control
request fresh diagnostics. Automatic refresh runs every minute while the page and
browser tab are visible; returning by Back does not trigger an initial reload.
Failed refreshes show Unavailable while retaining explicitly stale previous results.

## Checks

The normal snapshot contains 33 checks:

- Runtime: PHP version, required extensions and an encryption round trip.
- Data/storage: database read query, pending migrations, persistent cache round trip,
  runtime directory permissions, public uploads mapping, free disk and session driver
  prerequisites. Remote session/object storage connectivity is explicitly unverified.
- Workers: Sales AI, Wyne Wyne, Telegram customer service, scheduler, payment validation
  and default queue. Reuses heartbeat freshness and backlog checks; optional workers
  become required according to configuration or queued work.
- AI: credential availability, positive platform credit, validated cached model pricing,
  exchange rate/markup, and Wyne Wyne availability. Pricing is read from its saved cache;
  the monitor never fetches new prices or calls a model.
- Integrations: realtime HTTP health, SMTP configuration for each email action, Telegram
  prerequisites and locally recorded webhook registration, MCP route authentication/token
  storage and optional payments MCP credentials.
- Deployment: production HTTPS/debug/cookie/CORS/token-expiry settings and local built
  app entries/assets for Business, Mobile, Office and Shop. Local asset presence does
  not verify remote client hosting or browser delivery.

Healthy, Needs attention, Unhealthy, Not required and Unverified remain distinct.
Any failed component makes the snapshot unhealthy; warnings/unverified checks make
it degraded. Disabled optional features do not cause failures. Development mode
reports a warning because production security checks are not enforced there.

## API and limits

`GET /api/office/health/system` requires an active Office super administrator,
is limited to 30 requests/minute and returns a no-store/private JSON diagnostic
document. It returns HTTP 200 even when a component is unhealthy so the page can
show the individual results. Use the existing `/api/health/workers` endpoint when
HTTP 200/503 worker liveness semantics are needed.

Checks do not send email/Telegram messages, register webhooks, run migrations,
change settings, process queued replies or spend AI credit. Cache testing writes a
unique 30-second probe key and removes it. Realtime probes only the configured
publishing server's `/health` endpoint, without credentials or redirects, with
one-second connect and two-second total timeouts. SMTP/provider credentials are
checked locally; remote authentication and delivery are not verified. Raw exception
text, secrets, hostnames, SQL and filesystem paths are excluded from responses.

Backups/restoration, remote hosting, resource monitoring beyond local disk space,
web-server private-file protections and end-to-end delivery still require deployment
monitoring. This page does not claim those have been verified.

Deploy the backend changes and rebuilt Office assets together. No migration or
worker restart is required specifically for the diagnostics page.
