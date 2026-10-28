# Chat message timestamps

Shared `shop_chat_messages` and `shop_chat_threads` SQL timestamps use `app.timezone`. The shop API converts them to UTC once through `AppTime`. Business web/mobile render those instants in the business timezone; Shop customer chat now uses the `thread.timezone` returned by the API, including message tooltips and customer inbox dates. HTML message `datetime` attributes contain explicit UTC ISO timestamps. Telegram's native app continues to display an instant in the viewer's device timezone.

Previously, Telegram incoming messages, Sales AI replies, form activity and some thread updates wrote UTC wall time into these shared app-time tables. The API interpreted that value as app time, shifting it a second time. With Asia/Yangon this caused a 6 hour 30 minute error, including incorrect dates around midnight and inbox sorting. Telegram's incoming path also failed to advance thread activity when receiving another customer message.

`ChatTime` now applies the shared storage convention to all chat writers. AI run accounting, Telegram updates/outbox delivery clocks, billing and queue timestamps retain their own existing UTC conventions.

Migration `2026_10_28_000005_normalize_chat_message_times.php` repairs legacy messages from known UTC writers: AI messages, form activity, and incoming messages referenced by Telegram update records. It preserves human owner replies, including those in Telegram conversations. It repairs Telegram thread creation times and rebuilds inbox activity from the latest message. `time_storage_version` marks corrected and new records to prevent a second conversion. The migration's `down()` retains the repaired dates and markers.

## Deployment

Use the same `APP_TIMEZONE` as the existing installation. Enter maintenance mode and pause/drain chat workers before replacing code and running this data migration, so old workers cannot continue writing the previous timestamp format during repair. Deploy Laravel source and rebuilt `public/app`, `public/mobile`, and `public/shop` assets together. From the Laravel directory:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
php artisan up
```

Resume the workers and reload the browser applications. The migration processes history in bounded chunks. Apply it to the live database during deployment; local repair tests do not modify live history.

## Verification

The repair test covers mixed web/AI/Telegram history, a human reply inside a Telegram thread, midnight rollover, API UTC conversion and rerunning the migration. The Telegram integration test follows a customer message, an AI reply and an owner reply through real Laravel endpoints. Shop rendering tests exercise a non-Myanmar business timezone. `scripts/check-chat-times.cjs` checks the rendered customer conversation at 440px and 1440px with browser device timezones America/Los_Angeles and Asia/Tokyo; its fixture is isolated from live APIs. This verifies message time behavior, rather than a full application layout audit.
