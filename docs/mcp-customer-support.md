# MKPOS customer service MCP

This implements the customer-service catalogue, form-field guidance, read-only
product-import template/validation tools, and `get_mkpos_overview` for compatibility. No public endpoint can read a customer's
private account or execute administrator actions.

Administrator payment and refund write tools prepare a proposal only. Their
initial response has `status: awaiting_office_approval`, a `proposal_id`, expiry
and an `office_url`. An administrator reviews the exact action in **Office >
Payment > Assistant approvals** and explicitly approves or rejects it. The
chatbot's `confirmed` flag cannot apply the action. Proposals expire after 30
minutes and are invalidated by changes to the target payment/refund, its plan,
or the originating administrator/connection. Changed arguments require a new
idempotency key. A proposal's rejection leaves the underlying request unchanged.
For a completed proposal, inspect its nested `result` for the actual payment or
refund state. Refund approval/transfer recording never sends money through a bank.

Deploy migration `2026_10_27_000001_create_mcp_admin_proposals` with the updated
Office client and refresh tool discovery in connected chatbot hosts. HTTP MCP
requests with an Origin header must match an exact configured CORS origin;
server-to-server requests without Origin remain supported.

## Connections and tools

All MCP connections use Streamable HTTP POST. Prefix paths with the deployed
Laravel base URL (including `/public` if used by the host).

| Endpoint | Authentication | Tools |
| --- | --- | --- |
| `/mcp/knowledge` | Public, 60 requests/minute/IP | 17 tools: public usage/form/payment information, CSV template/validation and legacy overview |
| `/mcp/account` | Per-account delegated support bearer | 9 private account tools; 60 requests/minute/actor |
| `/mcp/admin` | Per-administrator delegated support bearer | 5 administrator tools; 30 requests/minute/actor |
| `/mcp/subscription-payments` | Existing server-to-server secret | Existing read-only pending-payment notification integration; unchanged |

Public tools:

1. `get_account_guide` — required `operation`: `login`, `register`, `password_reset`;
   optional `app`: `mkpos` (default) or `shop`.
2. `get_platform_links` — owner web/mobile, Shop and available Windows/Android links.
3. `get_video_tutorials` — current published Office tutorials.
4. `search_help_articles` — optional `app`, `query`, `limit`, `offset`.
5. `get_help_article` — required `article_id` from search results.
6. `get_troubleshooting_guide` — required `topic`: `login`, `checkout`, `429`,
   `printer`, `offline`, `barcode`, `notifications`, `permissions`, `product_form`,
   `cart`, `address`, `orders`, `chat`, `language`, `updates`.
7. `get_supported_devices` — documented platform/printing capabilities and limits.
8. `get_latest_app_versions` — published release records and file availability.
9. `get_service_status` — local DB observation and explicitly maintained components.
10. `get_support_contacts` — current official contacts, not a sent handoff/ticket.
11. `get_available_package_plans` — active public, non-system packages.
12. `get_subscription_payment_methods` — current active subscription recipients.
13. `get_payment_screenshot_instructions` — existing matching/forwarding instructions.
14. `get_form_field_guide` — explain a form or one field; see examples below.
15. `get_mkpos_overview` — retained compatibility tool.

### Form-field explanations

`get_form_field_guide` is public/read-only and never receives actual form values.
Its optional inputs are `app` (`business_web` default, `business_mobile`, `windows`,
`shop`), `form`, `field`, `mode` (`create` default or `edit`) and `language` (`en`
default or `my`). Omit `form` to discover forms for that app. Omit `field` for all
documented fields; specify an API key or exact English/Myanmar display label for
one field. Indexed array fields such as `items.0.quantity` resolve to
`items.*.quantity`. Create/edit mode changes applicable field labels; it does not
grant permissions or make a registration screen editable.

```json
{"app":"business_mobile","form":"product","field":"purchase_conversion_factor","language":"my"}
```

The returned explanation describes how a box of 12 base units uses factor 12,
that an explicit purchase unit requires a factor greater than one, and that
omitting the purchase unit uses effective factor 1.

```json
{"app":"shop","form":"checkout","field":"address","language":"my"}
```

This explains the delivery-address requirement and maximum length, without
requesting the customer's real address in chat. Other fields include the app's
current-quote and duplicate-submission identifiers, marked `system_managed` rather
than asking a user to invent their values.

Documented business forms: `login`, `register`, `password_reset`, `profile`,
`product`, `stock_adjustment`, `customer`, `supplier`, `expense`, `purchase`, `sale`,
`subscription_request`, `refund_request`. The refund entry describes support tool
fields, not an existing app refund screen. Documented Shop forms: `login`,
`register`, `password_reset`, `profile`, `address`, `checkout`.

Responses contain localized labels/descriptions, `requirement` (`required`,
`optional`, `conditional`, `system_managed`), documented validation rules, safe
fictional examples, related-field notes, available field keys and source links.
Unknown forms/fields return an explicit `form_not_documented`/`field_not_documented`
status with documented alternatives; the agent must not guess. Passwords, reset
codes, PINs and private payment proof have no example and are marked sensitive.
Fields are documented against the reviewed application validators; dynamic stock,
permissions, uniqueness and availability still require actual app validation.
Maintain `McpFormFieldGuide` when changing those contracts. No DB access, customer
lookup, submission or new migration is used by this additional tool.

Private account tools:

| Tool | Inputs beyond optional `language` |
| --- | --- |
| `get_my_subscription_status` | None |
| `list_my_subscription_requests` | Optional `limit`, `offset` |
| `get_my_subscription_request` | `request_id` |
| `get_my_billing_history` | Optional `limit`, `offset` |
| `submit_subscription_payment_proof` | `subscription_plan_id`, `payment_method_id`, `upload_id`, optional `message`, write confirmation fields |
| `get_my_profile` | None |
| `update_my_profile` | `name`, optional Shop `phone`/`address`, write confirmation fields |
| `request_refund` | `payment_id`, positive integer `amount` in the original payment currency/units, `reason`, write confirmation fields |
| `get_my_refund_status` | `refund_id` |

Shop customers can access only their profile tools. Subscription tools require a
linked business owner. Owner profile editing is name-only and requires
`profile_write` scope after password verification in the trusted app. Email,
password, business ownership and other customers' records are not editable here.
Missing Shop phone/address fields preserve existing values. To clear them send an
empty string. The existing profile service updates the default saved address.

Administrator tools:

| Tool | Inputs beyond optional `language` |
| --- | --- |
| `get_pending_subscription_payments` | Existing optional `limit` (1–50), `after_id` |
| `approve_subscription_payment` | `request_id`, exact `price_paid`, `admin_note`, `funds_verified: true`, write confirmation fields |
| `reject_subscription_payment` | `request_id`, `admin_note` (at least 5 characters), write confirmation fields |
| `get_pending_refund_requests` | Optional `limit`, `offset` |
| `process_refund_request` | `refund_id`, `action`: `approve`/`reject`/`record_transfer`, `review_note`; transfer action also needs `transfer_reference`, `transferred_at`; write confirmation fields |

All tools accept `language: en|my` (default English). New public usage tools accept
`app: business_web|business_mobile|windows|shop`. Written owner guides are read from
the existing documentation views, with curated Shop guides and Myanmar search
keywords in `config/mcp_support.php`; answers can translate faithfully. These
guides are not video transcripts. New paginated tools use `limit` 1–20 (default 5),
`offset` and `has_more`/`next_offset`. Never poll exhausted pages.

## Account linking and trusted host integration

The private connections use **MKPOS delegated bearer authentication**, not an
advertised OAuth discovery flow. A trusted application/host obtains a token from
an already authenticated MKPOS session; it must not ask a customer to paste their
password, OTP, existing app token or delegated token into an AI chat.

| Signed-in identity | Token issuance endpoint |
| --- | --- |
| Business owner | `POST /api/mcp/access` |
| Shop customer | `POST /api/ecommerce/customer/mcp/access` |
| Office administrator | `POST /api/office/mcp/access` |

Use the existing session/CSRF or app bearer authentication of that API. Body:

```json
{"consent":true,"allow_writes":false}
```

For customer-approved mutations use `allow_writes: true`. An owner can optionally
reauthenticate with `current_password` **inside the trusted application only**;
correct verification adds `profile_write` scope. The bot must never request or
receive that password. Tokens expire after one hour, are stored hashed, bound to
actor type/ID, owner business and password version, and reject inactive accounts, changed passwords,
changed owner roles and inactive businesses. Read-only grants cannot mutate data.

The trusted host keeps the returned `access_token` outside model context and uses
`Authorization: Bearer ...` on its corresponding MCP connection. Maintain a
separate authenticated connection per linked Telegram user. Do not configure a
single owner's token globally for all chatbot users. The identity and business
come only from that connection; tools reject identity/business arguments.

Revoke an account token with `DELETE /mcp/access`, or an administrator token with
`DELETE /mcp/admin/access`, using that token's bearer header. The private admin
connection must be restricted to verified administrators, never customer chats.
The existing payments notification secret cannot authorize writes.

## Writes, evidence and refunds

Every new mutation requires `confirmed: true` and a UUID `idempotency_key`. The
host must explain the actual proposed operation and obtain the corresponding
customer or administrator confirmation before calling it. A boolean passed by an
agent is not independent evidence of a human's identity or consent; the host is
responsible for presenting the confirmation. The server enforces identity, role,
scopes and state transitions.

Reuse the same key and arguments on uncertain retries. A changed payload with a
used key is rejected. Mutation and audit/result storage commit together; successful
replays return the original result without repeating approval, billing or submission.
Failed transactions can retry the same key. Do not generate a fresh key merely
because a response timed out. Keep `mcp_support_actions` for replay/audit history.

For proof submission the trusted host uploads the original image via multipart
`POST /mcp/evidence`, bearer authenticated as that owner, file field `file`, JSON
Accept header. Only valid JPG/PNG/WebP images up to 5 MB are accepted. Pass its
opaque `upload_id` to `submit_subscription_payment_proof`. Uploads expire after
one hour, belong to one owner and can be consumed once. Arbitrary URLs, local
paths and another customer's uploads are not accepted. The submitted request uses
the current active recipient snapshot and existing replacement/Office notification
rules. Proofs remain private and are never considered verified money by themselves.

Subscription approvals/rejections reuse the existing Office controller, including
the activation, financial record, notification and email behavior. Administrators
must verify actual funds independently and provide exact amount and review note.

Refunds here are **subscription payment refunds**, not Shop order refunds. Pending,
approved and transferred requests reserve the original payment amount, preventing
aggregate over-refunds; rejection releases that reservation. Review states:

```text
pending -> approved -> transferred
pending -> rejected
approved -> rejected
```

`record_transfer` records a completed external transfer; it does not call a bank
API. It requires a globally unique transfer reference and a completion time between
request creation and now. Approval alone does not report a transfer. Existing billing
amounts and subscription access are preserved; no automatic cancellation occurs.

## Deployment and verification

1. Deploy the changed Laravel source and the new migration. Existing 2.0.5 release
   archives were built before this feature and have not been overwritten.
2. Run `php artisan migrate --force`, then rebuild normal route/config caches.
3. Keep the existing scheduler running. `mcp:support-prune` runs hourly, removing
   expired tokens and unused expired uploads, preserving submitted evidence/audits.
4. Maintain verified incidents, web versions and support hours in
   `config/mcp_support.php`. Defaults are **unknown**, not a service-health promise.
5. Refresh OpenClaw MCP discovery/tool filters. Configure per-user delegated
   connections in the trusted host before enabling private tools in Telegram.
6. No frontend APK/EXE rebuild or socket-server update is required for this backend
   feature. Hosted OpenClaw/Telegram configuration is not changed by source edits.

Run isolated regression tests:

```shell
php artisan test --compact --filter='Mcp(Knowledge|SubscriptionPayments|Support)Test'
```

The tests use in-memory SQLite, private fake storage and intercepted side effects.
They cover discovery, real guide data, current recipients/releases, Shop recovery,
scopes, account isolation, expired/revoked links, proof ownership, confirmation,
idempotency, financial/activation rollback, refund reservation/transitions and cleanup.
No live business records, Telegram messages or bank transfers are used. Production
MySQL concurrent requests and an actual hosted per-user OpenClaw connection still
require deployment smoke testing.
