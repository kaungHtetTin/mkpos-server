# Wyne Wyne workflow skills

## Business Sales AI Telegram management

The existing owner-only `sales_ai` workflow now includes four Telegram tools:

| Tool | Supported behavior |
| --- | --- |
| `get_sales_ai_telegram_settings` | Read saved reply/group switches, limits, bot username, registration and token-configured flags. Does not initialize a connection, expose credentials or claim live health. |
| `get_sales_ai_telegram_groups` | Read/search/paginate only the current business bot's groups, approval, actual saved membership, triggers and limits; resolve exact record IDs. |
| `manage_sales_ai_telegram_settings` | Prepare a partial change to replies, group replies and daily/per-user limits, preserving all unspecified settings. |
| `manage_sales_ai_telegram_groups` | Prepare a single group's creation or partial update, approval/disable, mention/reply triggers or limits. New groups need the owner's exact negative Telegram chat ID and name. Existing chat IDs and membership are immutable through AI. |

Management uses the existing before/after review cards in Business web and mobile. Nothing changes until the authenticated owner reviews and confirms. Confirmation rechecks ownership, current connection/bot version and group version/membership. Stale proposals fail; retries do not repeat a saved change. Native Telegram configuration validation is reused, and changes enter the existing management audit. Confirmed changes reconcile open settings/list views while preserving unsaved edits and private token drafts.

Token entry/replacement, connection tests and webhook registration use the owner-only `sales_ai_telegram` navigation button to **My Shop → Sales AI → Telegram bot**. Wyne Wyne must never ask for a bot token in chat or send Telegram messages, alter membership/webhook destinations, link customer accounts, approve every discovered group, expose customer histories or manage Kaung Kaung's platform bot through these tools. Group approval is distinct from bot membership, the global group-reply switch, registered webhook, worker health and AI credit. Saved flags alone do not prove service availability.

Examples: "Show my Sales AI Telegram groups", "Prepare approval for this group", "Disable Telegram group replies", or "Set Telegram daily requests to 300". These extend the existing Sales AI skill; no new migration, cron or worker is introduced. Restart persistent workers after deploying the PHP changes; bounded cron invocations load updated code on their next run.

Wyne Wyne now includes a 17-topic workflow registry and a read-only get_wyne_wyne_skills tool. This organizes existing supported MKPOS capabilities; it does not add unsupported application features or grant new permissions.

| Topic | Workflow |
| --- | --- |
| getting_started | Setup progress, readiness, one next step and official feature guides |
| navigation | Find supported pages, explain fields and return permission-checked buttons |
| daily_operations | Business summary, local-day closing and recorded cash movements |
| sales_analysis | Period reports, comparisons and evidence-backed explanations |
| inventory | Current units/stock, reorder suggestions and slow-moving products |
| purchasing | Supplier records, purchases, costs/units and reviewed purchase changes |
| credit_accounts | Customer receivables, supplier payables and actual payment recording |
| expenses | Expense reports, categories and cash-movement distinctions |
| pricing | Saved selling prices, markup/margin, unit conversion and rule checks |
| data_quality | Bounded integrity checks, issue explanations and owner-selected safe corrections |
| management | Exact owned records, partial changes and app-reviewed proposals |
| online_shop | Actual order states, settings and native fulfillment/payment rules |
| sales_ai | Settings, tool permissions, published policies, activity, credit and scoped handover |
| billing | Current plans, active platform recipients, billing forms and manual Office approval |
| imports | Real CSV template/staging validation, errors and app-confirmed import |
| images_support | MKPOS image relevance, safe error explanation and actual support escalation |
| security_devices | Public account/device/printer/backup instructions and permitted navigation |

The all topic returns a compact index; a specific topic returns its workflow. Returned available_tools are intersected with tools actually exposed to the agent. The current database role, active business/session, tenant and enabled modules are rechecked for each request. Staff/restricted sessions receive only public guidance and navigation, never owner workflow guides or private data. Individual business tools still enforce their module/subscription/ownership checks.

Business figures require current server evidence, business-local periods, currency, units and scan coverage. Warnings are not confirmed errors; missing records are not zero activity; outstanding balances are not automatically overdue. Revenue, profit, recorded cash and counted cash are distinct. Guidance does not authorize writes: exact requested changes still require native owner review and confirmation. No role/credential changes, bank transfers, automatic payment approval, shell execution, backup restoration, tax/legal guarantees or scheduled reminders are introduced.

The existing image policy remains in force for Wyne Wyne. Image replies use the restricted structured image reader; receipt recipient comparison is not funds verification. Unknown MKPOS image errors create the existing Office Error report. Text error reports keep their contact/submission form. This skill update does not broaden image scope to arbitrary product/invoice uploads or let image turns run business mutations.

Deployment: upload the backend patch and run php artisan optimize:clear. Restart persistent workers; bounded cron workers read updated code on their next invocation. This update adds no new migration, cron entry or Office build. If the earlier image-policy migration has not been applied, follow the cumulative package deployment instructions first.
