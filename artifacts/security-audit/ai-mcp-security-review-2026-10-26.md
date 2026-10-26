# MKPOS AI agent and MCP security review

Date: 26 October 2026 (Asia/Rangoon)

## F3 remediation update

F3 has been fixed in source. `AiConversationAccess` enforces current stored-role
checks and conversation classification across listings, messages, images,
delivery receipts, sends, retries, SDK history and reply delivery. The additive
`2026_10_26_000002_protect_ai_conversation_history` migration defaults existing
conversations to owner-only; newly created staff help conversations remain
usable. Owner use upgrades a staff conversation before processing. Access is
rechecked after generation so an in-flight role downgrade cannot receive the
private reply. The two original F3 audit reproductions now assert denial and no
provider request. Dedicated regression coverage is in `AiAssistantTest` and
`AiAssistantAccessTest`. The findings and results below record the initial audit,
before this remediation; other findings have not been addressed by this fix.

Remediation verification: 175 tests and 2,474 assertions passed across the
targeted assistant/business/system suites and updated audit checks. The additive
migration was applied to the verified local `mkpos` database on loopback; its
privacy column exists and the message count was unchanged. See
`history-privacy-fix-results.txt` for commands and individual totals.

## Scope and result

Reviewed the workspace source for `MkposAssistant` (Wyne Wyne), `SalesRepresentative`, `SystemAgent` (receipt extraction and themes), their tool services, confirmation routes, credentials, accounting, and the five HTTP MCP servers. This assessment concerns the current working tree, which already contained substantial uncommitted work. No application implementation was changed.

Found three reproduced application weaknesses, one reproduced MCP protocol hardening gap, and a significant conditional risk in MCP approval design. Prioritize payment-check resource limits and independent authorization of sensitive MCP writes.

Tests used SQLite memory, fake files and mocked HTTP/AI responses. No real provider calls, financial transactions, Telegram messages, production migrations, or live penetration tests were performed. Hosted OpenClaw/Telegram settings, reverse-proxy rules, production HTTPS and network restrictions were not assessed. No successful real-model prompt-injection attack is claimed.

## Confirmed findings

### F1 — High: payment checking has no actor spending quota or concurrency cap

Affected: `app/Services/OwnerPaymentValidation.php:28`, `app/Mcp/Tools/CheckPaymentScreenshot.php:32`, `app/Services/McpSupportTools.php:152`, `routes/ai.php:24`.

An authenticated active business owner with a write-scoped MCP token can invoke paid screenshot extraction repeatedly without submitting a subscription request. The check service does not consult `AiWeeklyQuota` or enforce a payment-check budget. Its lock and 30-minute cache are keyed by owner/business/plan/recipient/image hash, so changing an image creates another independent lock and charge. There is no actor-wide limit on concurrent extractions.

Reproduced: with the fixture's weekly limit set to zero, two different private image uploads both passed `check_payment_screenshot`, produced two completed validation records and two usage-ledger entries, and created zero subscription requests. Model extraction was mocked; this tests authorization and accounting, not receipt authenticity.

Impact: an abusive owner or compromised write connection can consume shared platform AI funds and provider capacity, affecting other tenants. Existing throttles (60 account MCP requests/minute and 10 evidence uploads/minute per actor) slow the attack but do not establish a spending ceiling. Cached identical images help ordinary retries but do not contain deliberate variation.

Recommendation: introduce a separate payment-validation quota and daily monetary allowance per owner/business, enforced atomically inside `OwnerPaymentValidation` so every entry point is covered. Allow only a small number of in-flight checks per actor. Reserve estimated cost before contacting the provider. Keep a legitimate renewal allowance for expired subscriptions; do not blindly reuse a subscription gate that prevents renewal.

### F2 — Medium: the shared AI balance is a soft stop, not a reserved budget

Affected: `app/Services/AiBalanceBook.php:17`, `:72`, `:95`; callers include `OwnerPaymentValidation`, `AiAssistantController`, `PlatformTheme` and Sales AI `Runner`.

`funded()` checks only that `balance_nanos > 0`. Provider execution occurs after this check, and later charging subtracts actual usage even if the resulting balance is negative. A row lock during the check is released before the provider call, without reserving funds. Multiple actors can therefore pass the same balance check while earlier work is in flight.

Reproduced: two funding checks both passed against a balance of one nanodollar; one ordinary fixture usage charge then made the balance negative. This directly confirms the lack of a hard cost ceiling. A concurrent production load test was not performed. Sales AI reserves its business wallet, but this does not reserve the shared provider balance.

Impact: the internal balance cannot guarantee the maximum provider spend. F1 increases this exposure. Actual external billing limits depend on the provider account, which was not inspected.

Recommendation: atomically reserve a conservative per-run cost in the shared account before dispatch, then settle actual usage and release the remainder. Apply reservations to all agent types. Also configure an external provider spending ceiling where supported. Continue accounting for real incurred costs even when a response is suppressed or settlement fails.

### F3 — Medium: old owner-only conversation data survives a role downgrade

Affected: `app/Http/Controllers/Api/AiAssistantController.php:39`, `:122`, `:240`; `app/Ai/Agents/MkposAssistant.php:70`.

Conversation ownership is checked by user and business, but historical message reads do not reevaluate whether the current role may see previously retrieved owner-only business facts. `send()` also loads completed history and sends it to the provider after the same account becomes staff. The system prompt tells the model not to repeat private history, while the raw data remains accessible.

Reproduced: seeded an owner conversation with a private marker, changed that account's stored role to staff, and authenticated as the fresh staff user. The messages endpoint returned the marker despite `can_read_business_data=false`. A subsequent staff chat request also included the marker in the mocked provider's messages.

Impact: role revocation does not remove access to private AI history or prevent its further use in model context. This requires an actual owner-to-staff downgrade; this review did not find a normal staff-management UI path that performs that transition. It is not cross-user or cross-business access, and it cannot erase data the former owner already learned.

Recommendation: bind privileged conversations and attachments to their authorization level or an access-version value. On role loss, deny or redact privileged history server-side and omit it from provider context. Keep a separate restricted conversation for staff. Do not rely on a prompt to enforce historical-data authorization.

### F4 — Low: HTTP MCP endpoints do not reject an untrusted Origin

Affected: `routes/ai.php:15`; middleware in `app/Http/Kernel.php` and `vendor/laravel/mcp/src/Server/Registrar.php`.

Reproduced: `POST /mcp/knowledge` with `Origin: https://untrusted.example.test` and `tools/list` returned HTTP 200 and tool discovery. No application or installed MCP middleware checked the Origin header. CORS configuration covers API paths rather than MCP paths; CORS is not equivalent to server-side Origin validation.

The compatible [MCP HTTP transport specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports) requires rejection of invalid present Origin headers to mitigate DNS rebinding. Missing Origin should remain usable for legitimate server-to-server clients.

Impact is limited in the demonstrated case: knowledge discovery is intentionally public. No private bearer-authentication bypass, browser cross-origin response read, or complete DNS-rebinding exploit was demonstrated. Reverse-proxy protection could reduce deployed exposure and was not checked.

Recommendation: validate present Origin values against an explicit deployment allowlist, return 403 for invalid values, validate allowed hostnames, and bind local development services to loopback where appropriate.

## Conditional architecture and deployment risks

### R1 — High if the host does not independently authorize each write: MCP confirmation is model-controlled

Affected: `app/Mcp/SupportToolDefinitions.php:48`, `:66`; `app/Services/McpSupportTools.php:16`, `:202`; `app/Mcp/Servers/SupportAdminServer.php`.

MCP mutations accept `confirmed: true`; payment approval also accepts `funds_verified: true`. A write-scoped bearer plus these arguments can directly execute mutations. No action-bound approval challenge from a separately authenticated human is required by the server. A UUID idempotency key prevents accidental replays; it does not prove consent.

Reproduced: an admin write token directly rejected a pending subscription request using only ordinary tool arguments and `confirmed: true`, with no independent review challenge. This proves the server behavior, not a real prompt-injection exploit or an unauthenticated access bypass.

The repository explicitly documents this design and makes the trusted host responsible for human confirmation (`docs/mcp-customer-support.md`). A properly implemented host may enforce that requirement before dispatch. Its implementation was not available in this source review, so this is a conditional risk rather than proof that the deployed host skips approval. In contrast, the in-app owner and customer agents have separate server-backed review and confirmation routes.

Recommendation: keep financial mutation tools disabled or read-only until host enforcement is verified. Prefer immutable proposals with one-use, expiring approval authorization bound to actor, exact tool and exact arguments, issued through an authenticated human review surface. The model must not be able to mint or access that authorization. Narrow broad `write` grants into specific tool capabilities. See [MCP security guidance on scope minimization](https://modelcontextprotocol.io/docs/2025-11-25/tutorials/security/security_best_practices).

### R2 — Medium operational exposure: the payment notification integration uses one static shared secret

Affected: `app/Http/Middleware/AuthenticatePaymentsMcp.php:13`, `config/mcp_payments.php`, `routes/ai.php:35`.

The legacy payment-notification endpoint authorizes with one environment-provided bearer secret. The middleware has a length check and timing-safe comparison, but no per-client identity, built-in expiry or individual revocation. That credential reads pending payment metadata across businesses. It cannot authorize support-admin writes.

No secret was printed, copied or tested against a live endpoint. Secret quality, storage at the host, rotation practices and network controls remain unverified. A static secret is not automatically a vulnerability, but compromise has a broad read scope and lasts until rotation.

Recommendation: use separate expiring service credentials restricted to payment notification reads, add client-attributed audit logs, and verify rotation plus network restrictions on this endpoint.

## Controls that were present

- Private support tokens are random, stored as hashes, expire after an hour, and bind to actor, password version and (for owners) business. Resolution checks active accounts, current owner role and active business.
- MCP account/admin audiences are separated; read-only scopes cannot perform writes. Most private record queries explicitly scope owner/business/customer.
- In-app owner and Sales AI tools prepare proposals; authenticated app endpoints enforce review hashes, current state and confirmation. Model arguments do not directly confirm those in-app proposals.
- Receipt extraction does not establish bank settlement or automatically activate subscriptions. Private images use owned uploads; arbitrary model-supplied URLs and local paths are not accepted.
- The reviewed tools expose no generic model-controlled shell or SQL executor. Provider credentials are configured server-side; saved keys are encrypted.
- Owner assistant Markdown disables HTML and images, and filters navigation destinations. These controls reduce automatic output-based execution/exfiltration risk.

These are observed controls, not a claim that every path is vulnerability-free.

## Verification and artifacts

Existing targeted suite: **140 tests, 1,764 assertions; 139 passed and one failed**. Covered MCP knowledge/support/payment/import, assistant access/actions/chat and system payment review. Sales AI customer tool behavior was reviewed in source; its existing integration tests were not run against the workspace database.

The failure was `McpSupportTest::test_manual_office_approval_saves_the_reviewed_transaction_reference`: HTTP 503 because its in-memory fixture omits the `payment_receipt_claims` migration. A diagnostic reproduction confirmed the exact safeguard error and then successfully approved after applying that migration only inside the in-memory fixture. This is a test-fixture problem, not evidence that the production migration is absent. The existing test was not modified.

Audit reproductions: **7 tests, 29 assertions, all passed**, meaning the described current behaviors were reproduced. They are observation tests, not assertions that the application is secure; change their expected outcomes when implementing fixes.

```powershell
php vendor/phpunit/phpunit/phpunit --no-progress --filter test_audit artifacts/security-audit
```

Tests: `AgentBoundaryAuditTest.php`, `McpBoundaryAuditTest.php`. Recorded result: `reproduction-results.txt`.

`composer audit --locked --no-interaction --format=json` returned empty advisories and abandoned-package lists. Reviewed locked versions include Laravel framework v12.70.0, laravel/ai v1.3.0 and laravel/mcp v1.1.1. An empty advisory database result is not proof that these dependencies have no vulnerabilities.

Remaining deployment checks: inspect host approval enforcement and per-user connection isolation; confirm HTTPS, Origin/Host rules and network bindings at the proxy; inspect production service credentials and spending limits without exposing their values; verify migrations and scheduler operation; run tenant-isolation and adversarial-input checks in a staging deployment with synthetic data.
