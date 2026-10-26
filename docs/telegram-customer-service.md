# MKPOS customer-service Telegram agent

## Troubleshooting approved groups with no replies

Private `/help` succeeding does not prove group updates are arriving. In Office,
click Test connection to inspect Telegram Group Privacy, message-update selection,
the verified username versus the saved username, and any historical webhook error
timestamp. Testing does not save credentials or register anything. Register webhook
refreshes the verified identity. The CLI `telegram-support:setup --register` also
persists the verified username and requests both message and membership updates.

Send `/help@your_bot_username` from a normal personal Telegram account in the group,
then refresh Office. The group status and Manage group dialog show the last input
check: missing bot username, disabled service/group replies, missing approval or
membership, unmatched trigger, bot/anonymous sender, storage failure, accepted help
or accepted AI work. Last recorded reply errors appear in the editor as well.
These diagnostics contain only reason codes and UTC timestamps, never message text,
image references, tokens or extracted payment information. Cache evidence expires
after seven days; absence is not proof that a group has never sent a message.

An unchanged input-check timestamp after a new targeted command means the app has
no new diagnostic evidence for that group. Check Telegram delivery and saved webhook
routing. A storage failure points to migrations/server logs. Accepted input with a
delivery-rejected error points to the bot's group send permissions. Group support
still requires the saved global Group replies switch plus approval and a matching
mention/reply trigger. Anonymous group admins are intentionally not treated as
ordinary human senders.

## Images and manual payment-receipt review

Office → Settings → Telegram customer service → Images & payment receipt review:

1. Enable Image reading to accept Telegram photos or JPEG/PNG/WebP image documents
   up to 5 MB. Image documents must have a matching image filename extension.
2. Add the bot to a private administrator group. Register the webhook once if group
   membership updates have not been enabled. Copy the discovered negative group ID
   from the Telegram groups directory.
3. Enable Admin receipt notices, enter that group ID, and optionally set a forum
   topic ID (0 uses the default chat). Save settings. This explicitly authorizes
   sharing receipt images and extracted details with that group.

The selected notice destination must belong to the current bot, have confirmed bot
membership, and not be disabled. It is independently selected by a super admin;
it need not be approved for public AI replies. The Group replies switch controls
incoming public support questions and is not required for outgoing admin notices.
Replacing the bot token clears the old notice destination and disables notices.
Turning image reading off in Office also switches notices off.

The webhook stores an encrypted Telegram image reference and queues the work.
It does not download files, call AI or send admin messages. Existing global,
private-chat/group, per-user, minute and pending-work admission limits apply to
images as well as text. In groups the caption must mention the bot, or the image
must reply to the bot, under that group's approved trigger policy.

The worker downloads only bounded files from the fixed Telegram API host, refuses
redirects/path traversal, verifies JPEG/PNG/WebP bytes and pixel dimensions, and
re-encodes a metadata-free JPEG of at most 2400 pixels on its longest edge before
passing it to the image reader. No original file URL or token is exposed to AI or
written into queue payloads. No image files are saved in public storage.

MKPOS screenshots receive an image explanation in the originating chat. Detected
payment receipts receive an extraction of the visible amount, currency, provider,
recipient name/masked account, reference, date and screenshot status. This does not
check a bank transaction, authenticate the screenshot, approve a payment, create a
billing request, credit a wallet or activate a subscription. An admin must verify
funds manually. The image reader has no tools or account permissions.

When enabled, a separate durable notice job sends the receipt image with extracted
details to the saved admin group/topic, clearly marked MANUAL REVIEW REQUIRED and
NOT verified. Unresolved MKPOS error screenshots are also queued to this saved admin group, independently of the receipt-notice switch. Unrelated images and explained errors are not forwarded. Destination is determined by saved
settings, never caption/model output. Photo/document file IDs retain their Telegram
type. One notice exists per admitted message. An explicit Telegram 429 schedules
only the notice for retry; ambiguous sends are terminal and are not automatically
repeated. The existing customer-service recover command recovers lost notice
dispatches and marks interrupted sending as delivery_unknown. No extra cron or
worker is needed. Per-message deduplication is not proof of payment uniqueness.

Image references and extracted reports are encrypted with APP_KEY. `/reset`
erases that participant's saved references/reports and cancels pending notices;
daily pruning does the same for data older than 30 days. Copies already sent in
Telegram/admin groups are not removed. `/help` and `/privacy` disclose image support
and configured receipt sharing. Incurred model usage is billed once even when
extraction validation fails; no model call is made just to retry an admin notice.

Deploy backend and Office updates and run only the new migration:

```sh
php artisan migrate --path=database/migrations/2026_10_27_000001_telegram_images_and_receipt_notices.php --force
php artisan optimize:clear
```

The earlier group migration must already be installed. These settings default to
disabled; uploading does not contact Telegram or enable sharing. Cron workers load
new PHP on the next invocation. Restart an existing persistent customer-service
worker after deploying. Test coverage uses faked Telegram/model calls and bounded
generated image fixtures, not live customer receipts.

## Typing indicator

The customer-service worker sends Telegram `sendChatAction` with `action=typing`
when it starts generating an AI answer. With PHP cURL, transfer-progress callbacks
refresh it approximately every four seconds, including while waiting for the
provider's first response byte. The normal non-streaming agent call and billing
remain unchanged. Nothing is sent while a job is waiting for cron or another job;
commands, already-generated delivery retries and rejected updates do not start
typing. No new worker, cron job, Office setting or migration is needed for typing.

The worker checks the current bot identity, enabled status, message state,
conversation version and group permission before each action. Forum actions use
the same topic as the incoming message. Reset, cancellation, permission withdrawal
or bot changes stop refreshes. The in-memory scope is cleared even if generation
throws, so later jobs cannot inherit another conversation's typing state.

Each action has a one-second connection/total timeout. Telegram `retry_after` is
respected; transient failures back off for 15 seconds, permanent rejection stops
actions for that run. Typing failures never trigger another model call or fail an
answer. The worker stops refreshing after generation and Telegram clears the hint
when the reply arrives or the short action lifetime expires. The PHP stream
transport may not provide callbacks while idle, so enable cURL for continuous
refreshes. There are no background child processes or shell commands involved.

After uploading the PHP update, run `php artisan optimize:clear`. Cron workers pick
it up on their next invocation; restart a persistent customer-service worker.

## Multiple Telegram groups

Office → Settings → Telegram customer service now includes a Group replies switch
and a paginated group directory. Group replies default to disabled. Each group is
scoped to the currently saved bot and starts pending approval. Add the bot to each
group, register the webhook again once (to include `my_chat_member` updates), then
review each discovered group through Actions → Manage group. Groups can also be
added by their negative Telegram chat ID; a manually added group cannot receive
answers until a real group update confirms the bot is a member.

Each group has independent approval/disabled status, mention and direct-reply
triggers, a daily group allowance and a daily per-user allowance. The global daily
AI allowance covers private chats and every group combined. The existing per-chat
daily allowance applies only to private chats. Budgets reset at midnight Yangon
time. Membership/title discovery does not store the unaddressed message text or
call AI. Message edits, channels, anonymous bot senders, and ordinary group chatter
do not trigger answers.

For mentions, use the saved registered bot username, such as
`@mkpos_help_bot Explain MKPOS packages`. Keep Telegram group joining enabled.
For ordinary @username mentions, disable Group Privacy in BotFather using
`/setprivacy`, then remove and re-add the bot to each existing group so Telegram
applies the change. The app still ignores unaddressed group messages and requires
Office approval. If Group Privacy stays enabled, use `/help@mkpos_help_bot` and
reply to the bot's response instead; normal mention text is not an assured update
delivery path. The bot needs permission to send messages; administrator access is
not necessary when Group Privacy is disabled. Group history is isolated by bot, group,
sender and topic. A user's `/reset` resets only their own conversation in that topic,
not other participants or private chats. Group replies preserve the topic ID.

Permission is checked again before generation and before delivery. Changing a
group's settings cancels its queued replies and invalidates its prior context;
turning Group replies off cancels pending group work without affecting private
chats. In-flight model calls may still incur a charge, but their answer is suppressed
if approval has been withdrawn. A Telegram request already in transit cannot be
recalled. Group activity exposes only timestamps, request counts and safe error
codes to Office, not conversation text or credentials.

Deploy the updated PHP source and Office build, then run only this migration:

```sh
php artisan migrate --path=database/migrations/2026_10_26_000008_add_telegram_support_groups.php --force
```

The migration preserves existing private chats and makes chat IDs signed to support
negative group IDs. Its rollback intentionally retains the schema and history.
Run the existing customer-service cron worker; no separate worker per group is
needed. Restart an existing persistent customer-service worker after deploying new
code. Office group settings use version checks to reject stale edits.

The original 3.0.0 release archives predate this feature.

This is a separate Laravel AI agent and `customer-service` database queue. It
answers public questions about MKPOS in English or Myanmar using the existing
official knowledge catalogue. It does not replace Wyne Wyne or Sales AI, and
does not change the existing MCP/OpenClaw integrations.

## Connect the new bot

1. Create the dedicated bot with Telegram's [BotFather](https://t.me/BotFather).
   Keep its token private. Do not paste it into customer chat or source control.
2. On an existing deployment, apply both migrations in step 3 and deploy the rebuilt
   Office assets first. Sign in as a **super administrator** and open **Settings → Telegram
   customer service** (`#/settings/telegram-support`). Paste the BotFather token,
   enter the public HTTPS webhook URL, set the daily limits, and save. Select
   Enabled when ready. A blank token field keeps the saved token.

   Click **Test connection** to verify the saved token or a replacement draft.
   This does not save, register a webhook, send a chat message or use AI credit.
   After saving, click **Register webhook**. An existing different webhook is
   preserved unless you explicitly select **Replace an existing webhook**.

   Office credentials are encrypted using `APP_KEY` and are never returned to
   the browser or included in Office audit details. The webhook secret is generated
   automatically. Keep the server's `APP_KEY` backed up privately. Concurrent stale
   edits are rejected; reload saved settings before retrying. Unsaved drafts remain
   in the open tab across navigation but are never persisted in browser storage.

   Environment configuration remains a fallback before an Office value is saved:

   ```dotenv
   TELEGRAM_SUPPORT_ENABLED=true
   TELEGRAM_SUPPORT_BOT_TOKEN=your_private_bot_token
   TELEGRAM_SUPPORT_WEBHOOK_SECRET=your_private_random_secret
   TELEGRAM_SUPPORT_WEBHOOK_URL=https://your-domain.example/integrations/telegram/customer-service
   TELEGRAM_SUPPORT_DAILY_LIMIT=200
   TELEGRAM_SUPPORT_CHAT_DAILY_LIMIT=20
   ```

   The local workspace already has a generated private webhook secret; leave
   that value intact. Examples intentionally have no usable credentials. For
   another server, generate a random secret with at least 32 URL-safe characters.
   Include the actual Laravel URL prefix in the webhook URL, such as `/public`
   where required by that deployment. Set `APP_URL` and the public client URLs
   correctly so knowledge links point to the public deployment, not localhost.
   Telegram requires an accessible HTTPS webhook. A localhost URL cannot receive
   Telegram updates. Shared platform AI settings/key/pricing must be configured
   and the platform AI balance must have credit for model-generated answers.
3. Apply the additive migration and reload configuration:

   ```sh
   php artisan migrate --path=database/migrations/2026_10_26_000005_create_customer_service_agent.php --force
   php artisan migrate --path=database/migrations/2026_10_26_000006_create_telegram_support_settings.php --force
   php artisan config:clear
   php artisan telegram-support:setup
   ```

   The setup command checks bot identity and existing webhook status without
   changing Telegram. Once ready, register the webhook explicitly:

   ```sh
   php artisan telegram-support:setup --register
   ```

   It refuses a non-HTTPS/localhost target, missing secret/storage, disabled
   integration, or a different existing webhook. `--replace-webhook` is available
   only for an intentional migration of a previously integrated bot. Registration
   never drops pending updates. It receives ordinary `message` updates and limits
   concurrent webhook connections to one. See Telegram's
   [webhook contract](https://core.telegram.org/bots/api#setwebhook).
4. Start a dedicated worker:

   ```sh
   php artisan queue:work customer-service --queue=customer-service --sleep=2 --timeout=360 --tries=60
   ```

   On Windows, run `start-customer-service.cmd` from the repository root. It starts
   a hidden worker, including while the integration is disabled, and avoids duplicate
   launches. Both combined dev/prod launchers include it. Logs are
   `artifacts/customer-service-worker-service.log` and the corresponding
   `-error.log`. Use `scripts/start-customer-service-worker.ps1 -CheckOnly` to check.
   Production requires the same process supervision as the other queue workers.
   Office settings are read for each operation, so enabling the bot or updating
   credentials/limits needs no worker restart. Keep the idle worker supervised so
   Office activation can start processing immediately. Disabling pauses work but
   does not unregister the webhook. Changing bot identity rotates its webhook
   secret and cancels old queued work rather than sending it through the new bot.
5. Keep the normal minute scheduler running for dispatch recovery. Start a
   private chat with the new bot, send `/start`, then ask a public MKPOS question.
   `GET /api/health/workers` adds `customer_service` as required only when enabled.
   A healthy heartbeat checks the worker, not Telegram delivery or model credit.

## Public tool permissions

The fixed allowlist reuses the public MCP implementations directly; the model
cannot choose PHP classes, HTTP targets, databases or account identities.

| Tool | Access |
| --- | --- |
| `get_mkpos_overview` | Official capabilities, platforms and limitations |
| `get_available_package_plans` | Active public non-system plans, current prices/currency/duration/features |
| `get_account_guide` | Public signup, login and password-reset form guidance |
| `get_platform_links` | Official web/mobile links and published installer availability |
| `get_video_tutorials` | Published tutorials, bounded to five per call |
| `search_help_articles`, `get_help_article` | Public MKPOS/Shop help |
| `get_form_field_guide` | Form documentation, no submitted field values |
| `get_troubleshooting_guide` | Safe troubleshooting steps |
| `get_supported_devices`, `get_latest_app_versions` | Published compatibility/release information |
| `get_support_contacts` | Configured public support contacts |
| `get_subscription_payment_methods` | Active platform subscription recipients |
| `get_service_status` | Local/public observations with their stated limitations |

No business records, account/profile tools, approvals, wallet adjustments,
refunds, shell/SQL execution or private/admin MCP connections are granted. Human
contact links do not notify a person or create a support ticket. The separately
configured receipt-image flow can notify the saved admin group. Images require
Image reading to be enabled; other files, voice notes, channel messages,
unapproved/unaddressed group messages and messages from bots are not processed by
the model. Knowledge search/results and the model tool loop are bounded.

## Processing and privacy

- The stateless webhook checks `X-Telegram-Bot-Api-Secret-Token` with constant-time
  comparison, caps request size, validates private-chat sender identity, deduplicates
  `(bot_id, update_id)` and returns promptly. No model or Telegram send happens in
  the webhook request. Unsupported updates are acknowledged without paid work.
- Each chat has its own history. Up to three prior delivered exchanges from that
  chat/version are included. Tenant context is cleared/restored around the agent;
  Telegram identity never becomes a signed-in MKPOS owner/admin.
- `/start` and `/help` explain usage, `/privacy` explains storage, and `/reset`
  clears saved text and cancels old queued replies. These commands cost no AI
  credit. Reset does not reset abuse quotas or delete messages already in Telegram.
  A send already in flight cannot be recalled.
- Text is encrypted at rest with `APP_KEY`. The daily prune command erases input
  and reply text after 30 days. Telegram identifiers, delivery state and usage
  metadata remain for deduplication, rate limits and accounting. Private notices
  are throttled to prevent reply spam. Never send passwords, OTPs or PINs.
- Defaults allow 200 AI requests per platform day, 20 per chat/day, four per chat
  per minute and three pending per chat. Days use Asia/Yangon. Shared SQL admission
  locks prevent concurrent bypasses. Model expense uses the platform AI balance
  and appears in AI usage charts; business Sales AI wallets are not charged.
- A durable request is claimed once. Duplicate jobs cannot repeat model calls or
  charges. Actual reported usage is recorded even if reset suppresses an answer.
  Interrupted model calls fail without automatic model replay.
- A generated answer is saved before sending. Confirmed Telegram flood-control
  responses retry the saved answer after `retry_after`, without another model call.
  Ambiguous send failures are marked `delivery_unknown` and never automatically
  resent; exact-once delivery cannot be guaranteed across a remote timeout. Bot
  rejection/block responses terminate the delivery. Payloads are plain text,
  bounded below Telegram's [message limit](https://core.telegram.org/bots/api#sendmessage).
- Telegram URLs contain the token, so transport exceptions/raw responses are not
  logged or chained. Queue payloads contain only request UUIDs, not customer text.

## Verification

```sh
php vendor/phpunit/phpunit/phpunit tests/Feature/TelegramCustomerServiceTest.php
```

Tests use SQLite, SDK fakes and intercepted Telegram HTTP. They never send real
Telegram messages, register a real webhook or spend live model credit. No new
remote socket deployment is required. Rebuild/deploy Office assets for its settings
page (`cd office && npm run build:laravel` from the repository root).


## Shared MKPOS image scope and escalation (2026-10-27)

Wyne Wyne and Telegram use the restricted structured image reader. Sales AI uses a sales-specific structured reader with its existing shop-scoped, customer-confirmed tools. All three share the server policy for MKPOS subscription receipts, unrelated images and technical error escalation. Captions and screenshot text are untrusted evidence. Unrelated images receive "This image is not related to the MKPOS system." Unreadable images request clearer evidence. Clearly supported MKPOS errors receive safe explanations; unknown errors are escalated without inventing a diagnosis or claiming a fix.

Subscription receipts are compared against the current active platform payment_methods, never shop-order recipients. Matching requires a complete visible recipient account, name and provider. Missing/masked fields are inconclusive, and inactive recipients never count as a match. A matching recipient does not confirm funds, authenticity or subscription approval.

- Wyne Wyne: unknown image errors create an idempotent Office Error report, scoped to the current actor/business, with a private screenshot copy. Automatic image escalation does not invent a contact channel. Existing text-report contact forms keep their normal flow.
- Sales AI: unknown image errors create an Office Error report scoped to the chat business, with no fabricated owner/reporter identity and a private screenshot copy.
- Telegram: unknown image errors use the existing durable admin-group notice queue, with the saved admin group/topic and current bot membership/access checks. Enable image reading and configure the admin group in Office Telegram settings. This error route uses that group even if receipt notices are off. Missing configuration produces an explicit reporting-unavailable reply, never a success claim. Queued is not confirmed Telegram delivery.

Sales AI also admits shop-relevant product photos and shop-order receipts. Product images use visible labels/model text to find catalog candidates; the customer confirms uncertain matches. Shop-order receipts use the customer own order and native payment-proof review card, never platform subscription recipients. Image action cards are published only for product/shop-payment classifications; unrelated images and platform support screenshots cannot publish sales proposals. All existing owner tool permissions and customer confirmation boundaries still apply. Wyne follow-ups retain text history without resending old images outside the classifier. To re-read an image, attach it again.

Deploy the new backend and run only this additional migration:

```sh
php artisan migrate --path=database/migrations/2026_10_27_000002_add_image_escalation_key_to_error_reports.php --force
php artisan optimize:clear
```

Restart persistent workers after upload; bounded cron workers load the new code on their next invocation. No new Office build or additional cron command is needed. The migration adds only an idempotency key to error_reports and does not change AI credits.


## Kaung Kaung identity and public support skills

The public Telegram assistant is named Kaung Kaung, the MKPOS customer-service and help AI. The text persona, /start and /help messages (including image-enabled mode), and Telegram-only image-reader context use this identity. Wyne Wyne and Sales AI keep their own identities. This does not change the Telegram @username, webhook address or group mention target; Telegram profile/display-name changes are managed separately through BotFather.

The read-only get_customer_service_skills tool provides 13 public workflows: introduction/app selection; getting started; daily POS workflows; current plans; subscription payments; account access; devices/printing; safe troubleshooting; MKPOS images; online Shop/Sales AI; versions/status; human support; and privacy/groups. The all topic gives a compact index and a specific topic gives practical steps. It contains static public guidance only and performs no account/database reads or writes. Its recommended tools are restricted to the existing public knowledge allowlist.

Live facts still come from official public tools. Private account records, role changes, password resets, subscriptions, wallets, payment approval, refunds and administrative actions remain unavailable. Account recovery provides instructions and official links only. A support contact link does not create a ticket or notify anyone. Error-image reports and receipt notices use the existing configured admin-group queue; the bot distinguishes queued from sent and manually approved.

Group answers use public information. Kaung Kaung does not ask members to post credentials/full receipts in groups. Image-enabled /help and /privacy disclose that unresolved MKPOS error screenshots may be forwarded to the configured admin group as well as any enabled receipt notices. Resetting bot history does not remove copies already sent to Telegram/admin chats.

No additional database migration, cron job, Office build or bot token is needed for this identity/skills update. Upload the backend patch, run php artisan optimize:clear and restart persistent workers. Cron workers use the new code on their next invocation.
