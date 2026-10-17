# Email OTP password reset

Shop customer sign-in and business-owner web/mobile sign-in include **Forgot password?**. Recovery has its own page and never signs the user in automatically.

## SMTP setup

PHPMailer is installed through Composer and sends using Laravel's `mail.mailers.smtp` and `mail.from` configuration. It uses SMTP directly; a queue worker is not required. `MAIL_MAILER=log` does not redirect this feature to a log file.

Set these values in the deployment's private `.env` (the values below are examples):

```dotenv
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=security@your-domain.com
MAIL_FROM_NAME="MKPOS"
```

Use `MAIL_PORT=465` and `MAIL_ENCRYPTION=ssl` if the provider requires implicit TLS. For Gmail, use a Google app password when supported by the account, rather than the normal account password. Configure sender-domain authentication with the email provider. Do not commit credentials or put them in any frontend environment file.

After changing environment values, refresh Laravel's configuration cache using the deployment's normal `php artisan config:cache` procedure. Apply migrations with `php artisan migrate --force`; this adds only the OTP table and a nullable reset timestamp on users. The migration was applied in the development database during implementation. Deploy the new frontend, mobile and shop builds together with the backend.

Templates are editable Laravel Blade files:

- `resources/views/emails/password-reset-otp.blade.php` — HTML email with the MKPOS green header, account context, OTP and expiry.
- `resources/views/emails/password-reset-otp-text.blade.php` — plain-text alternative.

Render a synthetic preview without sending email with `php tests/Fixtures/password-reset-preview.php`. The command prints the generated HTML path.

## API and behavior

| Account | Request code | Submit code and new password |
| --- | --- | --- |
| Active business owner (`users.role=owner`) | POST `/api/auth/forgot-password` | POST `/api/auth/reset-password` |
| Active shop customer (`platform_customers`) | POST `/api/ecommerce/customer/forgot-password` | POST `/api/ecommerce/customer/reset-password` |

Request body: `{ "email": "owner@example.com" }`. The generic response includes `resend_after: 60` and `expires_in: 600`. Unknown/inactive accounts and staff get the same response. No email is sent to them. The SMTP delivery time can differ; generic responses do not guarantee identical response timing.

Reset body: `{ "email": "owner@example.com", "code": "012345", "password": "new-password", "password_confirmation": "new-password" }`. Codes are six-character strings, including leading zeroes. Passwords require 8–128 characters and matching confirmation.

- Cryptographic random codes expire after 10 minutes and allow five wrong attempts. The stored account key and code are HMAC hashes scoped to customer/owner; the same email in both realms has separate codes.
- Resending is limited to once a minute per account scope, replaces the prior code and resets its attempt counter. Public send/reset endpoints also limit requests per IP.
- Row locks serialize resends and resets. A consumed code cannot be reused.
- Reset revokes customer tokens or owner Sanctum tokens and remember-login cookies. Existing owner web sessions are rejected on their next authenticated API request using a password-version marker; new sign-in is required.
- SMTP failures invalidate the generated code and log only a sanitized delivery failure. The public request response stays generic. Check server logs and retry after the cooldown; verify provider settings before exposing this flow to users.
- Expired records older than a day are pruned by the daily Laravel scheduler task; keep the existing scheduler running.

## Verification and limits

Backend tests cover scope separation, unknown/inactive/staff accounts, normalization, hashing, expiry, lockout, resend cooldown/replacement, single use, token revocation, stale web-session rejection/new login, IP throttling, template escaping and PHPMailer SMTP configuration/HTML/plain-text composition. SMTP is faked; no live email was sent during QA.

Each app's form tests cover request/reset, leading-zero codes, resend cooldown, changing email, failure/draft retention, field errors, busy controls, duplicate-submit blocking and return to sign-in. Owner route tests include normal, subdirectory and portable reset URLs. Shop navigation verifies its auth-only shell and preserves the requested destination.

Run `node scripts/password-reset-ui-smoke.cjs` from `frontend/` for isolated rendered checks of all three flows. It uses synthetic request responses, verifies one reset request and no horizontal page overflow, returns to sign-in and saves screenshots for inspection. Mobile uses its existing auth header/brand layout; no separate reference image exists for password recovery. Test fixtures use fallback fonts and cannot load deployment logo URLs from temporary HTML.

Relevant tests, mobile lint and all three builds passed. Full mobile suite: 453 passed, with one unrelated existing `kpiConsistency.test.js` assertion about `sale-detail-summary-icon` in Transactions failing. No transaction code was changed. Actual inbox delivery, provider credentials, email-client rendering and Android device Back/touch behavior still need deployment/device verification.
