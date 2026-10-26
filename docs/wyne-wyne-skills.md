# Wyne Wyne workflow skills

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
