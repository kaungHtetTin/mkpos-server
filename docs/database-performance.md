# Database request and connection load

MKPOS uses one reusable PDO connection per Laravel connection within a request.
SQL query count and new connection count are different metrics. The production
example opts into persistent PDO connections; existing `.env` files are not
changed by deployment. Development retains the non-persistent default.

## Existing Hostinger deployment

1. Confirm the account's simultaneous database connection allowance and the PHP
   handler. Persistent reuse depends on a persistent PHP worker runtime. Budget
   for every PHP worker, dedicated queue worker, cron process and other website
   using the same account. Connections are per process, not per logged-in user.
2. For the local database use the host-provided connection settings, normally
   `DB_HOST=localhost`, blank `DB_PORT`, and blank `DB_SOCKET` unless the host
   supplies an explicit socket path. Do not change credentials or `APP_KEY`.
3. Set `DB_PERSISTENT=true` in the server's private `.env`, then run
   `php artisan config:cache`. Restart supervised queue workers using the
   deployment's normal process manager. PHP workers may require recycling through
   the hosting panel before all connections use the new configuration.
4. Observe database errors and active/sleeping connections under ordinary traffic.
   Ask the host for account-scoped new-connection counts per second and denials.
   MySQL's global connection counter on a shared server includes other accounts.
   If simultaneous connections become the constraint, set `DB_PERSISTENT=false`,
   rebuild configuration and recycle workers while reviewing the worker budget.

Hostinger documents 20 new MySQL connections per second per account:
https://www.hostinger.com/support/how-to-fix-mysql-operation-not-permitted-error/
This limit is independent of CPU, storage and simultaneous connections. Reuse
reduces churn; startup/restart bursts can still open multiple new connections.
PHP runtime details: https://www.php.net/features.persistent-connections

## Client and query changes

- Desktop and mobile product photos load near the viewport, with at most three
  downloads active per application instance and starts spaced by at least 100 ms.
  This also limits bursts when individual downloads finish very quickly; it is
  not an account-wide request limit across different users. Identical paths share an in-flight
  read and a bounded memory-only blob cache (120 entries / 8 MiB). Session/cache
  clearing drops cached blobs and rejects late downloads from the previous scope.
  Each mounted image owns and releases its object URL. Authenticated endpoints and
  subscription/permission checks remain in use.
- Product photo versions depend on the stored photo path, so stock-only writes do
  not download the same photograph again. Replacement uploads generate new paths.
- Data-change refreshes merge for 150 ms and serialize callbacks that return their
  loading promise. Events during a read cause one follow-up read. Hidden retained
  pages remain dirty until activated; unchanged Back navigation adds no refresh.
- List endpoints only count rows when `with_total=true`; response formats and
  existing pagination remain unchanged.
- Reports combine related totals in SQL. Daily/monthly trend totals use bounded
  aggregate queries, returning one row each, with timezone boundaries calculated
  in PHP. This avoids MySQL timezone-table requirements and handles DST.

Build and deploy the desktop/mobile clients together with the PHP changes. No
database migration is required. Do not run load tests or the test suite against
the production database. Use the isolated `mkpos_testing` schema for regression
tests, including year/month boundaries, DST, counts and photo version behavior.

## Read-only price-type responses

`GET /api/price-types` only reads configured names, stored rules and usage counts.
Missing legacy rules return the schema defaults in memory (manual pricing, zero
markup, rounding 1, minimum profit 0). GET does not initialize or touch rule rows
and therefore cannot publish its own pricing-change invalidation event.
Usage counts use one conditional aggregate query for all configured names,
preserving database collation matching and excluding inactive/zero-price products.

Registration already persists the initial Retail rule. Creating or explicitly
editing a price type persists its rule. A legacy configured type with no row can
still be edited; creating a duplicate configured name is rejected. Actual pricing
mutations continue to emit realtime updates. No schema migration or client API
contract change is required. Restart long-running AI workers on deployment if
pricing tools have already loaded the old controller class.

Regression coverage: `PriceTypeReadOnlyTest` and `PriceTypeReadRealtimeTest` check
repeated GETs, unchanged timestamps, missing defaults, tenant isolation, name
collation, constant usage-query count, real CRUD and realtime event behavior.
