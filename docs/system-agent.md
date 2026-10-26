# System agent: owner payment proof validation

Business web and mobile owners trigger this agent by pressing **Submit request** in their payment form. There is no agent chat page. The server checks the private screenshot before creating a subscription request. Every accepted submission remains pending manual Office review and approval.

## Submission flow

`POST /api/subscription/requests` validates the selected public plan, receiving account and image, then calls `OwnerPaymentValidation`. The form displays “Checking payment proof…” while waiting. Wrong amounts/accounts, unclear or incomplete receipts, non-successful payments and missing transaction IDs return a screenshot field error. The form keeps the owner's selections, proof and note. Failed checks do not create a request or replace an existing pending request.

The `SystemAgent` extracts visible receipt fields using `deepseek-flash` vision through the Laravel AI SDK. Private JPEG, PNG and WebP files are sent inline; arbitrary remote URLs and file paths are not accepted. Screenshot text is untrusted. The prompt reuses the customer-service MCP's `mkpos_payments` recognition note and KPay, WavePay and AYA Pay profiles. Deterministic comparisons check the selected price, currency, recipient owner name followed by the last four account digits, provider, completed status and transaction reference. Provider aliases, Myanmar digits, wallet-only +95/0095 phone formatting and outgoing debit amount displays are normalized. Bank providers remain distinct from wallets, and arbitrary bank account prefixes are preserved. The recipient owner name must be visible and match (ignoring case and repeated whitespace). Only after that match are the final four account digits compared. Masked accounts such as `******3805` are accepted when their four trailing digits match the configured recipient account; full account numbers also use the same last-four rule. Missing or incorrect names cannot pass, even with matching account digits. A missing date does not block an otherwise complete receipt with a transaction ID; an invalid visible date still needs correction. Fewer than four visible trailing account digits, masked names and other unresolved required fields cannot pass. This checks screenshot consistency; it cannot verify that bank funds arrived. See [DeepSeek's vision contract](https://api-docs.deepseek.com/guides/vision/).

Recent results are cached for 30 minutes, bound to the owner, business, plan price, receiving account and image hash. Submission rechecks that binding inside a database transaction. Recorded transaction IDs and assessed image hashes prevent duplicate submissions across businesses. Retrying a successful submission returns its existing pending request without another charge. Historical unassessed receipts have limited duplicate coverage.

Transaction ID checks now share `PaymentReceiptClaims::duplicates` across
`subscription_requests` and `sales_ai_topups`, including records in other
businesses. Indexed transaction hashes and legacy raw references without hashes
are checked using the same provider-alias, punctuation/case and Myanmar-digit
normalization; leading zeros remain significant. Pending and approved Sales AI
top-ups reserve their known references. Existing subscription duplicate-history
rules remain unchanged. The approved-claim ledger also blocks reuse after a
source record is removed.

These checks run during subscription screenshot validation (including cached
results), final subscription submission, background System Agent review, Sales
AI top-up submission when a reference is supplied, and manual approval in either
flow. Top-ups with no supplied reference are checked when the reviewer enters
it. Both approval paths retain the atomic unique transaction claim before
activating a subscription or crediting a wallet; missing claim-table setup blocks
approval. Duplicate failures leave requests pending and do not activate access,
post subscription income or add Sales AI credit. Screenshots still do not prove
actual receipt of funds.

A passing check saves the extracted `transaction_id` with the pending request. Office retains its editable transaction ID field for manual approval; it has no separate pre-validation UI. The agent never approves requests, activates access, verifies bank funds or initiates payments.

## Setup and usage accounting

Apply both additive migrations after the AI balance-book migrations:

```sh
php artisan migrate --path=database/migrations/2026_10_25_000005_create_system_agent_reviews.php
php artisan migrate --path=database/migrations/2026_10_25_000006_create_owner_payment_validations.php
```

The shared AI settings must be enabled and configured with `DEEPSEEK_API_KEY`, current pricing and positive internal AI credit. Unavailable checking returns a recoverable error without submitting. The browser allows two minutes for the request; the provider call has a 90-second timeout. Submission is rate limited.

Each completed assessment, including an incorrect proof, records actual reported tokens and an idempotent cache-aware estimated debit in the internal AI balance book. Owner validations contribute to AI usage reports once; attached audit records do not double count. Provider failures may have unreported usage, consistent with the estimated balance-book policy.

No scheduler or queue worker is needed for owner submission validation. The old `system-agent:validate-payments` command and `scripts/run-payment-prevalidation.ps1` are retained only for legacy queued tasks; automatic scheduling has been removed.

## Private MCP tool

`POST /mcp/system-agent` exposes `check_payment_screenshot` with `upload_id`, `subscription_plan_id` and `payment_method_id`. It requires an authenticated business-owner MCP connection with write scope and an owned, private, unconsumed evidence upload. Office admin tokens cannot use this server. Repeated matching checks reuse the recent result; do not poll or loop paid checks.

The existing owner `submit_subscription_payment_proof` MCP action uses the same validation gate before creating a request. Paid checking runs outside the submission transaction so a rejected screenshot still records its real AI usage. The upload and final submission remain protected by ownership, expiry, idempotency and duplicate checks. Manual Office approval is always required.

Recipient matching policy version `mcp-receipt-rules-v3-name-last-four` invalidates cached pre-submission checks from the previous full-account policy. The example receipt is represented in a workflow regression with recipient `Min Htet Kyaw`, masked account `******3805`, transfer amount `30000.00 Ks` and transaction ID `01004314000892184310`; it passes only against a configured recipient with that name and account ending `3805`. This fixture mocks image extraction, and does not claim a live vision assessment or funds verification.
