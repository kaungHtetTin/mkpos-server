# MKPOS customer-service Telegram agent

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
contact links do not notify a person or create a support ticket. Files, images,
voice notes, group/channel messages and messages from bots are not processed by
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
