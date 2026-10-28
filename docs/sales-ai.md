# Sales AI implementation

## Required payment screenshots in Sales AI checkout

Shop-chat Sales AI checkout and the private Telegram checkout wizard now require
a reviewed screenshot for non-COD methods. Customers may calculate a quote first
to see the exact total and receiving details, but cannot confirm a transfer order
until an image is staged and reviewed. COD checkout requires no image. The native
order and its pending proof are submitted in one transaction through the existing
shop APIs; screenshots never approve payment or mark an order paid.

The Shop review card accepts JPG/PNG/WebP up to 2 MB, retains the chosen file and
entered details after errors and Close/reopen, and invalidates consent after edits.
The staged image is hash-bound to the review and checked again at confirmation.
Existing unpaid transfer orders still use `submit_order_payment_proof` separately.

In private Telegram chat, non-COD checkout asks for a photo/image before showing
Confirm order. For an existing own unpaid transfer order, the same AI proof tool
opens a native photo-and-Submit payment proof wizard. Guest orders are scoped to
the originating Telegram conversation; linked account orders remain ownership
checked. Group chats cannot prepare or submit proof. Chat text never confirms an
order or receipt. The existing tool permission must be enabled for attaching proof
to an existing order. Cancellation does not cancel an existing order. Staged images
are removed after success/cancellation and expired images are pruned by the
existing `sales-ai:prune-actions` scheduler task.

No migration is required. Deploy the PHP source including
`app/Services/SalesAi/CheckoutProof.php`, rebuild/deploy the customer Shop frontend,
and restart persistent workers. The Node socket server needs no change for this
flow. Existing native Shop checkout/order APIs retain their separate order/proof
workflow; the new mandatory pre-confirmation screenshot gate applies to Sales AI
checkout.

Verification covers API confirmation without proof, hash tampering, exactly-once
order/proof creation, COD compatibility, private Telegram receipt upload,
customer-bound callback signatures, and pending owner verification. Shop component
tests cover file review, blocked consent, multipart submission, failures and
Close/reopen draft retention. An isolated rendered browser fixture verifies the
same controls with fake APIs; it creates no live orders or provider calls.

## Realtime top-up activity

New top-up requests notify Office through the shared private activity socket,
using the same transaction-safe delivery as subscription requests. Approvals,
rejections and owner cancellations notify the business owner on desktop and
mobile; manual Office wallet adjustments also notify the owner. Approval and
rejection send a silent Office activity update so other open review lists stay
current without an additional toast to the reviewing admin.

Events are recorded with stable keys in the transaction that changes the request
or wallet. Publication occurs after the outer transaction commits; rollback and
successful retries produce no extra delivery or credit. Socket messages contain
only the event ID. The authenticated owner feed includes the current wallet and
affected request versions, scoped to that business, for immediate updates of
balances, lists and open details. Existing polling recovers missed socket events.

Office's Review request action opens the exact top-up, even outside the current
list filters. Owners can open the matching request or balance book from the
notification. Source search, filters, drafts, loaded records and Back navigation
are retained. The read-only Office detail endpoint is
`GET /api/office/sales-ai/topups/{id}`; existing Office authentication is required.

This uses the existing realtime server and activity tables. No new migration,
server protocol, queue worker or provider call is needed. Enable the existing
realtime connection as for subscription notifications. Browser verification with
an isolated socket server and intercepted financial APIs is available through
`node scripts/verify-sales-ai-topup-realtime.cjs` (local Vite ports 5174/5175/5176).

Goal: per-business sales representative inside existing shop/customer chat, with
human handoff, owner configuration and permissions, an MMK wallet, manual Office
top-up approvals and a one-time 500 MMK grant for newly created businesses.

## Required delivery checklist

- [x] Database: business configuration, wallet/immutable ledger, payment requests,
  durable AI runs, thread handling/version and clearly marked AI messages.
- [x] Owner APIs: owner-only settings and allowed tool catalog; MMK wallet and
  endless history; payment proof top-up submission, own detail and cancellation.
- [x] Office APIs/UI: global paginated top-ups with review, private evidence,
  rejection reason, exactly-once approval; management within business detail.
- [x] New-business 500 MMK grant exactly once; no retroactive grants to existing
  businesses. Promotional grants do not count as cash payments.
- [x] Actual Laravel AI SDK agent: configured name/instructions/soul; bounded,
  tenant/customer-scoped tools; public catalog/prices/availability/promotions,
  delivery/payment info, own orders and human escalation. No ERP/admin tools.
- [x] Background processing, durable retries without duplicate provider runs,
  charging after delivery, cache-aware usage, USD/MMK + markup snapshots
  (default 5%), platform USD ledger and no concurrent business overspend.
- [x] Handoff states: ai, waiting_owner, human. Customer requests a person; staff
  takes over or returns to AI; a new human reply takes over. Pending/stale AI
  cannot publish after handoff, disabling or configuration changes.
- [x] Business desktop Shop settings, wallet/top-up/history and chat handoff.
- [x] Mobile equivalent using shared controls/cascade, dedicated form routes,
  endless lists, page-refresh shell and retained navigation/drafts.
- [x] Public Shop AI response labels, handoff/status and retained existing chat,
  products/images, unread counts, realtime, receipts and human availability.
- [x] Meaningful API/agent/billing/race/permission tests; app checks/builds;
  isolated browser scenarios and screenshots; deployment/worker documentation.

## Agreed billing policy

Business credit is in MMK. Successful delivered responses deduct cache-aware
provider USD cost × snapshotted USD/MMK rate × (1 + platform markup / 100).
Default markup is 5%. Top-ups credit their approved face value, with no markup.
Sales AI starts a response whenever the business wallet has a positive balance;
there is no upfront maximum-token credit reserve. Each delivered response deducts
actual cache-aware usage, capped at the wallet's remaining balance. If the last
response costs more than the remaining credit, it is still delivered and the
wallet reaches exactly zero. The usage ledger records the full calculated charge
and unbilled difference in pricing metadata; the platform USD ledger always
records the full provider expense. At zero, new calls stop until credit is added.
Business runs remain serialized using both the cache lock and a persisted
processing-run check, so queued requests cannot all spend the final balance.
Current assistant per-user weekly quotas are independent of Sales AI wallets.
Only manual Office approval credits a submitted top-up. Corrections use new
ledger entries, not edits to past usage. Human chat continues when AI is paused.
Provider expense remains recorded if a generated response is suppressed after
handoff; the business is not charged for an undelivered response.

No live paid AI calls or real payment approvals are authorized as test fixtures;
verification uses SDK/HTTP fakes and intercepted browser APIs.

## Worker and deployment

Use `GET /api/health/workers` to monitor the dedicated worker and minute
scheduler. See [worker health setup](worker-health.md) for heartbeat installation,
status codes and authenticated Office diagnostics.

Run migrations before deploying the new clients. Sales AI always uses the
dedicated `sales-ai` database queue, even if the application's default queue is
`sync`. The jobs-table migration creates the standard shared table if missing.

Run a supervised worker from `laravel/`:

```sh
php artisan queue:work sales-ai --queue=sales-ai --sleep=2 --timeout=360 --tries=60
```

On Windows, `start-sales-ai.cmd` starts this worker in the background and avoids
starting another copy when it is already running. Both `start-mkpos-dev.cmd` and
`start-mkpos-prod.cmd` include it. Check it without starting work with
`powershell -File scripts/start-sales-ai-worker.ps1 -CheckOnly` from the repository
root. Output is in `artifacts/sales-ai-worker-service.log` and errors are in
`artifacts/sales-ai-worker-service-error.log`. The launcher preserves the caller's
working directory. `scripts/run-sales-ai.ps1` remains a foreground alternative;
use one launch method per installation. A server deployment still requires a
process supervisor that restarts workers after an exit or machine reboot.

Restart workers after deployment using `php artisan queue:restart`. The worker
timeout is shorter than the connection's 600-second retry interval. It never
retries a processing provider run: the persisted claim is terminal on interruption.
Retries while another business run holds the lock make no provider call.

Keep the Laravel minute scheduler running (`php artisan schedule:run` every
minute, or `php artisan schedule:work` locally). `sales-ai:process --limit=10`
recovers undelivered queue dispatches and releases interrupted holds older than
10 minutes. It can call the provider for pending runs; do not execute it against
live pending work merely as a smoke test. Do not retry an unknown provider result
as a new automatic run. The human conversation remains available for follow-up.

DeepSeek requests retry DNS resolution, TCP connection and TLS handshake failures
at most twice (cURL codes 6, 7 and 35). Retries preserve the individual HTTP
request and do not replay the agent's tool loop. Timeouts, interrupted responses,
certificate failures and HTTP responses are left to existing error handling.
TLS certificate verification remains enabled. Failure logs contain exception
class and connection error code, without credentials or conversation content.

Realtime publishing allows three seconds to connect and five seconds overall
to the configured socket server. Handling changes reuse the last message ID
to trigger authenticated refreshes under the existing socket protocol. An empty
thread has no message event to publish. Browser and publisher addresses must
reach the same socket instance; a healthy unrelated localhost server is not a
substitute for the configured public server.

Use a shared atomic-lock cache on deployments with multiple application hosts
(for example, Redis). The default file cache is suitable for one host. Business
settings remain disabled by default. Enabling requires configured platform AI,
official provider pricing, positive USD/MMK rate, sufficient platform USD funds
and sufficient available business MMK funds. Never expose provider credentials
in owner or public responses. Payment proof stays on private storage.

## Client parity inventory

Desktop and mobile owners can configure enabled/name/instructions/soul and 20
tool permissions, publish sales policies, inspect available/reserved MMK credit, browse balance
entries and top-up requests, submit a proof with account/amount/reference/note,
view private proof and cancel a pending request. Mobile forms have dedicated
routes; histories use endless scroll. Failed submissions retain drafts and keys.
Office has global searchable/status-filtered/paginated top-ups. Business detail
shows Sales AI balance in the summary cards and top-ups in the same billing history
table as subscription periods. Its review modal requires a reference
for approval and a reason for rejection, and reconciles its mounted source list.
All existing customer and owner chat surfaces retain their messages and composer
while exposing AI labels and human/AI handling controls.

Billing history provides a **View details** row action for subscriptions and
Sales AI top-ups. Its read-only modal loads private payment proof on demand;
the table has no proof column. Subscription approvals now persist the associated
period using migration `2026_10_25_000018_link_subscription_payment_proofs.php`.
Historical unlinked proofs are shown only when the business has one period for
that plan; ambiguous older period matches are not inferred.

Payment references are checked against both `sales_ai_topups` and
`subscription_requests` before submission (when supplied) and again before
approval. The shared checker includes legacy raw references without hashes,
provider aliases and the approved `payment_receipt_claims` ledger. Reusing a
subscription payment for AI credit, or AI credit payment for a subscription,
returns a validation error without changing the wallet or subscription. Final
approval retains a unique atomic claim to prevent duplicate credit under retries
or concurrent approvals. No new payment table or data migration is required for
this check; the existing receipt-claims migration must be installed.

## Verification results

- Backend: `tests/Feature/SalesAiTest.php` covers actual SDK HTTP fixtures,
  cached-token pricing, rate snapshots, free grants, holds, human takeover,
  permission rechecks, private proofs and manual review. Existing ShopChat tests
  run alongside it. No live provider calls are used.
- UI: focused Sales AI tests in frontend/mobile/office cover draft retention,
  proof previews, retry keys, handoff, manual review and source reconciliation.
- `node scripts/verify-sales-ai.cjs` intercepts all API requests in isolated browser
  contexts and checks the actual desktop/mobile/Office shells. Screenshots are
  written to `artifacts/sales-ai-ui`. Desktop, mobile, Office and public customer chat scenarios pass, including retained drafts and source-page return without a new initial fetch.


Evidence from the current workspace:

| Requirement | Implementation / verification |
| --- | --- |
| Business settings and wallet | `SalesAi/Settings.php`, `Wallet.php`, owner APIs; default-disabled, permission/version, grant-once and old-business tests |
| Cache-aware MMK charges | `Pricing.php`, `Runner.php`; real SDK HTTP fixtures, fixed expected 1.517040 MMK charge, rate snapshot and exactly-once ledger tests |
| Queue and recovery | Dedicated database connection and job; `SalesAiQueueTest` serializes, pops and executes one actual database-queue job against a mocked runner |
| Handoff and stale replies | Thread/settings versions and shared business/thread locks; takeover during provider callback, disable, stale tools, owner send and failed-provider tests |
| Public tool privacy | Existing public catalog query/view; tests exclude unpublished products, cost/private inventory, inactive accounts and another customer’s orders |
| Manual top-ups | Pending submission, private evidence, cancel/reject, review version, unique receipt claim and idempotent credit tests |
| Office and business detail | Shared actions/pagination/filter drawer; business balance card and combined subscription/top-up billing table; browser reviews a fixture and returns to the original reconciled global table without another list read |
| Owner desktop and mobile | Configuration, balance book, top-ups and proof preview; retry keys, account selection/copy feedback and history append/dedup/failure tests |
| Public and owner chat | Explicit AI labels; draft-preserving human handoff tests and public customer chat browser screenshot |
| Business deletion | Waits for processing runs; removes Sales AI tenant rows/private proofs; queued work cannot run after deletion. Global provider expenses and receipt claims remain recorded. |

- Backend combined suite: **53 passed, 944 assertions** (`artifacts/sales-ai-backend-final.log`).
- Desktop focused UI: **10 passed**; mobile Sales AI/chat: **12 passed**; Office Sales AI/navigation: **7 passed**. Public Sales AI handoff test passes.
- Full public Shop suite: **192 passed**; the newly added Sales AI handoff test was also run separately.
- Full mobile suite: **759 passed, 1 failed**. The unrelated `src/ai/BusinessActions.test.jsx` PIN confirmation test cannot find `Admin PIN` at line 136. Sales AI does not use that action-confirmation component. This outstanding suite failure is not reported as a pass.
- Mobile lint and lint for the changed desktop/Office components pass. Office-wide lint also reports an existing requests-filter effect in `src/main.jsx` (the synchronous `setFilterDraft` effect); it is outside the Sales AI changes. Shop does not define a lint command.
- Desktop web/native-renderer, mobile, Office and public Shop production builds pass; existing bundle-size warnings remain.
- Shared theme audit passes (all application sources, PHP/JS palette parity and seven boundary-color contrast cases).
- All three Sales AI migrations are recorded as applied locally.
- Local dedicated worker and recovery loop are running; their process IDs are in `artifacts/sales-ai-services.json`, with separate output/error logs. Startup verified zero pending runs and zero enabled businesses; no live paid provider test was performed.

The local recovery loop can be run with `scripts/run-sales-ai-recovery.ps1` instead
of the full application scheduler. Production should supervise the worker and
use the existing Laravel minute scheduler. Local processes are development
services; they do not install an operating-system startup service.


## Sales AI top-up receipt validation

Business-owner top-up submission now runs the existing System Agent receipt extractor and subscription matching rules before creating a pending request. Checks compare the requested MMK amount, selected recipient name and visible account suffix, currency, provider, completed status and transaction reference. The extracted reference is saved automatically; an optional entered reference must match. Cross-flow subscription/top-up duplicates are blocked again under the shared payment lock before saving.

The check is bound to owner, business, top-up amount, selected account details and image hash. Changed account details invalidate an in-flight check. Successful submission retries reuse the existing request without another paid check. Failed validation keeps the owner's draft and preview; temporary uploaded files are removed on failure. Validation costs use the platform system AI balance book. This does not change manual Office approval or credit a business wallet automatically.

Office top-up review includes the saved check results with the private proof and extracted reference. Legacy requests without validation reports remain manually reviewable. Schema change: `2026_10_25_000019_add_sales_ai_topup_validation.php` adds a nullable JSON report; migration applied locally.

Verification: receipt/top-up backend tests, focused owner desktop/mobile and Office form tests, three production builds, mobile lint and changed-component lint passed. Intercepted desktop/mobile/Office browser fixtures passed and screenshots were inspected. No live AI payment or provider call was used for verification.

Final backend regression run: 61 passed, 584 assertions. Focused UI: desktop 3, mobile 5 and Office 4 passed. Full mobile run: 761 passed, 2 failed in unrelated `ShopLocation` and `ShopReviews` tests (see `artifacts/sales-ai-topup-validation-mobile-full.log`); not counted as a passing suite.
Both unrelated failing mobile files passed when rerun together (10 tests); see `artifacts/sales-ai-topup-validation-mobile-recheck.log`. The initial full-suite result remains recorded above.

## Expanded Sales Representative tools

There are 21 tools: 20 owner-configurable tools and mandatory `request_human`.
The 17 additions complete the customer sales journey. This agent is separate
from the owner's Wyne Wyne management assistant and cannot use its ERP tools.

| Tool | Behavior |
| --- | --- |
| `search_products` | Existing public catalog search |
| `shop_information` | Existing public shop/contact information |
| `customer_orders` | Existing orders for this conversation's customer |
| `request_human` | Existing human handoff, always available |
| `get_product_details` | Published product facts, photos, current price/units/availability |
| `compare_products` | Up to five verified products in a comparison table |
| `recommend_products` | Catalog matches for requirements/category/budget; paginated candidates |
| `find_product_from_image` | Search candidates using visible text from the current attached image; customer confirms identity |
| `get_sales_policies` | Only owner-published delivery, payment, cancellation, return and warranty terms |
| `calculate_quote` | Native current quote with explicit delivery/payment choices, coupons and promotions |
| `manage_customer_cart` | Read or prepare a complete replacement/clear; customer presses Apply cart |
| `get_delivery_options` | Real zone/method IDs and pickup instructions; external courier fee/ETA may be unknown |
| `prepare_checkout` | Customer contact/fulfillment/payment form, fresh server quote, then Confirm order |
| `submit_confirmed_order` | Read prepared checkout status; model arguments cannot place an order |
| `submit_order_payment_proof` | Review an image and submit proof against an owned shop order; funds remain unverified |
| `get_customer_order_details` | Owned order items, fulfillment, payment and customer-visible requests |
| `request_order_change` | Exact requested address/phone/note/quantities for human arrangement; no snapshot edits |
| `request_cancellation` | Native cancellation rules; unpaid pending orders can cancel immediately |
| `request_return` | Pending return request with optional reviewed photo; no automatic refund |
| `get_customer_request_status` | Request status and shop response |
| `prepare_buy_again` | Current-price cart proposal; unavailable/changed-unit items skipped and low stock reduced |

### Setup and approval boundary

- Run migrations `2026_10_25_000018_expand_sales_representative_tools.php` and
  `2026_10_25_000020_add_sales_ai_product_cards.php`, deploy all three clients,
  and restart the Sales AI worker. Both migrations are applied locally.
- Existing businesses keep their saved tool permissions. The owner explicitly
  enables additions in My Shop > Sales AI > Configuration and publishes policies.
  Empty policies require human clarification. New settings remain disabled.
- Tools can prepare proposals only during an authenticated, active response run.
  Proposals become accessible only with a delivered AI reply. Suppressed/failed
  responses cancel unpublished proposals. Forms expire after 30 minutes.
- Customer-session endpoints bind business, conversation and customer identity;
  owner tokens cannot approve a customer's proposal. Review revisions/HMACs,
  current permissions, handling versions, cart versions and native quote hashes
  prevent changed/stale proposals from committing. Retries return saved results.
- Chat carts are separate from browser shopping carts. Applying a chat cart
  reserves no stock. Confirm order creates a native shop order/reservation;
  it does not create a POS sale. Purchased quantities are removed from the chat
  cart while unordered items remain.
- Payment proof is for the shop order, separate from MKPOS subscriptions and
  Sales AI top-ups. Shop staff still verifies funds. Returns, refunds, goods
  receipt and exceptions follow existing controlled owner workflows.
- Order changes are requests. Owners arrange changes/replacement checkout and
  mark resolved or rejected with a note. Resolution never edits original order
  quantities, prices, stock or delivery snapshots automatically.
- Private proof files are hash-bound to the reviewed proposal and deleted after
  completion/cancellation/expiry. `sales-ai:prune-actions` runs hourly through
  the Laravel scheduler; business deletion also removes staged proof files.
- Up to eight model steps with 1,200 output tokens per step are allowed. Actual
  delivered usage remains cache-priced, without a maximum-token wallet reserve.
  Any positive balance permits a reply; charging stops at zero. An empty wallet
  hands the chat to a person. Old in-flight holds remain releasable for deployment
  compatibility, but new responses never create holds.

### Verification and button record

`SalesRepresentativeToolsTest` passes 15 tests/288 assertions, including a real
SDK tool-call loop with mocked HTTP, persisted product readouts/forms, private
identity, stale prices/permissions/cart versions, idempotency, partial-cart
checkout, return evidence and native owner resolution. No live AI or payment
calls are used. The existing Sales AI/queue/chat regression group passes
47 tests/864 assertions. Broader native-order regression: 173 passed, 2 failed;
failures are an exact realtime HTTP-count assertion (5 vs 3) and global marketplace
search fixture isolation (11 vs 1). They are not reported as passing checks.

Focused UI suites: public shop 17 passed, desktop 6 passed, mobile 26 passed.
Changed-component lint has no errors; existing ShopUpdates hook/export warnings
remain. Desktop, mobile and public-shop Laravel builds pass with bundle-size
warnings. Browser check: `node scripts/verify-sales-representative.cjs` from the
repository root; all API writes are intercepted. It checks 393px and 1440px,
long Myanmar product names, local table overflow, draft retention and checkout.
Screenshots: `artifacts/sales-representative-ui`. Existing intercepted desktop
and mobile shell checks also pass with policy draft retention across the history
tab / app-bar Back. Policy controls have explicit accessible names after editing.
The local Sales AI worker was restarted with the new code; no pending/processing
runs existed at restart, and no paid provider smoke test was performed.

| Control | Handler / expected result | Evidence |
| --- | --- | --- |
| Review / Close review | Open authenticated proposal; close without saving; retain edits | Component tests + browser, no second initial proposal fetch |
| Product link / browser Back | Native product route and same chat/review/draft on return | Browser at both widths; no new initial proposal read |
| Quantity / Remove / delivery/payment/contact fields | Stage edits, clear consent, require fresh quote | Component tests + checkout browser |
| Calculate and review quote | Server quote, preserve values on validation failure | Component tests + browser failure/retry |
| Consent / Confirm order | Exact reviewed revision, one authenticated POST | Component tests + browser; backend creates one order |
| Cancel proposal | Cancel draft without cart/order write | Component + backend tests |
| Check action status / Retry status | Recover saved result after lost confirmation response | Component tests |
| Review image / Submit payment proof | Stage private image first, then customer confirmation; pending funds | Component + backend tests |
| Refresh review / Submit change/cancel/return request | Fresh edited reason and native request path | Component + backend tests |
| View order | Native tracking URL uses returned reference | Component + browser URL assertions; destination covered by native order tests |
| Owner policies / Save | Include published terms in versioned settings save | Desktop/mobile component tests |
| Mark resolved / Decline | Resolve/reject human change request, no payment approval | Mobile component + backend tests |

Physical device Back, live courier/payment integrations and live provider image
recognition were not exercised. Image matching is candidate text search, not a
dedicated visual similarity engine. Native order destinations are reused.

## Responsive form and table audit — 2026-10-26

Scope: public Sales AI cart, checkout, proof, change, cancellation and return
review cards; product comparisons and quotation tables. Owner configuration and
unrelated business-management forms are outside this audit.

`SalesTable.jsx` supplies a bounded, independently scrollable region with a
visible overflow hint and keyboard focus only when needed. Column minimums keep
product names readable and numeric quantities/prices/amounts on one line. The
last Actions column has a visible heading. The wrapper is positioned so hidden
accessible content cannot extend the conversation's horizontal scroll area.
Forms retain one column in narrow cards and two in wider cards; narrow headings
place the review button on a separate row instead of breaking words. Inputs and
buttons retain 44px minimum targets. Long Myanmar text wraps within the card;
image previews preserve their aspect ratio. Existing review/approval logic is
unchanged.

Verification commands (from repository root):

- `node scripts/verify-sales-ai-responsive.cjs`: all six forms at 320, 360, 393,
  768, 1024 and 1440px with long Myanmar names/addresses, decimal quantities and
  large amounts. Checks every table's column bounds, final-column reachability,
  keyboard horizontal scrolling, no card/chat/page overflow, field column count,
  image layout, close/reopen draft retention and a short 393×480 checkout viewport
  with confirmation reachable above the composer. API writes are intercepted;
  confirmations are explicitly blocked in this layout fixture.
- `node scripts/verify-sales-representative.cjs`: quote validation/retry, exact
  customer confirmation, product-page browser Back and unchanged form/chat
  drafts at 393/1440px. No new initial proposal fetch on close/reopen.
- Existing public SalesActionCard/SalesAiChat/ShopChatProducts suites: 17 passed.
  Changed-component lint and public-shop Laravel build pass; the existing large
  bundle warning remains.

Screenshots inspected under `artifacts/sales-ai-responsive/`, including mobile
table right edges, payment image, wider two-column checkout and comparison/quote
tables. Button paths retain the existing completion record above: Review/Close,
field edits, quote retry, consent/Confirm, Cancel and status recovery are covered
by browser/component tests. This audit uses Chromium viewport emulation; physical
touch devices, virtual-keyboard behavior and hardware Back are not verified.

## Conversational form outcomes — 2026-10-26

Successful review, customer confirmation and proposal cancellation now record a
server-authored Form activity message and queue a genuine Sales AI follow-up.
Valid current submissions that fail with 409/422 also record an error outcome;
forged/stale previews and unauthorized requests do not trigger provider work.
Show/status reads, closing a card and editing fields locally do not create replies.

The action and its successful activity event commit atomically. Deterministic
event keys and the existing unique run trigger prevent duplicate replies/charges
on network retries. A follow-up receives the recorded result, not client claims.
It can read owned order/request status or request human help; mutation/preparation
tools are blocked. It distinguishes a preview from an order, proposal cancellation
from order cancellation, and proof submission from verified funds. The latest
actual customer text supplies the language context; English activity labels cannot
change the customer's reply language.

Older pending/in-flight preview/error replies are suppressed after a newer result
or expiry. This suppression does not hand the conversation to a person. Ordinary
human takeover, entitlement, tool permissions, wallet funding and provider failure
rules remain active; unavailable AI leaves the saved outcome visible and uses the
existing handoff behavior. Generated follow-ups use normal Sales AI wallet billing.

The client fetches incremental messages immediately after the action, displays AI
preparing while queued, and appends its reply through realtime/polling. Form edits,
chat drafts and history remain mounted. The customer activity is explicitly labeled
Form activity rather than presented as customer-written text.

Deployment: apply `2026_10_26_000001_add_sales_ai_action_events.php` (nullable JSON
on existing chat messages), deploy the public shop and restart the Sales AI worker.
Migration is applied locally. Verification: 70 backend tests/1,300 assertions and
18 focused UI tests pass; the added proof-result assertions run in the final
focused recheck. Lint and public-shop Laravel build pass. Intercepted 393/1440px
browser fixtures verify immediate activity, AI preparing, eventual result response,
one confirmation POST, retained drafts and product Back. Screenshots:
`artifacts/sales-representative-ui/action-follow-up-{393,1440}.png`. Provider calls
are mocked; physical device Back/keyboard are not tested.

## Mobile owner chat: one header — 2026-10-26

The conversation page hides the MKPOS assistant and shop-chat shortcut icons via
local `AppBarMenu` options. It removes the separate Sales AI/Take over strip and
puts the shared 44px AI switch inside the existing page app bar. AI on means AI
replies; off takes over as human. The native versioned handoff endpoint is reused.
Only staff with order-processing permission can switch; other staff see a
read-only mode. Busy requests disable the switch, failures keep the server's
previous state and expose the error, and retry preserves the message draft.

Verification: 36 ShopChat/AppShell tests, changed-file lint and mobile Laravel
build pass (existing bundle warning). `scripts/verify-mobile-chat-header.cjs`
intercepts all API traffic and checks 320/393/768px in the actual mobile shell:
exactly one header, no shortcut icons or status strip, switch in the header,
keyboard activation, failed-request retry, both handoff directions, app-menu
open/Escape, app-bar Back to the retained inbox and browser Forward to the same
conversation/draft without another initial message read. Screenshots inspected
in `artifacts/mobile-chat-header/`. View customer/Back/send/share-image/product
paths retain their component coverage; physical hardware Back is unverified.

## Owner assistant Sales AI help correction — 2026-10-26

Wyne Wyne previously had a first-sale POS guide but no Sales AI guide or approved
Sales AI destination. A question such as “Sale AI ကို ဘယ်လိုသုံးရမလဲ” could therefore
produce Sell/cart/checkout instructions and Open Sell. `MkposAssistant` now
explicitly distinguishes Sale AI/Sales AI (the shop customer-chat representative),
POS Sell and the owner assistant. It directs this topic to the new `sales_ai`
feature guide and corrects prior mistaken Sell explanations.

The guide covers owner configuration, published catalog, tools/policies, customer
chat and confirmed forms, payment/refund authority, human handover and separate
MMK credit. Approved owner-only destinations `sales_ai` and `sales_ai_topups` are
shared across PHP/desktop/mobile catalogs. Desktop consumes the section target
without adding an extra Back entry; mobile uses its existing native routes.

Verification: 7 onboarding/access/catalog tests (268 assertions), 15 desktop and
14 mobile focused tests pass; desktop lint and both production builds pass.
`scripts/verify-sales-ai-help-links.cjs` verifies that the new configuration button
opens the actual desktop/mobile configuration and Back retains the assistant's
draft/history without another initial message read. Screenshots inspected under
`artifacts/sales-ai-help`. These browser replies are fixtures, not live paid model
calls; physical Back remains unverified. No business settings are modified by help
or page-link tools.

## Wyne Wyne manages the owner's Sales AI — 2026-10-26

Seven owner-only in-app tools are added to the existing management registry:

| Tool | Capability |
| --- | --- |
| `get_sales_ai_settings` | Current enabled/name/instructions/soul, permissions, policies, version, MMK wallet and safe platform/shop readiness flags |
| `get_sales_ai_activity` | Bounded own wallet entries, top-ups, conversation UUID/mode/version and response runs; `usage` returns native 30-day/12-month spending in business timezone |
| `manage_sales_ai_settings` | Review partial enabled/name/instructions/soul changes |
| `manage_sales_ai_tools` | Review enable/disable lists, preserving unspecified tools; mandatory human handoff cannot be disabled |
| `manage_sales_ai_policies` | Review partial published policy changes; empty text unpublishes one policy |
| `manage_sales_ai_handover` | Review AI/human handling for one owned conversation or an explicitly requested all-conversation scope |
| `manage_sales_ai_topups` | Review cancellation of one pending owned top-up; no credit/refund/Office approval |

Mutations reuse `AiBusinessActions`: authenticated owner/conversation/message,
immutable before/after preview, full reviewed-row count, preview hash, expiry,
current role/subscription checks, business locking, exact baseline recheck,
idempotent confirmation and management audit. Model tools only prepare proposals;
only the existing authenticated owner app endpoint confirms. Settings use the
same validation rules as the native Sales AI form. Settings-version changes make
older pending Sales AI responses/customer proposals stale, disclosed in review.

Handover calls the native versioned chat service. The preview identifies every
affected conversation; a changed mode/version/settings or newly added conversation
invalidates a bulk proposal. Returning to AI may process an unanswered customer
message under ordinary Sales AI billing. Top-up cancellation reuses the native
pending/version guard and cannot cancel a request already reviewed by Office.
Wallet reads do not initialize wallets or grant promotional credit. Read results
exclude provider credentials, private proof paths and customer access tokens.
Wallet credit, provider rates, security/staff access and Office approvals remain
outside these tools. New top-ups use the native screenshot-validation page.

Both owner clients render the five mutation types in existing compact review
cards. Saved configuration/top-up results reconcile the native Sales AI view;
unsaved fields are retained while unchanged fields/version adopt server changes.
Approval, cancellation and retries preserve the assistant draft. No new database
migration is required beyond existing Sales AI, policy and management-audit setup.

Verification: the 66-test backend regression group passed; final focused Sales AI
owner management recheck passed 7 tests/158 assertions, including schema/registration,
read-only queries, partial preservation, exact confirmation/idempotency/audit,
stale app settings/handover/Office top-ups, tenant/role isolation and excluded
wallet/security fields. Desktop/mobile focused suites each pass 16 tests, including
the new review card and native view reconciliation. Changed-file lint and both
Laravel production builds pass with existing bundle warnings.

`php scripts/verify-wyne-sales-ai.php` from the repository root confirms all seven
tools are registered locally without writes/provider calls.
`node scripts/verify-wyne-sales-ai-tools.cjs` intercepts every API call and verifies
all five review forms on desktop/mobile: consent required, four confirmations and
one cancellation, exact preview payload, one POST and retained chat draft. Inspected
screenshots are in `artifacts/wyne-sales-ai-tools`. No live AI/payment calls or
physical-device Back checks were performed. Existing Back behavior/component
coverage is retained; these tools introduce no new navigation destination.


## Handover and configured identity

Both individual and bulk handover now change handling mode for future messages only. They do not enqueue or replay the latest historical customer message. Existing queued work from earlier handling versions remains subject to the runner version guard. A newly submitted customer message in AI mode follows the normal enqueue path; retrying an existing message key does not create AI work.

## Business Telegram Sales AI

Owners configure a dedicated shop bot in **My Shop → Sales AI → Telegram bot** on desktop or mobile. Save its BotFather token, enable replies, test the connection, then register the displayed server-generated HTTPS webhook. `APP_URL` must point to the public Laravel entry point (including `/public` if the hosting layout requires it). Each business gets `/integrations/telegram/sales-ai/{connection-uuid}`. This bot must be separate from Kaung Kaung and other businesses. Tokens and webhook secrets are encrypted and never returned to the browser.

Enable group replies, add the bot to each Telegram group, and approve each group in the same screen. Mentions/replies and independent daily/per-user limits are configurable per group. Group joining must be enabled in BotFather. Telegram privacy settings may require bot administrator status or privacy disabled followed by re-adding the bot for ordinary mentions. Groups expose published shop information only, with separate histories for each group participant and topic; checkout and account orders require private chat.

Private customers can select exact products and quantities, tap **Continue checkout**, enter their own contact/fulfillment/payment details, review the native server quote, then press **Confirm order**. This supports the existing guest-order path without registration. Chat text or model tool calls cannot submit an order. Native stock, active payment methods, coupon, delivery, reservation, blocking and idempotency rules apply. Changed prices require a new review and confirmation. Tracking credentials remain encrypted on the server; guest order access is scoped to the originating Telegram customer. Payment is still subject to the shop's verification.

Signed-in Shop customers can optionally choose **Link my Telegram account** in their web conversation, then open the one-use ten-minute Telegram link. This grants private read access to their own account orders for that business. `/unlink` removes account access and clears previous model context; `/reset` clears conversation context; `/cancel` abandons checkout. Neither command cancels already submitted orders. Account-owned orders stop being accessible after unlinking, including those created through Telegram.

Telegram replies use the existing Sales AI wallet, tool permissions and `sales-ai` queue. The existing Sales AI worker sends replies and typing indicators; `sales-ai:process --limit=10` also drains pending Telegram deliveries when using the existing cron-only scheduler. No additional queue or Node worker is required. Cron-only deployments retain their existing scheduling delay. Ambiguous outbound deliveries are not automatically repeated; explicit Telegram rate-limit responses are retried. Owner text replies from Shop Chat are delivered to the matching Telegram conversation. This initial business Telegram transport supports text messages; photo/payment-proof uploads and order changes continue through the secure Shop app or a person.

Deploy this migration specifically (do not run unrelated pending balance migrations as part of this feature):

```sh
php artisan migrate --path=database/migrations/2026_10_27_000004_create_sales_ai_telegram.php --force
php artisan queue:restart
```

The established scheduler remains `php artisan schedule:run` every minute. For persistent hosting, the established Sales AI worker remains `php artisan queue:work sales-ai --queue=sales-ai --sleep=1 --tries=1 --timeout=600`. Follow the existing deployment's connection and worker supervision settings.

The agent prompt explicitly identifies the current owner-configured assistant name, explains instructions and personality, and supplies owner-published sales policies. Public assistant identity takes precedence over names in old history. Human owner replies in history are marked as human context rather than unlabelled AI replies. Tool permissions and publication/privacy boundaries remain enforced.
