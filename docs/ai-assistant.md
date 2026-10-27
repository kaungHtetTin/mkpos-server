# MKPOS in-app AI assistant

## Shared agent awareness

`App\Ai\Skills\AgentAwareness` supplies one public role directory to Wyne Wyne, each business's Sales AI, Kaung Kaung and the internal image/payment/theme helpers. SalesImageRepresentative inherits it from SalesRepresentative. Agents can explain who handles business operations, shop purchases and private orders, public MKPOS help, or manual Office review. Each keeps its own identity, including the shop owner's configured Sales AI name, and replies in the user's language.

This skill adds no tools, shared memory, account linking, messaging, delegation or permissions. Referrals are guidance unless an existing authorized tool confirms an actual handoff/report. Agents must use current verified links/settings, preserve owner/staff and customer/tenant boundaries, and never claim another agent received private history or files. Internal helpers retain their bounded task and structured output. Existing payment matching, manual approval and customer/owner confirmation rules still apply. No migration or frontend build is required; deploy the PHP changes and restart long-running PHP queue workers to load the new prompts. Cron-only commands load them on their next invocation.

## Direct HTTP replies

Wyne Wyne validates and saves a message, runs the provider immediately in the
same HTTP request, then returns HTTP 200 with the completed user/assistant
exchange, proposals, billing forms and weekly usage. It needs no queue worker
or minute cron job to start a reply. Provider and tool execution time still applies.
Only one reply per user can run at a time, including across conversations.

Desktop, Windows and mobile wait for this response (390-second client timeout).
The PHP request allows up to 360 seconds, with existing 40-second provider-call
limits. Configure the web server, PHP-FPM and reverse proxy request timeouts to
allow at least 400 seconds; hosting hard limits cannot be overridden in PHP.
Use concurrent PHP request handling so unrelated requests can proceed.

The existing message-tracking migration is still required on new installations:

```sh
php artisan migrate --path=database/migrations/2026_10_26_000004_queue_wyne_wyne_replies.php --force
```

Duplicate client IDs return the saved exchange without another provider call or
charge. Failed replies return HTTP 503 and require an explicit user retry.
Delivery GET requests are retained to reconcile saved work after a lost HTTP
connection or reload; normal sends do not poll for a reply. A disconnected
client does not prevent the server from saving a completed response.

The runner preserves tenant scope, role/subscription checks before generation
and publication, private attachments, owner approvals and once-only accounting.
Existing message history, image/CSV uploads, proposals, billing and navigation
are unchanged. Actual reported provider usage is recorded even when revoked
access prevents publication.

Remove any `cron:work wyne-wyne` cron entry and stop the old Wyne Wyne worker
or Supervisor program when deploying. Keep Sales AI, Telegram and the regular
scheduler running. The Windows combined launchers no longer start Wyne Wyne;
its old standalone launcher now explains that no worker is required. Old queued
jobs are retired without model execution and their saved messages can be retried.
The minute `wyne-wyne:recover` task only marks abandoned requests older than ten
minutes failed; it never dispatches jobs or replays a provider call.

Worker health reports `wyne_wyne.worker_required: false`, `mode: http`.
A missing Wyne Wyne worker does not make health fail. Existing release archives
must be regenerated to include this change; uploading current PHP source changes
the server flow, while rebuilt clients remove the old short response timeout.

## Conversation privacy after role changes

Owner conversations require current owner access to the same business. If an
owner becomes staff, those conversations disappear from history, and direct
message, image, delivery-receipt, send and retry requests return 404. Their
contents are excluded from subsequent model context. The server checks the
stored account role, including when an authenticated user object still has its
old role. A role change during generation suppresses delivery of the private
reply while retaining accounting for provider work already performed.

Staff can create fresh help conversations and continue their safe history.
Once an owner sends a message in a staff conversation, it becomes owner-only
before content is saved or sent to the model. Clients cannot downgrade this
classification. Unscoped history passed directly to an agent is discarded for
staff because its authorization cannot be established.

Apply the additive migration when deploying this fix:

```sh
php artisan migrate --path=database/migrations/2026_10_26_000002_protect_ai_conversation_history.php --force
```

All pre-migration conversations default to owner-only, including old staff help
threads: their full historical sensitivity cannot be proven from tool logs.
Messages and files are retained. Before the migration runs, staff history access
fails closed and creating staff conversations returns a setup error; owner access
continues. This change cannot erase information already downloaded by a client or
already sent to the provider before access was revoked.

## Sales AI platform markup setting

Office → Settings → AI assistant includes **Platform markup (%)**, alongside the
USD-to-MMK rate. `sales_ai_markup_percent` defaults to **5.00%** for the existing
settings row and new installations. Super admins may set 0–1000%, with up to two
decimal places. Omission preserves the stored value; blank/null is rejected, and
zero explicitly disables markup. Changes use the existing settings audit log.

The planned Sales AI charge is provider USD usage cost × the saved USD/MMK rate ×
(1 + markup/100), so the default multiplier is 1.05. The setting is saved for the
planned per-business Sales AI wallet; it does not alter current assistant charges,
platform USD balances, or top-up amounts. Actual Sales AI billing and per-request
rate/markup snapshots belong to the Sales AI implementation. Migration
`2026_10_25_000014_add_sales_ai_markup` adds the defaulted decimal column.

## USD to MMK setting

Super admins can set **USD to MMK exchange rate** under Office → Settings →
AI assistant. It means MMK per 1 USD and is manually maintained. The optional
`usd_to_mmk_rate` setting is stored as DECIMAL(15,6); positive values with up to
six decimal places are accepted. Blank clears it, and omitted API fields preserve
the saved value. Migration `2026_10_25_000013_add_ai_usd_to_mmk_rate` adds the
nullable column; no market rate is guessed or automatically fetched.

The value is available through `AiAssistantSettings::get()` and the Office settings
API for future conversions. It does not currently convert USD charges, pricing,
balances or reports. The existing Save assistant settings action persists it;
failed saves and settings tab navigation preserve the draft. Regular Office
administrators cannot modify it, and changes use the existing Office audit log.

## Weekly allowance avatar

Business desktop (dialog and full page) and mobile show a progress ring around
the assistant header avatar, with the remaining weekly request percentage.
The header also shows the next reset date in one line (for example, “Resets Mon,
Oct 26”) from `weekly_usage.resets_at`, formatted in Asia/Yangon rather than the
device timezone. The full instant remains available in the tooltip and accessible
label; midnight and the timezone are omitted from the visible caption. Missing or
invalid reset timestamps retain the normal assistant subtitle.
The ring continuously blends semantic success → warning → danger colors as the
remaining percentage falls from 100 → 50 → 0. The percentage remains readable
in the normal text color. Unknown usage does not display a guessed percentage;
small remaining allowances retain one decimal place. Reduced motion disables
ring transitions and screen readers receive the remaining/total count.

Authenticated config, message history and send responses include `weekly_usage`:
`limit`, `used`, `remaining`, `remaining_percent`, `resets_at`, and `timezone`.
`AiWeeklyQuota` supplies both this snapshot and server quota enforcement. Counts
belong to the current authenticated user, span conversations, include failed
provider attempts and reset Monday at midnight Asia/Yangon. Duplicate completed
messages do not spend another request. Quota updates arrive with message responses
and refresh independently every 30 seconds while the chat is active/visible, on
focus, reconnect and reopening. These refreshes retain drafts and message DOM;
they do not reload conversation history or call the AI provider. A failed refresh
keeps the last known value until a later successful refresh.

`node scripts/check-ai-weekly-credit.cjs` verifies both apps, desktop maximize,
mobile history, five color/percentage states and post-send updates with mocked
API writes. Screenshots and color samples are in `artifacts/ai-weekly-credit/`.

## Office API key management

Super admins can replace the DeepSeek API key in **Settings → AI assistant**.
The password field is always blank on load. **Test connection** tests the typed
candidate, or the current effective key when blank, without saving it. **Replace
key** saves explicitly and clears the field after success. Unsaved model and
limit edits remain intact. Blank settings saves leave the saved key unchanged.

Migration `2026_10_25_000012_add_encrypted_ai_api_key` adds encrypted storage.
The Office key takes precedence; `DEEPSEEK_API_KEY` remains the fallback when no
Office key exists. Laravel `APP_KEY` must remain stable to decrypt the stored key.
Unreadable ciphertext fails closed. Settings/config responses allowlist public
fields, audit entries exclude the key, and validation does not flash it. Provider
failure logs contain exception classes rather than raw provider exceptions.

Super-admin routes: `PUT /api/office/ai-assistant/api-key` accepts `api_key`;
`POST /api/office/ai-assistant/test-connection` accepts an optional `api_key` and
is limited to five calls per minute. The test uses the official
[GET /models endpoint](https://api-docs.deepseek.com/api/list-models/) and returns
a short sanitized result. It does not generate a completion or verify available
credit. Chat, owner payment validation, background payment review and theme
generation resolve the effective key before invoking the SDK, including when a
provider instance was previously cached. Verification uses synthetic credentials
and intercepted HTTP; no real credential was changed or sent during testing.

The initial implementation uses the official Laravel AI SDK (`laravel/ai` 1.x)
and DeepSeek directly from Laravel. PHP 8.3+ is required. OpenClaw and Telegram
are not used by this in-app flow. Business web offers a dialog with a maximized
page, and mobile uses a full-page chat view; customer/shop conversations remain
separate.

## Setup

Install the locked backend dependencies with `composer install`. Deploy this
feature's additive migration on an existing, up-to-date MKPOS installation:

```sh
php artisan migrate --path=database/migrations/2026_10_25_000001_create_ai_assistant_tables.php --force
```

This creates only `ai_assistant_settings`, `ai_conversations`, `ai_messages` and
`ai_runs`. It does not update products, financial books, subscriptions, existing
chat messages or bank balances. Follow the repository's financial-preservation
procedure separately if older Office migrations are also pending.

Set the credential privately on the Laravel server:

```dotenv
DEEPSEEK_API_KEY=your-private-key
MKPOS_AI_MODEL=deepseek-flash
MKPOS_AI_ENABLED=false
```

Never place the key in a VITE variable, app build, chat message or Office form.
After changing server environment values, rebuild the config cache with
`php artisan config:cache` if deployment uses cached configuration.
`MKPOS_AI_MODEL` and `MKPOS_AI_ENABLED` seed installation defaults; after the
migration, manage the saved configuration in **Office > Settings > AI assistant**
as a super administrator. Enable the assistant there after setting the key.
The default model name is configurable; verify available models for the account.
Official references: [Laravel AI SDK](https://laravel.com/framework/docs/12.x/ai-sdk),
[DeepSeek model IDs](https://api-docs.deepseek.com/quick_start/pricing/).

Office > AI > Usage Report estimates input, output and total USD cost using rates
fetched by Laravel from DeepSeek's official pricing page. The documented model
API has no pricing fields. The server validates the HTML table and caches peak
cache-hit input, cache-miss input and output USD rates per million tokens for
six hours. Refresh happens on
the next Office AI settings/report request after expiry, without a scheduler.
Fetches have a six-second timeout and a ten-minute retry backoff. On failure,
the last successful snapshot is retained for up to 30 days and marked stale;
without a snapshot, chart costs show Unavailable and new AI requests are denied.
There are no fixed fallback prices in the client or balance book.

Estimates apply the latest fetched rates to recorded tokens, including older
requests. Chart estimates are not historical invoices: they use current rates
and exclude off-peak discounts. Reported cache hits use the discounted input
rate; missing or historical cache counts use the normal uncached input rate.
Failed/incomplete runs and unsupported models show Unavailable.

## Internal AI balance book

Office > AI > Balance book replaces the former Request page. It is an internal
USD budget ledger, independent of DeepSeek payments and the Office Financial
book. Only super administrators may view it or record a top-up. The shared app
bar provides search and Top up; entries use the shared 10/25/50/100 pagination.
The top-up modal records an amount (up to six decimal places), description,
optional reference, actor and timestamp. It does not send a payment.

Deploy the additive migration after the initial AI tables:

```sh
php artisan migrate --path=database/migrations/2026_10_25_000002_create_ai_balance_book_tables.php --force
```

This creates only `ai_balance_accounts` and `ai_balance_entries`. The opening
balance is **USD 0**, so new AI usage is denied until a super administrator
records a top-up and the AI settings are enabled/configured. Existing requests
are not charged retroactively. Missing book storage fails closed.

Each newly completed run deducts its estimated input + output cost. Peak
cache-hit, cache-miss and output rates are captured before the call and saved on the
immutable usage entry. Subsequent pricing updates do not reprice these entries.
Money uses integer billionths of USD; each charge rounds up once to that unit.
Missing or unsupported model pricing blocks new calls. Failed provider calls
with unreported usage are not charged internally, although DeepSeek may bill
them. Chart estimates still use current rates and can differ from ledger totals.

The backend checks the singleton account under a database row lock when
admitting a new run. Zero or negative balance returns HTTP 402 across all
businesses and apps; existing history remains readable. Already-running replies
may finish and take the balance below zero. Top-ups must restore a positive
balance before new runs resume. Top-ups and deductions update the balance and
append entries atomically, with unique top-up keys and run IDs preventing double
credits/charges on retries. Completed exchange replay remains free at zero.
Charging and reply persistence share a transaction, so a failed save rolls both
back. Top-up and usage entries have no edit/delete endpoint. This budget gate
does not change provider credentials or the saved AI enabled setting.

### Cached input accounting

Apply this additive migration after the AI and balance-book migrations:

```sh
php artisan migrate --path=database/migrations/2026_10_25_000003_add_cached_input_tokens_to_ai_usage.php --force
```

It adds nullable `cached_input_tokens` to `ai_runs`, `ai_messages` and
`ai_balance_entries`. Existing amounts, balances and usage records are not
recalculated. Null means the cache count was unreported, rather than a confirmed
zero. The Laravel AI SDK maps DeepSeek's `prompt_cache_hit_tokens` to
`cacheReadInputTokens` and sums it across the tool loop. Input token totals
already include these cached tokens. Pricing and deductions use:

```
((input_tokens - cached_input_tokens) * uncached_input_rate
 + cached_input_tokens * cached_input_rate
 + output_tokens * output_rate) / 1,000,000
```

Unknown cache counts receive no cache discount. Partially reported tool loops
discount only the known hits. Invalid counts outside 0..input_tokens cannot
create a discount or a ledger charge. The new pricing-cache version fetches
cache-hit rates from the official page rather than reusing snapshots with only
uncached rates. Balance entries show cached/uncached input counts or identify
unreported usage. Charts include a Cached input tokens metric and apply the
same split to their cost estimates. Posted ledger amounts remain immutable.

Build and deploy the matching `frontend`, `mobile` and `office` clients. Each
supports `npm run build` and `npm run build:laravel` as documented in the main
README. This implementation does not require a new realtime or queue worker.

## User experience

Office > AI > Usage Report includes four charts: the last 24 hourly buckets,
30 days, 12 months, and five years. All buckets use UTC, include the current
partial period, and fill missing periods with zero. The Chart metric selector
switches between request counts, failures, input tokens, output tokens and total
tokens. Hovering or keyboard focusing a bar reveals its exact value. Charts
aggregate all recorded runs, independently of balance-book pagination.
The Estimated cost (USD) metric sums input and output costs per model for
completed runs in each bucket using the automatically fetched pricing snapshot.
Failed and pending runs are excluded. Missing model prices make the affected
bucket and period total unavailable, rather than displaying a partial cost.

- Business web: **MKPOS AI assistant** in the sidebar or app bar opens the
  dialog first without changing the source page. Its **Maximize AI chat**
  header button navigates to `?page=assistant`. Both presentations share one
  mounted chat state, preserving messages, drafts, files and in-flight replies.
  The dedicated page fills the workspace below the app bar with compact
  spacing, no outer card or bordered message panel, and the composer at the bottom.
  The page survives browser refresh; the selected saved conversation reloads.
  In-session module navigation retains messages, draft, attachments and source
  page data through the shared retained-module shell. App-bar Back and browser
  Back/Forward use the existing module history. Explicit Refresh reloads the
  assistant configuration and messages without clearing the draft.
- Mobile: open the assistant from the app bar or drawer. `/assistant` is a
  dedicated full-page conversation inside the existing swipe-refresh shell.
  Back uses the actual source history entry; cached source data and drafts are
  retained. The assistant is unavailable in offline mode.
- **New chat** starts a new draft. The conversation is created when its first
  message is submitted. Desktop full-page chat keeps a conversation list on the right,
  reusing chat-room thread rows with selection, timestamps, a New chat header action,
  scrolling pagination and a Load more fallback. The dialog's **History** opens the current user's prior conversations;
  mobile appends additional conversations and older messages through endless
  scrolling with a Load more fallback. Messages are fetched in bounded pages.
- Replies are returned as complete JSON responses in this basic release. The UI
  shows a processing state while the SDK executes its bounded tool loop. Token
  streaming, image/voice input, autonomous actions and background agent scheduling
  are not enabled in this release.
- Attach a UTF-8 product CSV up to 5 MB / 5,000 products. Laravel performs the
  shared import validation, then sends only its bounded result (at most 20 issue
  rows) to the model. The original uploaded CSV is not stored or sent wholesale
  to the provider. The validation summary and supplied filename remain in the
  user's message history. Download CSV template returns the actual standard CSV.
  API callers can declare extra price headings through `price_types`.
- A passing file check is preliminary. Signed-in product import still checks
  business configuration and existing inventory. With Phase 2 installed, owner
  uploads also create private import staging records. Products are imported only
  after the owner reviews and confirms a separate import proposal in the app.

## Agent and authorization

`App\Ai\Agents\MkposAssistant` provides bounded conversation context (the most
recent 12 completed messages), at most 6 model steps per reply, configured output
tokens per step and a 40-second provider-request timeout. Replies follow the
user's language. It has no shell, SQL, web-browsing or arbitrary HTTP tool.

The knowledge tool wraps an allowlist of the existing public MCP knowledge
services: overview, help search/articles, form guides, troubleshooting, plans,
payment recipients, tutorials, supported devices, releases, support contacts and
product templates. The current authenticated business owner additionally gets
a read-only subscription-status tool and the Phase 1 business tools below. Staff
retain only the knowledge tool. Identity
and business scope are resolved server-side, never from model arguments or text.
The agent cannot approve payments, change subscriptions or send external messages.
Phase 2 tools prepare product/contact/expense/import proposals; only the trusted
owner confirmation endpoint saves them. Tool content and CSV cells are untrusted data.

Conversation ownership is checked by both user ID and business ID on every
request. Sharing a business does not grant access to a coworker's conversations.
History is stored in the application database; the browser stores only its
active conversation ID in an account-scoped key. Switching accounts remounts
and clears the in-memory chat state. Cross-device access is through History;
this release does not broadcast live changes between open devices.

### Structured reply formatting

Assistant replies use structured Markdown: short sections, bold labels, lists,
read-only checklists, quotes, code examples and pipe tables for reports/comparisons.
Replies lead with the requested answer or action status. Simple questions normally
receive 1–3 short sentences, without greetings, repeated summaries, generic offers
or closing follow-up questions. Additional sections and next steps appear only when
needed. Essential date/currency context, uncertainty, access restrictions and owner
confirmation remain explicit, stated once rather than repeated.
Report replies place the business-local period and currency before a compact table;
current snapshots and estimates remain clearly distinguished from period totals.
Simple answers and access denials remain brief. Findings still require tool evidence.

Both chat clients render assistant messages with `AssistantReply` using
`react-markdown` and `remark-gfm`. Existing history renders without migrating stored
message text. User messages remain literal plain text. Tables scroll horizontally
inside the message on narrow screens, with a keyboard-focusable region; headings,
lists and numbers retain readable sizes. HTML, scripts, embeds and images are not
enabled. Only HTTP(S), mail and telephone links are rendered; links open separately
with noopener/noreferrer to preserve the source chat. Markdown does not introduce
new model tools or grant permission to data/actions.

`AssistantReply.test.jsx` in both clients covers structure, Myanmar text, escaped
pipes, checklists and safe rendering. `scripts/check-ai-reply-formatting.cjs` checks
rendered tables, headings, narrow widths (320/393/768), confirmation controls and
source/draft retention with intercepted APIs. Screenshots are under
`artifacts/ai-reply-formatting/` at repository root.

### Role-aware access explanations

Each model request includes safe server-derived access facts from
`AiAssistantAccess`: current stored role, assigned module permissions, enabled
business modules, owner-only AI policy and a reason when access is unavailable.
These facts contain no credentials or business figures. Role claims in chat and
old assistant replies cannot grant access. The assistant must not repeat previously
retrieved private values after the user's current access becomes restricted.

For a staff member missing Reports permission, the assistant explains that their
assigned role does not allow Reports access and directs permission review to the
business owner in Access management. For staff who already have Reports permission,
it explains the separate policy: the Reports screen may be available, but private
business reads through AI are currently owner-only. Granting Reports permission
does not itself unlock owner AI tools. Disabled modules, inactive accounts/businesses,
expired-subscription write restrictions, missing records and temporary failures
receive different explanations. General MKPOS guidance remains available.

Replies should be brief, polite and in the user's language. They must not suggest
restricted screens, credential sharing, permission bypasses, or claim that the bot
granted access/sent an access request. Private tool failures include safe role/module
context for an accurate answer. Subscription reads also recheck the stored owner
role before looking up billing. `AiAssistantAccessTest` verifies role/permission
facts, cross-business role assignments, stale roles and provider-bound instructions.

### Phase 1: owner business reads

Owners receive **12 additional read-only AI tools** (14 interfaces before Phase 2,
including knowledge and subscription). These call authenticated Laravel services
directly. They do not register new public or external MCP endpoints; existing MCP
tool counts and account/admin permissions remain separate.

| Tool | Reads |
| --- | --- |
| `get_business_context` | Own business name, currency, timezone, local date and enabled read capabilities |
| `get_business_summary` | Report totals, current accounts/inventory, low-stock count and current pending-order count |
| `search_products` | Active products by name, SKU, barcode or category |
| `get_product_details` | One product's units, conversion, cost, stock and named prices |
| `get_inventory_report` | Current low/out-of-stock products or dated stock movements |
| `search_sales` | Filtered receipts, full matching totals and selected receipt's paginated items |
| `search_purchases` | Filtered purchases, full matching totals and selected purchase's paginated items |
| `get_customer_account` | Customer search/ranking, current balances, selected customer's dated sales/payments |
| `get_supplier_account` | Supplier search/ranking, current balances, selected supplier's dated purchases/payments |
| `get_expense_report` | Dated expenses, total amount and top categories |
| `get_business_report` | Report-screen totals, estimated profit, cash movement and top products |
| `get_shop_orders` | Own order search, order/payment states and selected order's paginated items |

Every owner reply starts with a provider-enforced `get_business_context` call;
the SDK releases the forced tool choice after that first step so the model can
read the relevant business tool and answer. Server-provided session instructions
identify the authenticated owner and explicitly supersede old chat replies
claiming that business access is unavailable. Staff retain automatic public
knowledge tool selection. This uses one model step for owner context even on
general support questions and stays inside the bounded six-step limit.
Relative dates
use that business's timezone, rather than the provider/server clock. Dated tools
default to the business-local current day; supplying one date selects that day.
Use explicit `start`/`end` (`YYYY-MM-DD`) for periods, or `all_time: true` without
dates to search full history. Compare periods with separate report calls. Purchase
and payment timestamps follow the Reports screen's `created_at` basis; expense
reports use `expense_date`. Current account balances, current inventory and pending
order counts are independent of historical date filters. Original invoice credit
does not represent the account's remaining debt. Profit remains an estimate.

Each call verifies the current stored owner role, active user/business, and the
server's tenant context. Business IDs and user IDs are never tool arguments.
Every query, including child records and balance subqueries, explicitly filters
the authenticated business; no SQL or arbitrary table/URL input is accepted.
Tools respect configured business modules. Read access follows existing GET
routes: expired subscriptions retain history access. No new mutation permission
is granted by calling these tools through the chat POST endpoint.

Results carry currency, timezone, observation time, applicable date range, record
IDs and source API paths. Those paths identify data sources, not new UI routes;
the existing chat remains text-based. Lists default to 10 records, cap at 20 and
return total/has_more/next_offset; offsets cap at 10,000. Selected transaction/order
IDs keep the parent record while offset paginates its items. Selected account IDs
keep the account while offset independently paginates its transaction and payment
histories. Long business strings are truncated at 500 characters. The assistant
must explain partial results rather than treating a page as the whole business.
Tracking credentials, password hashes, payment proofs and arbitrary notes are
excluded. Business values returned by a tool are untrusted content.

`BusinessReport` shares calculations between the existing Reports controller and
AI. `BusinessAccounts` shares scoped balance calculations, with totals aggregated
in SQL. AI calls omit unused chart series. Business tool names join the existing
message/run tool logs. Requested business results go to the configured DeepSeek
provider as tool context; the final answer remains in the owner's private chat
history under the existing ownership checks.

No database migration, new API route, frontend build or paid provider request is
needed for this phase. Deploy the PHP changes with the existing assistant setup.
Use isolated tests (SQLite memory and intercepted provider HTTP):

```shell
php artisan test --compact tests/Feature/AiBusinessToolsTest.php tests/Feature/AiAssistantTest.php tests/Feature/AiUsageReportTest.php
```

Coverage includes all 12 reads, business isolation, injected tenant arguments,
stale owner privileges, inactive users/businesses, module restrictions, local
midnight boundaries, report parity, payment directions, bounded pagination and an
actual SDK context-to-sales tool loop. No live customer or financial data is used.

### Phase 2: owner-reviewed business changes

Phase 2 adds **15 tool interfaces**, bringing the installed owner's total to
**29** before pricing diagnostics: 10 named proposal operations, an import-preview tool, and four shared
proposal helpers. Staff retain public knowledge only. Existing external MCP
servers and their 31 unique tool definitions are unchanged.

Named tools: `create_product`, `update_product`, `update_product_prices`,
`preview_product_import`, `execute_product_import`, `create_customer`,
`update_customer`, `create_supplier`, `update_supplier`, `create_expense`, and
`update_expense`. The execution-named import tool **prepares** a proposal; it
cannot bypass owner review. Named operations accept a `data` object; updates
require its `record_id`. Omitted fields are preserved. The shared
`prepare_business_action` accepts an allowlisted `operation` and `data`;
`get_action_preview`, `get_action_status`, and `execute_confirmed_action` accept
an `action_id`. The last helper retrieves an already-confirmed result and refuses
to save pending proposals. No tool accepts a confirmation boolean or approval token.

Apply only the additive action/audit-storage migration after the existing AI
migrations:

```shell
php artisan migrate --path=database/migrations/2026_10_25_000004_create_ai_business_actions.php --force
```

It creates `ai_business_actions` without changing existing business records,
inventory, balance books or subscriptions. The existing `product_imports` tables
are required for CSV staging/import. Action tools appear only when their storage
exists. Build/deploy both `frontend` and `mobile` clients for the review UI; this
does not require an Office rebuild or new queue worker.

Each proposal stores its owner, business, conversation, source user message,
operation, validated payload, exact before/after preview, record baseline,
business currency/timezone, preview digest, expiry and final result. Identical
preparation within one source message reuses the proposal. At most 10 unexpired
pending proposals per conversation are allowed. Proposals expire after 30 minutes;
completed results remain readable and replayable. There is no edit/delete endpoint
for these audit records.

Both chat clients show Business changes cards in the existing message scroller.
Review opens inline, preserving the source page, draft, history and chat controls.
The owner loads every preview page, checks the explicit review checkbox, then
presses **Confirm changes**. Cancel proposal saves no business change. Close review
only collapses the preview and restores focus. Confirmation sends the saved digest
and reviewed count, never a replacement payload. A chat “yes” or model-generated
`confirmed: true` is not approval. The model has no arbitrary HTTP tool or tool
that can reach the confirmation endpoint.

The owner-session endpoints under `/api/ai-assistant/conversations/{conversation}`:

| Endpoint | Behavior |
| --- | --- |
| `GET /actions?offset=0` | Own proposal summaries, 20 per page |
| `GET /actions/{action}?limit=100&offset=0` | Exact preview; app pages at 100, model helpers cap at 20 |
| `POST /actions/{action}/confirm` | Owner app confirmation with `preview_hash`, `reviewed_count`; validate and save atomically |
| `POST /actions/{action}/cancel` | Cancel a pending proposal |

These routes use existing session/Sanctum authentication, password-revocation and
business middleware plus owner enforcement. Proposal creation and execution both
recheck the current stored owner, active user/business, enabled AI/module and
subscription mutation entitlement. Expired subscriptions may read history and
cancel proposals but cannot prepare or save changes. Replaying a completed action
does not post again or require AI credit. Confirmation itself makes no provider
request and consumes no model-message quota.

Execution locks the business and proposal, verifies the digest/expiry and checks
the stored record baseline and business currency/timezone. Changed records require
a new proposal. The write and completed audit result commit in the same transaction;
retries cannot create duplicate contacts, expenses, price changes or import stock.
The final result includes affected modules so clients refresh affected data through
their existing events without discarding filters or source-page state. The current
proposal statuses/results are supplied to subsequent model requests, so an owner
can follow up without repeating a completed action.

Product and CSV writes reuse `ProductWriter` and `ProductImportService`; normal
product forms share the extracted update operation. Normal customer, supplier and
expense forms share `BusinessRecordWriter` validation with proposals. New products
created through chat start at zero stock; product updates preserve existing stock.
Inventory adjustments, sales, purchases, payment posting and deletion remain outside
Phase 2. CSV imports may create the opening stock explicitly shown in their preview,
using the existing opening-stock movement behavior.

Price proposals accept up to 100 explicit `{product_id, price_type, price}` changes,
or a category, named `price_type` and percentage (-100 through 1000). Category
selection freezes the actual target records before review. Whole-unit prices round
to the nearest integer, other named prices are preserved, and approved overrides
become manual prices. Partial price failures roll back the whole proposal.

Owner CSV attachments retain bounded validation summaries in chat and private
normalized staging rows on the server. Raw CSV contents are not sent wholesale to
the model. `preview_product_import` reads only the owner's import, including at most
20 issue rows; `execute_product_import` requires its import ID and prepares the
exact ready rows. Invalid/failed rows block approval; duplicate/skipped rows remain
excluded and are counted in review. Up to 5,000 ready products can be confirmed in
one proposal. Existing 50-row import checkpoints run inside the approval's outer
transaction: a conflict or failed row rolls back all products, stock movements and
checkpoints from that approval. Staging/proposal rows themselves are not products.

Focused backend checks use SQLite memory and intercepted model HTTP:

```shell
php artisan test --compact tests/Feature/AiBusinessActionsTest.php tests/Feature/AiBusinessToolsTest.php tests/Feature/AiAssistantTest.php tests/Feature/AiUsageReportTest.php tests/Feature/DeepSeekPricingTest.php tests/Feature/McpProductImportTest.php
```

Both clients have action-component tests for explicit consent, duplicate clicks,
bulk pagination, cancel/focus restoration and timeout-to-status recovery, alongside
their existing chat tests. `scripts/check-ai-business-actions.cjs` at repository
root verifies rendered web/mobile review, confirmation, cancellation and draft/source
retention with intercepted APIs only. Screenshots are under
`artifacts/ai-business-actions/`. Physical-device Back/keyboard behavior and production
MySQL concurrent writes still need deployment/device checks.

Phase 2 control verification record:

| Control | Expected result | Verified |
| --- | --- | --- |
| Review / Close review | Open exact values, close without a write, restore focus/draft | Component tests and rendered web/mobile browser fixtures |
| Load more changes | Append the next preview page; consent stays disabled until all changes are loaded | Component tests and backend pagination |
| Review checkbox / Confirm changes | Explicit consent, submit the exact digest once, display saved result | Component tests, browser fixtures and backend replay tests |
| Cancel proposal | Mark only the proposal cancelled; no business write | Component tests, browser fixtures and backend tests |
| Check action status | Recover after a timeout without issuing another execution | Component tests |
| Load older changes | Append proposal history without opening or saving it | Component tests |
| Web minimize/close/reopen | Retain conversation, proposals and draft | Rendered browser fixtures |
| Mobile app-bar Back / browser Back and Forward | Return to the same mounted Products page without a new initial list request, then resume chat/draft | Rendered browser fixtures; physical device Back untested |

Local verification also included a live DeepSeek context-to-create-customer
proposal flow. Its proposal was cancelled afterward and the business customer
count stayed unchanged. All actual business writes in automated checks used
isolated SQLite or intercepted browser APIs.

### Read-only price checking

Three additional owner tools bring the current installed total to **32**:

| Tool | Purpose |
| --- | --- |
| `check_product_prices` | Check product selling prices, named-price coverage and automatic/primary-price mismatches |
| `check_pricing_rules` | Inspect configured price types and rule validity without creating or changing settings |
| `get_pricing_health_report` | Combine configuration and product-price checks with evidence, counts and recommended review steps |

These use the existing private business reader's owner, active-account, tenant and
enabled-products-module checks. Staff receive no private pricing tool. They do not
register public MCP endpoints, make changes, run imports or prepare corrections.
There is no new migration or client build requirement. The agent uses the existing
Markdown report-table renderer and answers in the user's language.

Confirmed errors include invalid/duplicate configured types or rules, conflicting
named prices, invalid selling prices, automatic prices inconsistent with the current
rule, and a primary product price inconsistent with its Retail/first named price.
Warnings include missing configured named prices, zero cost/price, below-cost sales,
unconfigured price names/rules and automatic flags without valid automatic rules.
Missing saved rules use the existing default manual rule; they are not automatically
an error. Explicit manual overrides are counted but never treated as automatic-rule
mismatches. Missing named prices and below-cost/free prices can be intentional and
require owner review. No market-price, historical, coupon or flash-sale verification
is claimed.

`ProductPricing::automatic` shares the existing formula with price-setting and
purchase recalculation: take the higher of rounded-up cost-plus-markup and
cost-plus-minimum-profit, then round upward to the configured increment. All prices
and costs are compared in the product's base unit. Evidence includes record IDs,
price type, current and expected automatic values where meaningful, unit and next
step. Corrections still require an explicit owner request and Phase 2 review.

Product checks accept `product_id`, `query`, `category`, `price_type`, `severity`
(`all|error|warning`), `issue`, `limit` (1–20), `offset` (0–10,000),
`after_product_id` and `scan_limit` (1–10,000; default 10,000). Rule checks accept
the type/severity/issue filters and pagination. `findings.next_offset` pages findings
within the same scan; `scan.next_after_product_id` continues a bounded product scan.
Counts describe that scan and the applied finding filters, not an unbounded claim
about the whole business. Rule findings are included in the first combined scan
only, so continued batches do not count them again. Rule rows have their own
`rules.next_offset` pagination for the rule tool. Reads use batches of at most 250
products and explicitly scope child prices and rule/settings queries to the owner.

`AiPriceCheckerTest` verifies default rules, read-only behavior, business isolation,
permissions, rounding/minimum-profit calculations, manual overrides, warnings,
invalid rules, pagination/scan limits and an intercepted SDK price-report loop.

## API contract

All Business endpoints require the existing Sanctum/session, password-revocation
and active-business middleware. They remain accessible outside the subscription
mutation gate so expired owners can ask for renewal help.

| Endpoint under `/api` | Behavior |
| --- | --- |
| `GET /ai-assistant/config` | Enabled/configured/storage status and limits, no credential |
| `GET /ai-assistant/product-template?price_types=VIP` | JSON `filename` and `csv` for a download |
| `GET /ai-assistant/conversations?limit=20&offset=0` | Own history; `has_more`, `next_offset` |
| `POST /ai-assistant/conversations` | `{id: UUID}`; idempotent, ownership preserved |
| `GET /ai-assistant/conversations/{id}/messages?limit=30&before=123` | Chronological page; `has_more`, `next_before` |
| `POST /ai-assistant/conversations/{id}/messages` | JSON or multipart `client_id`, `body`, `language`, `app`, optional `csv`, `price_types` |
| `GET/PUT /office/ai-assistant` | Super-admin configuration, usage totals and latest 20 run records |

Messages accept up to 4,000 characters. The same `client_id` and content returns
the saved exchange once completed. Different content with the same key returns
409. A failed message remains saved and can be retried with its original key;
its CSV validation summary is reused when no new attachment is sent. Only one
reply per user runs at once, guarded by a cache lock. Use shared cache storage
when deploying more than one application server.

Each provider attempt, including failed retries, counts against the per-user
weekly limit (`weekly_limit`). The quota is checked while locking the user record.
Weeks start Monday at midnight Asia/Yangon, with the boundary converted to UTC
for stored timestamps. Existing configured limits are preserved by migration
`2026_10_25_000011_use_weekly_ai_request_limit`. Duplicate completed responses do not
consume another model request. Separate named rate limits bound reading and
sending. A disconnected browser does not cancel an already-running provider
request; retrying retrieves its completed result. Provider errors return a
generic message and never include the credential or raw provider exception.

Office usage totals contain successful reported input/output tokens. Failed
requests may incur provider usage that was not reported, so these are operational
metrics, not a billing invoice. Run logs contain status/model/tool names and token
counts, excluding message text and secrets. Configuration changes use the Office
activity log. Raw conversation text is not included in that Office screen.

## Verification and limits

Backend fixtures verify tenant/user isolation, pagination, idempotency, content
conflicts, failed retries and quotas, disabled/unconfigured behavior, CSV checks,
read-only tools, super-admin authorization and the actual SDK DeepSeek tool loop
with HTTP responses faked. No paid model request is needed for these tests.

Component tests cover both chat surfaces, new/history/close/minimize/draft flows,
attachment errors and Office settings. `scripts/check-ai-assistant.cjs` checks
rendered web/mobile send/retry/history, source-page retention and screenshots
using intercepted APIs only. Screenshots are in `artifacts/ai-assistant/`.
Physical-device keyboard/Back and a live DeepSeek exchange require a configured
server key and device testing; they must not be inferred from fixture checks.

### Control verification record

| Control | Handler and expected behavior | Observed verification |
| --- | --- | --- |
| Web AI entry / mobile AI entry | Open the assistant without discarding the current Business page | Browser fixtures passed; mobile returns to the retained Products DOM without an initial list fetch |
| Send / Retry | Save one exchange, preserve failed messages and reuse their client key | Component, browser and backend fixtures passed, including duplicate prevention |
| New chat / History / conversation selection | Start an empty draft or load the user's selected conversation | Component and browser fixtures passed |
| Older messages / more history | Fetch the next bounded page and merge unique records | Component and backend pagination fixtures passed; mobile uses endless scrolling |
| Attach CSV / remove attachment / CSV template | Validate file type, remove a draft attachment or download the template | Component fixtures and backend CSV/template checks passed; native device download not tested |
| Web Minimize / Close | Hide chat while retaining its draft and source page | Browser fixture passed |
| Mobile app-bar Back / browser Back and Forward | Restore the actual source page and retained conversation | Browser fixture passed with retained Products DOM; physical device Back not tested |
| Office Status / Model / limits / Save | Save non-secret settings once, preserve values on validation/network failure | Two component tests and rendered Office browser fixture passed |
| Refresh / error retry | Retry failed loading while retaining the draft | Component fixtures passed; mobile uses the shared swipe-refresh shell |

Final local checks: 449 web tests, 715 mobile tests, 16 focused Office tests,
and the backend AI/MCP suites pass. Production and Laravel-targeted builds
were generated for all three clients. Browser API fixtures make no paid
provider calls and do not modify live customer or financial records.

The desktop assistant flow is verified by `node scripts/check-ai-assistant-page.cjs` with intercepted APIs: app-bar and sidebar open the dialog first; Maximize navigates with the same messages and draft and loads the right-hand conversation list. Checks cover flat workspace dimensions at 1440/1024px, send once, history selection, New chat, absence of the CSV download button, explicit Refresh, browser reload, app Back and browser Back/Forward. Returning to Products retains the same mounted source without another initial products fetch; reopening the dialog or maximizing retains the draft and selected conversation. Component regressions check that maximizing preserves attachments without reinitializing chat, and that conversation pagination deduplicates rows. Existing business-action controls reuse the same component and API; this fixture does not execute a business mutation. Fifteen focused assistant/navigation/sidebar tests, ESLint on changed modules and production build pass. Screenshots are in `artifacts/ai-assistant-page`. Desktop CSV attachment upload remains supported; the CSV template download button has been removed from both dialog and page.

Full-page visual refinement: regular-weight, high-contrast 13px message text with Myanmar-safe line spacing, restrained 10px metadata, bounded reading width, consistent message gutters, and a white conversation sidebar with compact two-line titles and a selected-row accent. The header and composer remain flat. Browser fixtures include Myanmar text, multiple dated conversations and a Markdown report table; screenshots were inspected at 1440px and 1024px. Computed-style checks guard paragraph weight, timestamp size and horizontal overflow. Fourteen focused assistant/Markdown tests and the production build passed for this styling update.

The chat and conversation list now each have a subtle 1px border and 8px corners, separated by a 12px gutter. The message scroller remains unbordered inside the chat panel. Viewport height reserves 10px above/below the workspace. Browser checks verify both panel borders, composer visibility, text overflow and unchanged navigation; screenshots and the production build were verified.


### Owner-approved app settings (2026-10-25)

Owners now receive `get_app_settings` and `manage_app_settings` (34 total owner
interfaces when business actions are installed). Allowed settings cover business
name/title/address/phone, currency label, POS payment method names, receipt content
and layout, barcode paper/layout/content, printer name and language. The allowlist
comes from `SettingsController::DEFAULTS` with price types excluded; validation
shares the normal Settings endpoint rules and adds strict toggle/scalar checks.
The tool never reads the raw settings table into a provider response.

`create` adds an absent saved override; `update` changes only supplied values;
`delete` and `reset` restore the displayed application defaults. Business name
and currency must be updated rather than deleted/reset. Currency changes only
labels, never converts historical amounts. Security/PIN/password/token settings,
account profile/security, staff/role assignments, price-type rules, storefront
configuration, image uploads and device-native printer pairing remain outside
this tool. Public business phone/address are ordinary business profile fields.

The chat review displays the current and proposed values with text, number,
select and multiline controls. Editing disables consent and confirmation.
**Refresh review** validates values server-side, cancels the previous immutable
proposal and prepares a new proposal; it does not save settings. Failed validation
rolls back cancellation. The owner must separately check consent and press
**Confirm changes**. Reset/delete proposals display the exact defaults and are
read-only. Close, cancellation and navigation do not save business changes.

The owner-only `POST .../actions/{action}/revise` endpoint requires the current
preview hash and the same allowed field set. No AI tool exposes this approval
endpoint. Confirmation rechecks stored owner identity, active business/module,
subscription, expiry, hash, currency/timezone, baseline settings and business/
shop profile. Another edit invalidates the old proposal. Confirmation atomically
writes settings and saves its result; retries cannot execute it twice. Business
renames synchronize settings.shop_name, businesses.name and an existing online
shop display name. Both clients publish data changes and refresh session identity
after a completed settings change.

Verification: five backend settings cases cover preview-only preparation,
confirmation/replay, profile synchronization, tenant/security isolation,
validation, stale writes/access, immutable revisions and create/delete defaults.
Two component cases per client cover edit invalidation and validation failures.
`node scripts/check-ai-settings.cjs` verifies web/mobile review, edit, refresh,
consent, confirmation once, cancellation, retained draft and navigation/source
state using intercepted APIs. Screenshots: `artifacts/ai-settings`. A live
DeepSeek controller request successfully prepared a shop-name proposal; settings
remained unchanged and the proposal was cancelled after verification. No live
settings confirmation was performed.


### Guided onboarding for new owners (2026-10-25)

One MKPOS Assistant now combines business tools with seven application workflow
skills in `app/Ai/Skills/OwnerOnboarding.php`: business setup, first products,
first purchase, first sale, customer/supplier credit, daily closing review and
troubleshooting. These skills describe existing app workflows; they are not
Codex filesystem skills, additional autonomous agents, or authorization grants.
Detailed form and troubleshooting help still uses the existing MKPOS knowledge
source. Existing owner-approved business changes remain available.

Five read interfaces were added (39 total owner interfaces when action
tables are installed):

| Tool | Behavior |
| --- | --- |
| get_setup_progress | Current checklist from saved identity, payment names, active products, inventory and completed-sale evidence |
| check_business_readiness | Missing checked setup and review suggestions; not a complete business audit |
| get_feature_guide | A bounded workflow topic with steps and an enabled screen action |
| get_next_setup_step | One next step, prioritizing missing setup before review items and first-sale milestones |
| open_app_screen | A bounded screen button; does not open anything automatically or save records |

The four private onboarding reads require the current stored owner role, active
business, tenant identity and enabled modules. Navigation also supports staff
with permitted pages, without granting private business data access. Disabled
modules are omitted from progress. Settings reads use only the safe profile and
payment method names; no credential or staff settings are read. Defaults are
identified as defaults rather than evidence of owner review. Zero inventory and
zero selling prices produce review suggestions because service/free-product
workflows may be intentional. First sale is a milestone, not permission to post
practice transactions. No onboarding read performs business writes.

For ?I am new, help me start?, the agent reads actual progress and replies with a
compact checklist, one next step and a server-supplied button. App navigation uses
`[Open Settings](#mkpos-screen=settings)` with a fixed module allowlist. Each client
also checks its enabled/permitted screens. Unknown, disabled, security, staff,
arbitrary relative paths and API URLs never become navigation buttons. Buttons
are disabled while an AI exchange or business action is busy; external links keep
their existing safe behavior. Button clicks use existing SPA navigation, not
page reloads, and do not constitute approval for a business change.

Desktop dialog navigation records an assistant source entry before visiting the
screen. **Back to MKPOS AI** and browser Back restore the retained conversation and
draft, including from the full assistant page. Mobile uses its existing retained
route views and Back stack. Both clients preserve chat state without reloading
messages merely because the user returns.

Verification: backend cases cover empty and existing businesses, tenant scope,
read-only SQL, optional versus required checks, disabled modules, bounded screen
IDs, workflow guides and changed owner access. Component tests cover allowed,
disabled and unknown navigation links, busy state and a missing navigation
handler; the desktop history regression checks the assistant source entry.
`node scripts/check-ai-onboarding.cjs` verifies actual web/mobile checklist and
button rendering, keyboard activation, Back/Forward, draft retention, absence of
message reloads and no business mutations using intercepted APIs. Screenshots are
in `artifacts/ai-onboarding` and were visually inspected. A live AI controller
request called `get_business_context` and `get_setup_progress`, returned the real
business checklist with one Settings button, created no business proposals and
left business settings unchanged. Physical device Back is not tested.


### Image reading in owner/staff chat (2026-10-25)

The existing attachment picker now accepts one JPEG, PNG or WebP image OR one
product CSV per message. Images are limited to 5 MB and 8192 pixels per side;
server-side image/MIME inspection rejects invalid bytes, unsupported formats and
mixed image/CSV submissions. Image-only messages receive a short description
prompt. Text, image and CSV remain separate request paths; images are never
staged as product imports.

Images are saved on the private local disk under a server-generated business/
conversation directory. Only safe name/type/MIME/size/dimension metadata is
returned to the chat; internal paths and integrity hashes are removed from every
message response, including delivery/history/replays. No public image URL is
created. The SDK receives verified inline image bytes, never arbitrary URLs or
client-supplied file paths. Stored image hashes bind retries to the original
content. Retrying a failed saved message uses its private image without requiring
another upload; replaying a completed message does not call the provider or
charge again. Rejected unsaved uploads are cleaned up. Existing owner/conversation
access checks also protect image messages.

For follow-up questions, the last 12 completed messages can include at most the
two most recent image attachments in that context, plus a current attached image.
Older/unavailable images are labelled as not included; missing historical files
do not break new exchanges. Image reading does not expand staff access to owner
business tools. The assistant treats screenshot text as untrusted evidence and
must not infer payment authenticity, bank funds or authorization from an image.
Business mutations still require the existing owner review/confirmation UI.

Backend regressions cover image-only sending, private metadata, replay, changed
content, failed-message retry, two-image history limits, cross-owner access,
spoofed/unsupported/oversized files and mixed attachments. Both clients have image
multipart and remove-with-draft tests. `node scripts/check-ai-images.cjs` verifies
picker validation, attach/remove, file/draft retention on return, one multipart
image submission and a structured reply with intercepted APIs. Screenshots in
`artifacts/ai-images` were visually inspected. A live configured DeepSeek request
read the supplied receipt amount, recipient, masked account ending and transaction
reference successfully, with zero business proposals or payment submissions.

## Attachment previews

Desktop dialog, desktop page and mobile now show an attachment card with an image thumbnail or CSV icon, filename, type, size and accessible remove action. Draft and outgoing image previews use local object URLs, revoked on replacement/unmount. Sent images expose only a scoped preview endpoint through message metadata; authenticated download helpers preserve Sanctum, token and desktop bridge credentials. `GET /api/ai-assistant/conversations/{conversation}/messages/{message}/image` verifies actor/conversation ownership, message membership, private storage prefix and content hash before returning bytes with private/no-store and nosniff headers. Raw storage paths and hashes remain excluded from message responses. Unavailable saved images retain a file card with clear preview-unavailable text.

`node scripts/check-ai-attachments.cjs` verifies saved and selected image thumbnails, CSV metadata, remove, maximize retention and overflow at 393/320px using intercepted APIs. Screenshots in `artifacts/ai-attachments` were inspected. Twenty backend tests (including image access isolation), 13 desktop tests, 11 mobile tests, changed-module ESLint and both production builds passed. No live AI request was made for visual QA.


### Requested page and form buttons (2026-10-25)

`open_app_screen` now accepts 26 fixed destination IDs in
`resources/ai/app-pages.json`, mirrored by both clients. The catalog includes 11
module pages, five native create forms (`products_new`, `customers_new`,
`suppliers_new`, `purchases_new`, `expenses_new`) and ten settings sections
(`settings_shop`, `settings_receipt`, `settings_barcode`, `settings_printer`,
`settings_payments`, `settings_prices`, `settings_language`, `settings_timezone`,
`settings_account`, `settings_security`).
It accepts no arbitrary URLs, paths, JavaScript, record IDs or action payloads.
A page-link request never creates a record or prepares an AI business change.

For example, ?Where can I change receipt settings?? yields
`[Open Receipt Settings](#mkpos-screen=settings_receipt)` and ?Open Add Customer?
yields `[Add Customer](#mkpos-screen=customers_new)`. The agent is instructed to
return the exact tool-generated button and one short sentence. Main-page IDs and
existing older chat links remain supported.

Laravel verifies the current stored account, tenant/business, enabled module and
role permissions before returning a link. Owners retain 39 interfaces. Staff
now have two: public knowledge and navigation; private owner reads and actions
remain unavailable. Staff may open assigned module pages/forms and existing
permitted receipt, barcode and printer settings plus their own Account page. Business identity, payment
methods, pricing, language, time zone and billing destinations are owner-only.
Both renderers and navigation handlers also apply the client module/role guard.
Native destination screens and API endpoints retain their normal access checks.

Desktop history records the approved destination alongside its assistant source.
A bounded hook activates the correct settings tab or opens the existing native
form, waiting for its normal initial data. An already open form is retained.
Back/Forward preserve unsaved form and chat drafts; cancelling an AI-opened form
clears its target and returns to chat. Mobile destinations use existing routes,
including `/settings/receipt` and `/customers/new`, and their normal Back/Cancel
behavior. Opening a form does not grant consent to save anything.

Verification includes catalog parity with locally available client sources,
read-only destination generation, staff allow/deny and inactive-user checks,
client role/module guards, native destination activation and cancellation.
`node scripts/check-ai-page-links.cjs` exercises all ten settings sections and
five forms on web/mobile. It checks desktop selected tabs, mobile routes, native
form dialogs, Cancel/Back, chat retention without message reloads, unsaved customer
form retention across Back/Forward, and absence of business writes. Receipt
preview POSTs are read-only and intercepted. Screenshots in
`artifacts/ai-page-links` were inspected. Live AI checks produced the exact Receipt
Settings and Add Customer buttons with zero business proposals; one temporary
provider connection failure recovered through the existing message retry flow.
Physical-device Back remains untested.


Password/how-to navigation follow-up: the agent now proactively calls
`open_app_screen` whenever its answer directs the user to a supported page,
including actions the agent cannot perform itself. ?Where do I change my
password?? must include the `settings_account` button for Settings > Account,
where the user enters their current/new password. It must not invent a separate
Change password menu, ask for passwords in chat or confuse a security mutation
restriction with a navigation restriction. `settings_security` opens owner-only
protected-action/PIN settings; it is not the personal password form.

The same Burmese password question was checked against the live configured
provider and returned the Account Settings button with zero business proposals.
Owner/staff account-link and owner-only security-link regressions pass. Browser
checks now cover ten settings sections and five native forms on both clients;
web/mobile Laravel builds include the updated destination catalog.


### Compact responsive reply forms (2026-10-25)

Business-action review cards and editable settings forms now use scoped, compact
spacing. Fields follow their actual card width with CSS container queries:
under 480px of available content they use one column; wider cards use two.
Multiline fields and the review-refresh row span the form. Read-only comparisons
also use two field/value pairs per row on wider cards. Very narrow approval rows
stack their buttons. Long field labels, values and Myanmar text wrap inside the
card, and report tables scroll within their own region rather than the page.

Desktop controls use 32?34px minimum heights, with less padding and spacing.
Mobile controls keep 44px targets and 16px input text, with line spacing retained
for Myanmar marks. Native textarea resizing and natural message scrolling remain
available; no fields, before/after values, notices or consent controls are hidden
to fit a viewport. Styles affect assistant reply/review components only.

Desktop review state is cached in the account-scoped assistant component memory,
keyed by conversation, so switching from dialog to the full assistant page
preserves the current preview, edits, validation error and consent state without
another preview fetch. It is not placed in local storage or history URLs. Another
conversation cannot reuse the cached review. Maximize is disabled during a
business-action request to avoid remounting its active handler. Closing the review
restores focus to its review control, including after a portal remount.

`node scripts/check-ai-response-density.cjs` verifies a seven-field review with
text/number/select/multiline inputs, long values and Myanmar text. Checks cover
desktop dialog plus 1440/1024px page layouts and mobile 320/393/768px layouts,
column selection by actual card width, bounded controls and page width,
full-width textareas, mobile control heights, unsaved edits on maximize without a
preview refetch, review refresh, explicit consent, confirmation once and cancel.
Rendered screenshots in `artifacts/ai-response-density` were inspected. Existing
structured-reply checks also pass for narrow tables, safe links and review flow.
Component regressions cover review caching and conversation isolation; these
checks use intercepted APIs and do not change live business records.


### Wyne Wyne / ဝိုင်းဝိုင်း persona (2026-10-25)

The application assistant now uses `app/Ai/Personas/WyneWyne.php` as its shared
identity and professional-manners instructions for owner and staff sessions.
English name: **Wyne Wyne**. Myanmar name: **ဝိုင်းဝိုင်း**. The persona is a female
AI assistant; it uses **ကျွန်မ** when a Myanmar first-person reference is needed,
and natural courteous wording rather than repeating its name or adding polite
suffixes to every sentence. It remains transparent about being an AI and does not
invent a human biography, age or physical presence.

The soul specifies respectful, patient, attentive, calm and practical behavior;
brief direct answers; simple beginner guidance; evidence-based facts; honest
uncertainty; brief correction of mistakes; privacy and discretion; and calm help
when the user is frustrated. It avoids flattery, flirting, patronizing language,
gender assumptions about users, repetitive introductions and needless questions.
Existing page-button, role/tenant isolation and owner-confirmation instructions
continue to apply. Personality never grants new tools or authorization. Account,
security and staff settings remain excluded from AI business mutations. Payment
screenshot SystemAgent behavior is separate and unchanged by this persona.

`/api/ai-assistant/config` exposes safe `name` and `name_my` metadata. Web and
mobile chat headers, assistant sender labels, loading text and message prompts
use the display identity appropriate to their UI language. The existing MKPOS
assistant navigation/accessible entry identifiers remain compatible.

Backend checks verify the configured names and soul in both owner and staff
instructions, including transparency, professional conduct and approval rules.
Client checks verify the visible header and composer prompt. A live Burmese
identity/capabilities request introduced itself as ဝိုင်းဝိုင်း (Wyne Wyne), used
ကျွန်မ and ပါရှင်, and described itself as the MKPOS AI assistant.


### MKPOS payment-to-billing skill (2026-10-25)

`BillingPayment` adds the owner-only `prepare_billing_request` tool. When an
owner identifies an uploaded receipt as payment to MKPOS, Wyne Wyne prepares a
compact chat form in desktop and mobile. A receipt uploaded only for reading does
not start billing; an image with unclear purpose needs one clarification. Unknown
plan/account selections remain blank rather than being inferred from amount.

The form uses actual active public subscription plans, active platform receiving
accounts and owned images from the same conversation. It shows the selected
screenshot, note, receipt check table and pending-request replacement warning.
Owner validation reuses `OwnerPaymentValidation` and `SystemAgent`: recipient name
first, last four account digits, amount/currency, provider, status, reference,
date rules and duplicate proof/reference protection. Failed or unavailable checks
show a correction and cannot be submitted. Screenshots cannot establish bank funds.
Checking has its existing internal AI usage charge and 30-minute bound cache.

Submission is an authenticated app endpoint, never an AI tool. The owner reviews
the result, checks consent and presses **Submit billing request**. Changes clear
consent and require a refreshed review. The server checks owner/tenant, exact
revision/hash, expiry, current selections, private image/proof hashes, duplicate
ledger and reviewed pending requests. Replays return the same request. The new
request stays **pending Office approval**; no subscription activation occurs.
Existing native Billing and MCP workflows retain their validation requirements.
Native and chat submission share the same atomic pending-request writer.

An expired subscription can submit renewal evidence. Account security and staff
permissions are unaffected. Private paths are never sent to the client or model;
authenticated image preview routes supply thumbnails. Desktop portal transitions
and mobile Back preserve unsent form/chat edits. Cancel creates no billing request.
Submitted forms include a permission-checked **Open Billing** button.

Apply `2026_10_25_000010_create_ai_billing_drafts.php` after the existing assistant
and payment-validation migrations. The tool is registered only when its tables
exist. Owner endpoints live under
`/api/ai-assistant/conversations/{conversation}/billing-requests`: list/show,
`POST /{draft}/validate`, `POST /{draft}/submit`, `POST /{draft}/cancel`.
The message exchange/history payload includes `billing_requests`. No client
supplied business ID, file path or chat approval grants submission authority.

Verification covers receipt mismatch/duplicates, review binding, stale prices,
account changes, expiry, newly pending requests, role revocation, private
conversations, HTTP authorization, cancellation and idempotent pending submission.
Both clients test consent, edits, network recovery and status refresh. The browser
fixture `scripts/check-ai-billing.cjs` intercepts every API write and checks desktop
floating/full-page and mobile (393px/320px), natural scrolling, retained drafts,
validation/submit/cancel, Billing navigation and Back. Screenshots are in
`artifacts/ai-billing`; UI intentionally reuses the compact existing chat form
system rather than redesigning the Billing page.


Local verification: 76 backend tests (796 assertions), 17 desktop and 15 mobile
component tests, targeted ESLint and both Laravel production client builds pass.
The live owner smoke called `get_business_context` and `prepare_billing_request`
with the supplied receipt, returned an unsubmitted form with blank plan/account,
and left the subscription request count unchanged. Its test draft was cancelled.
No live billing request was submitted, approved or activated during verification.


### Business advisor tools and invoice drafts (2026-10-25)

Eight owner-only tools are registered after migration
`2026_10_25_000011_create_ai_purchase_drafts.php`. Instructions live in
`app/Ai/Skills/BusinessAdvisor.php`; calculations and authorization live in
`AiBusinessAdvisor`. Every call rechecks stored owner, tenant, active account and
business, plus the relevant enabled modules. Model arguments cannot select a
business, execute SQL, grant approval, or modify security/staff assignments.

| Tool | Implemented behavior |
| --- | --- |
| check_business_data_integrity | Bounded scans of active product identifiers/units/stock warnings, completed sale/purchase totals, payment splits, missing owned links, orphan lines and setup gaps. Coverage, skipped modules and continuation offsets are explicit. POS rounding and ecommerce delivery fees follow posting rules. |
| explain_business_issue | Recomputes a returned issue against current owned records; reports resolved/unavailable findings without guessing. Returns evidence, impact, supported correction fields and an approved page link. |
| prepare_issue_fix | Proposes only the product fields permitted for the current finding, using existing durable before/after actions and owner confirmation. Stock and financial-history repair require the normal app workflow. |
| get_restock_suggestions | Completed sales demand includes FOC, excludes voided/foreign transactions, and subtracts active online reservations from available stock. Target uses threshold and daily demand times coverage plus lead days. Reports base-unit quantities; unposted drafts are not incoming receipts. |
| get_slow_moving_products | Current positive stock with sales at/below the selected per-product threshold, last completed sale, cost-based stock value and paginated coverage. Sparse-history/seasonality caveats are explicit. |
| get_outstanding_accounts | Current customer/supplier balances net of completed payments, with positive balances and credits kept distinct. Lists active accounts only; no overdue or invoice-aging claims because due dates are not tracked. |
| prepare_purchase_from_invoice | Binds extracted, exactly matched lines to an owned chat image. Validates units, quantities, currency, active product/supplier IDs and invoice total. Owner review saves a purchase draft, never a posted purchase. |
| check_daily_closing | Business-local completed movements by exact payment method. Opening cash and counted cash must be supplied together for a drawer difference. Missing module coverage prevents a definitive drawer comparison; no closing record or bank reconciliation is written. |

Invoice extraction uses the image context already available to Wyne Wyne; the tool
validates the proposed structured data and never guesses unclear image content.
Unmatched/ambiguous lines, discounts/tax not represented in supported purchase
lines, and total/currency mismatches require clarification. Cash payment is not
inferred from an invoice: the native form initially has paid amount zero.

The existing chat action review includes **Invoice purchase draft**, checkbox,
**Confirm changes**, **Cancel proposal**, and **Close review**. Confirmation creates
one durable private `ai_purchase_drafts` record and reports that stock/payments
remain unchanged. **Open purchase draft** opens the existing New Purchase form
with supplier, lines, units, quantities, invoice costs and note prefilled. Only
its existing **Submit Purchase** (desktop) / **Save purchase** (mobile) posts the
purchase. The model has no purchase-posting tool. Drafts are retrieved through
owner-only `GET /api/ai-assistant/purchase-drafts/{uuid}`; changed product units or
unavailable products block loading rather than silently changing quantities.

Navigation carries only a draft UUID in the authenticated history entry. Mobile
binds that value to its history index so subsequent ordinary New Purchase flows
do not inherit an old invoice. Source chat and native form stay mounted across
browser Back/Forward. Cancellation and the existing purchase wizard remain intact.
The new internal action operation is excluded from generic model action schemas;
only the dedicated invoice tool prepares its image-bound inputs.

Verification: backend regressions cover tenant isolation, stale roles, disabled
modules, read-only SQL, schema registration, stock/FOC/reservations, voided sales,
account directions, closing inputs/timezone, rounding/delivery fees/orphans,
reviewed corrections, invoice validation/staleness/idempotency and private HTTP
access. The browser fixture `scripts/check-ai-invoice.cjs` intercepts all writes,
checks Review/checkbox/Confirm/Open, native supplier/line/payment prefill, wizard
Continue through final review, and Back/Forward with retained native note and chat
without initial reloads. Screenshots are in `artifacts/ai-invoice`. Posting was not
performed against live business data; normal Purchase Save behavior is covered by
existing purchase tests. Android hardware Back was not separately exercised in
this browser fixture. Desktop and mobile production builds were regenerated.


### Trial/subscription eligibility for Wyne Wyne (2026-10-25)

`AiAssistantEntitlement` checks the signed-in user's business through the existing
`SubscriptionService::status` on every new conversation/message request. Access
requires `is_valid` and a classified `trial` or `paid` subscription. Missing,
expired and not-yet-started subscriptions receive HTTP 402 with
`assistant_subscription_required`, before uploads, message/run creation or AI
credit usage. The existing subscription service's valid-period rules remain the
source of truth; mutation restrictions such as a cancelled period's read-only
status remain separate and unchanged.

The config response includes safe `subscription_access` metadata. Desktop and
mobile disable composing, attachments/retries and AI change forms when access
is denied; an owner can open Billing, while staff are directed to their owner.
History stays readable. Subscription-entitlement events refresh config without
clearing drafts. A stale open session is still blocked by the server and updates
its local entitlement from the denial response. Weekly quotas, global AI credit
and owner/staff permissions continue to apply independently.

Paid receipt validation through the chat API also requires eligibility. Expired
owners can renew through the native Billing page; this policy does not block
native renewal proof submission or Office review. Existing public knowledge MCP,
external account/billing MCP and platform SystemAgent integration authentication
are unchanged; this policy governs the in-app MKPOS assistant's AI access.

Verification covers actual trial/paid boundaries, future/missing subscriptions,
business isolation, expired request denial without provider calls/charges and
renewal recovery. Browser fixtures in `scripts/check-ai-subscription.cjs` check
owner/staff desktop and mobile, disabled fields, owner Billing navigation and Back,
reactivation and retained drafts, with all API traffic intercepted. Final builds
were regenerated. Android hardware Back was not separately exercised.

### Owner-approved POS and online-shop management (2026-10-25)

`BusinessManagement` is the new application skill. `BusinessManagementTool`
registers 18 additional owner tools when the management-audit migration is
installed. That phase exposed 69 assistant tools before the Sales AI management additions below;
registration varies with installed features. These are in-app Laravel AI tools,
not public unauthenticated MCP endpoints.

| Tools | Supported behavior |
| --- | --- |
| `get_price_type_rules` | Read configured types/rules without creating defaults or updating timestamps. |
| `get_business_management_records` | Bounded, scoped record lookup: products, contacts, financial records/lines, shop settings, receiving/delivery entries, catalog, orders/requests, promotions, buyers, reviews and audit references. No credential fields or arbitrary tables. |
| `manage_price_types` | Create/update/rename/delete types and rules. Wholesale 15% and Retail 20% are cost markups. Preview every changed product price; explicit `apply_to_existing=true` is required to replace manual overrides. Last-type and active-use deletion safeguards remain. |
| `manage_sales`, `manage_purchases` | Prepare real transaction create/update/delete, preserving omitted fields and showing line items, stock, original credit, purchase-cost and automatic-price effects. Native posting and protected-action PIN checks execute only after owner confirmation. |
| `manage_account_payments` | Customer/supplier settlement create/update/delete with amount, direction, account identity and current balance effect. Original invoice credit is not rewritten. |
| `manage_inventory` | Signed base-unit stock adjustment with reason; protects online reservations. |
| `manage_business_records` | Archive/restore products, customers and suppliers; delete expenses through native PIN protection. Existing create/update record tools remain available. |
| `manage_business_timezone` | Review a supported IANA timezone change without rewriting stored timestamps. |
| `manage_shop_settings` | Partial shared business/storefront identity, order acceptance, marketplace visibility, opening hours, announcements and pickup instructions. Existing order links retain their slug. |
| `manage_shop_contacts`, `manage_shop_delivery_zones`, `manage_shop_payment_methods` | Public-contact, delivery-zone and manual receiving-method CRUD; preserve last-active method and entry limits. These are public receiving details, never gateway credentials. |
| `manage_shop_catalog` | Explicit product publication, online unit and description updates; validates active owned products and online price/unit bounds. |
| `manage_shop_orders` | Native transitions, reopen, cancellation/return request resolution, full payment confirmation/rejection and refund recording. Shows resulting states and affected stock/reservations. Completion posts one linked POS sale. No bank transfers. |
| `manage_shop_promotions` | Single-product flash-sale and coupon create/update/delete, business-local schedules, usage/quantity limits and overlap checks. Multiple products can use separate reviewed proposals. |
| `manage_shop_customers` | Block/unblock and explicitly link/create a POS credit-contact identity. Linking never creates a debt or merges balances. |
| `manage_shop_reviews` | Publish/change/remove the exact reviewed public reply. Customer reviews themselves cannot be fabricated or deleted. |

Every mutation uses the existing durable proposal flow: authenticated owner and
business scope, current mutable subscription/trial and enabled modules,
immutable payload and preview hash, 30-minute expiry, complete review and explicit
in-app approval, locked baseline recheck, native operation and audit in one
transaction. Replays return the saved result without posting again. Cancelled,
expired or stale proposals cannot save. A typed chat "yes" or model-provided flag
never approves an action. Pending proposals are limited to ten per conversation;
review pagination is bounded to 5,000 rows.

The owner form renders dense before/after values and scrollable transaction line
tables on desktop and mobile. Deleted-record details remain visible. Protected
operations display a password PIN field; it is sent only to the authenticated
confirmation endpoint and is not in model arguments, proposal payloads, audits,
chat messages or retained form cache. PIN input clears on submit, close and
review remount. Shop payment/refund and goods-return confirmations have separate
checkboxes checked only by the owner. Screenshots do not establish bank funds.
The actual saved owner location remains required for shop management.

Account security, passwords/PIN configuration, staff/role assignment and platform
admin approvals remain excluded. Native device/printer setup, GPS consent, media
uploads, backup/restore/reset and customer chat continue through the appropriate
app screens; the agent must supply an existing page button for those workflows.
This suite does not silently automate external messages, bank transfers, device
actions or arbitrary database repairs.

Install the additive audit table with
`php artisan migrate --path=database/migrations/2026_10_25_000017_create_ai_management_audits.php --force`.
It was applied locally; no live business setting, financial, stock or shop record
was changed during verification. The read-only installation check is
`php scripts/verify-ai-management.php` from the parent MKPOS directory.

Verification: `AiManagementTest` exercises the actual native POS/shop controllers
on a tenant-aware SQLite connection, price-rule math/manual overrides, no writes
before approval, wrong/correct PIN, funds/goods gates, stock/FOC, purchase costs,
settlements, catalog/settings/contact/zone/method CRUD, promotions, customer
controls, reply removal, scoping, role/module/subscription rechecks, replay and
SDK schema/tool exchange using intercepted provider HTTP. Existing assistant,
action, advisor, entitlement and business-read suites remain covered.

Button/navigation record (`scripts/check-ai-management.cjs`, all APIs intercepted):
Review opens the exact proposal; Close review retains the draft and returns focus;
review checkbox gates Confirm; PIN/funds/goods fields gate protected actions;
Confirm submits once, clears the PIN on failure and permits a reviewed retry;
Cancel proposal saves nothing; desktop Maximize clears PIN while retaining the
review; desktop app-bar Back returns to Products; mobile app-bar/browser
Back/Forward retains the mounted Products page, conversation and draft without
an initial source reload. Older/load-more/status-recovery controls retain their
existing unit coverage. Rendered 393px mobile and 1440px desktop screenshots were
inspected: compact spacing and touch targets remain, with horizontal scrolling
inside line tables rather than page overflow. Android hardware Back, physical
keyboard behavior and real bank/provider verification were not exercised.

Final verification: 97 backend tests (1,532 assertions), 23 desktop UI tests and
9 mobile UI tests passed; targeted ESLint and both Laravel production builds
passed. Browser fixtures also checked long mixed Myanmar/English product names.
Price-type renames explicitly review and update the selected online-shop price
reference, avoiding a broken catalog reference. Voiding a linked online sale
requires the owner's physical-return confirmation and previews the resulting
refund obligation; recording the refund remains a separate approved action.

### Owner-approved Sales AI management (2026-10-26)

Wyne Wyne additionally exposes seven owner-only tools: `get_sales_ai_settings`,
`get_sales_ai_activity`, `manage_sales_ai_settings`, `manage_sales_ai_tools`,
`manage_sales_ai_policies`, `manage_sales_ai_handover`, and
`manage_sales_ai_topups`. Reads are scoped and bounded; all mutations use the
existing owner review/hash/version/audit flow. Top-up management is cancellation
of pending requests only; credit and Office payment approval remain excluded.
See [Sales AI implementation](sales-ai.md) for schemas, effects and verification.
