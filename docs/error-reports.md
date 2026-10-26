# MKPOS error support and Office reports

The in-app assistant has an `ErrorSupport` workflow skill. It reads the owner's screenshot as evidence, checks existing MKPOS guides and permitted business context, and gives a focused safe correction. Supported business/settings changes still go through the existing Review and Confirm changes UI. The assistant must not clear data/offline queues, retry financial transactions, bypass permissions or claim an unverified fix.

When the problem requires code, infrastructure or administrator access, or an attempted correction fails, the assistant prepares an error report and asks how admins should contact the owner before submitting to Office with the observed problem, expected result and actual steps attempted. This is a support submission, not approval to change business records. The owner receives the saved `ERR-000001` style reference only after successful storage. No external email, Telegram or chat service is used.

## Agent tools

- `get_error_support_guide`: troubleshooting/escalation instructions and up to three recent screenshot message IDs/names in the current owned conversation.
- `submit_error_report`: prepares a persistent owner contact request (no Office report yet), using required `title` (160 characters) and `description` (4000), optional `expected_result` (2000), `attempted_steps` (3000), and up to three relevant `screenshot_message_ids`. Omitted screenshot IDs attach only the current message image. Older images require explicit selection; arbitrary URLs, file paths, owners and businesses cannot be supplied.
- `get_my_error_reports`: optional `report_id`, or `offset`/`limit` (maximum 20). Returns only this owner's report references, status and public resolution, excluding Office private notes and screenshots.

The tool returns `awaiting_owner_contact` and `request_id`. The owner uses **Contact and submit** to choose Facebook, Telegram, Viber or phone and enter their reachable account, link or number. An explicit **Submit without contact details** option is available. Only the authenticated owner form supplies or skips contact; model-provided contact values are ignored. No delivery or report number may be claimed before the form succeeds. Desktop shows the form in the chat; mobile uses `/assistant/error-reports/{requestId}` with retained chat and draft on Back. Pending requests reappear when reopening chat or refreshing. Office report details show the saved channel and contact as plain text.

Tools are available only to owners. Each call rechecks the stored active owner/business. The conversation and current user message come from the running server-side agent. Submission is idempotent per user message and does not incur an additional AI inference. The bounded tool loop allows six steps for context, diagnosis and escalation. Screenshots and reports are untrusted content, not instructions. Missing facts, attempted actions and successful submissions must never be invented.

## API

Owner routes, under normal authenticated business/owner middleware:

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/api/ai-assistant/error-report-requests/{draft}` | Own pending report summary or completed reference |
| POST | `/api/ai-assistant/error-report-requests/{draft}/submit` | Submit server-held draft with explicit owner contact choice; 10/minute |
| GET | `/api/ai-assistant/error-reports` | Own report list, bounded pagination |
| GET | `/api/ai-assistant/error-reports/{report}` | Own public status/resolution |
| POST | `/api/ai-assistant/error-reports` | Submit using an owned `conversation_id` and `message_id`, plus report fields and explicit contact choice; 10/minute |

`contact_choice` is required (`provided` or `skip`). Provided contact requires `contact_channel` (`facebook`, `telegram`, `viber`, `phone`) and `contact_value` (max 255). Facebook accepts profile/Messenger HTTPS links; Telegram accepts @username, t.me link or phone; Viber/phone accept 7?15 digits with common formatting and optional + prefix. Skip stores no contact even if stale contact fields are included. Duplicate submission returns the original reference without overwriting contact. Draft lookup and submission recheck owner/business scope. Contacts are not added to AI tool history or public report status responses.

Office routes use existing Office authentication and audited mutations:

| Method | Route | Purpose |
| --- | --- | --- |
| GET/POST | `/api/office/error-reports` | Filtered/paginated list or manually create a report |
| GET | `/api/office/error-reports/businesses` | Search business choices, 20 per page with offset |
| GET/PUT/DELETE | `/api/office/error-reports/{report}` | View, edit or confirmed delete |
| GET | `/api/office/error-reports/{report}/images/{index}` | Authenticated private screenshot bytes |

Status values: `open`, `in_review`, `resolved`, `closed`. Priority values: `low`, `normal`, `high`. Office can edit the problem, expected result, attempted steps, app, status, priority, public resolution and private note. The report's business cannot be reassigned during edit. PUT/DELETE require the latest returned `version`; concurrent changes return 409. Office changes are recorded in the normal activity audit. Only public resolution is available through owner tools/APIs; `resolved` records the Office assessment, not proof that the owner verified the fix.

The Office **Reviews & reports → Error reports** page includes search, status/priority filters, shared table pagination, view/edit/new/delete modals and private screenshot viewing. Closing a modal retains the mounted list/search/page without another initial list request. Failed saves preserve the draft. Business choices are paginated and stale search responses are ignored. `#/error-reports` survives browser refresh.

## Screenshots and migration

Relevant screenshot bytes are verified through the existing private chat-image reader, then copied to `error-report-images/{business}/...` when the report is created. Report images remain available independently of chat-image retention. Failed submissions clean up copies. Only Office can read them; server paths and hashes never appear in owner or Office JSON. Image responses use private/no-store and nosniff headers. Confirmed report deletion removes its copies after the database transaction commits, preserving the original chat image. No data is forwarded to external messaging systems.

Apply after the AI conversation migrations:

```sh
php artisan migrate --path=database/migrations/2026_10_25_000007_create_error_reports.php
php artisan migrate --path=database/migrations/2026_10_25_000008_add_error_report_contact_requests.php
```

No queue worker is required. The assistant tool only stages the report; final submission happens in the authenticated owner form POST. The skill does not guarantee diagnosis of every screenshot; unresolved issues enter Office review.

## Verification

Backend tests cover tool registration, skill context, duplicate submission, screenshot snapshotting, foreign/staff access, invalid screenshot cleanup, Office CRUD/audit, concurrent-edit rejection and internal-note privacy. Office component tests cover retained-list view/close, screenshots, edit failure/retry, duplicate-save guards, manual create, filters and confirmed delete. `node scripts/check-error-reports.cjs` verifies the rendered Office flow, deep-link refresh, screenshots and retained list using intercepted requests. Artifacts: `artifacts/error-reports/`. No live business data or paid provider call is used by the fixture.

Contact verification: `node scripts/check-error-report-contact.cjs` checks Contact and submit, all form load states used in the fixture, Phone selection, invalid contact/retry, explicit submit, saved reference and Done, desktop maximize retention, mobile app-bar/browser Back and Forward, and direct completed-request loading. Back preserves the mounted chat and unsent form fields without a new messages fetch. Form layouts were inspected at 320/393/768 mobile widths and desktop. Contact component tests additionally check explicit skip, Not now without mutation, field errors and duplicate-submit guards. Backend tests check all four channels, foreign-owner denial, pending drafts without an Office report, screenshots saved only on final submission and immutable contact on duplicate submission. Artifacts: `artifacts/error-report-contact/`.

Validation on this change: 58 related backend tests, 14 desktop assistant/contact tests, 13 mobile assistant/contact tests, and 4 Office tests passed; lint and all three app builds passed. The full mobile suite has three existing design-rule failures involving native controls/CSS imports in AssistantReply and BusinessActions, an AttachmentPreview CSS import, and radius values in ai-attachment-preview.css. The new contact form uses shared controls, the stylesheet cascade and radius tokens; these existing files were not changed by this feature. No real provider request or external contact message was sent during verification.
