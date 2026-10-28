# Telegram payment receipt prevalidation

Non-COD Telegram checkout and payment-proof submission for existing orders now require a successful receipt check before the signed customer confirmation button is available. COD keeps its existing flow. The owner must still verify funds; a consistent screenshot does not mark an order paid.

Screenshot uploads stage the image and enqueue `ValidateTelegramCheckoutProof` on the existing `sales-ai` connection and queue, after the webhook transaction commits. The existing Node worker wake mechanism runs the job; `sales-ai:process` recovers pending checks through the existing scheduler. No new socket server or cron entry is required.

The checker reuses receipt extraction and balance accounting. Telegram requires readable, completed evidence with matching amount, currency, recipient name/account and selected bank/wallet, a transaction reference, and no duplicate claim. Failed or uncertain checks retain the draft and allow a replacement image or explicit retry. The general shop receipt policy is unchanged.

A server-encrypted result expires after 15 minutes and binds the business, checkout, image, order (when applicable), payment method and complete quote. Confirmation validates that binding and checks duplicate claims again. Changed totals, quantities, payment details, images or expired results require another check. Stale queued jobs cannot overwrite newer uploads. Order creation and final proof attachment run in one transaction; failed finalization rolls back the order and reservations and removes the copied proof while retaining the staged image.

The guidance migration publishes an updated `sales-telegram-channel` skill and preserves existing version history and pending Office draft edits. No new model-callable tool or manual Office skill edit is required.

## Deployment

Apply the backend files to the Laravel application. Run `php artisan migrate --force`, refresh optimized Composer autoloading if used, and restart the existing Sales AI queue workers (`php artisan queue:restart` for Laravel workers). Keep the existing scheduler and Node worker manager running. No frontend or mobile rebuild is required. The earlier 3.0.3 release archives predate this change.

## Verification

The focused suite passed 37 tests with 863 assertions, covering successful confirmation, COD, existing-order receipts, mismatch and unreadable evidence, duplicate claims, worker recovery, retries, stale uploads, changed quotes, expiry, tampered results/images, finalization rollback, shared shop receipt behavior and preservation of skill history/drafts. All 11 runtime PHP files passed syntax checks. See `artifacts/telegram-prevalidation-final-tests.log` in the repository root for the test output. Live Telegram and production deployment were not exercised.
