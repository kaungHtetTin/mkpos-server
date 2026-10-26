# Platform theme

Super admins manage the global appearance in **Office → Settings → Theme & branding**. Select a primary HEX color and Light or Dark mode. **Generate with System Agent** creates a private preview; **Apply theme to all apps** publishes it. Applying a changed primary without a preview generates the palette first. Switching only the mode reuses the saved palette and makes no AI request.

The settings page lists all 55 color roles for the selected mode, their HEX values and the apps that reference them. Transparent variants and contextual blends are also listed. Previewing, filtering the inventory and switching settings tabs preserve the draft. If another administrator publishes a newer version, reset to the latest version before applying.

## Coverage and synchronization

Office, business desktop web, the Electron renderer, mobile/Android, Shop and the public Laravel website consume the same saved light and dark palettes. Open clients refresh branding every 30 seconds while visible and on focus, visibility and online events. Updates change CSS variables without reloading business data or clearing unsaved forms. Cached branding supplies the last known appearance offline and at startup.

The Electron bridge updates the window background and native appearance. Capacitor updates Android system-bar contrast. Printed documents, barcode output and image-processing pixels retain their required fixed colors; document previews use explicit paper and ink roles in both modes. Packaged startup artwork retains its bundled appearance until the app loads.

## Server contract

- Public `GET /api/branding` returns primary color, theme mode, version, source and both palettes alongside existing branding assets.
- Super-admin `POST /api/office/branding/theme/preview` accepts `primary_color` and a UUID `request_key`. It returns an owned, private preview and its estimated generation cost.
- Existing `PUT /api/office/branding` applies the primary, mode, expected `theme_version` and optional `preview_id`. A primary change without a preview also requires `request_key`.
- Preview ownership and primary must match. Stale updates return 409. Invalid/provider failures leave the live theme unchanged. Repeating a request key reuses its preview and charge.

System Agent chooses harmonious secondary/neutral seeds and recognizable semantic colors. The server expands both modes and adjusts text/action contrast deterministically. Generation requires enabled/configured AI, available internal AI balance and cached model pricing. Generation tokens and cache-aware estimated cost appear in the AI usage report and internal balance book. Manual payment approval behavior is unchanged.

Deploy migrations `2026_10_25_000009_platform_theme_palettes` and `2026_10_25_000010_theme_generation_usage` with `php artisan migrate`. Both were applied in the local workspace during implementation.

## Maintenance

Use semantic `--theme-*` roles for new UI colors. The canonical client implementation is `shared/theme/themeRuntime.js`; run `node scripts/sync-platform-theme.cjs` after changing it. PHP palette logic is in `app/Support/ThemePalette.php` and must remain numerically consistent with JavaScript. Regenerate the Office source usage inventory with `node scripts/refine-theme-roles.cjs` after adding color references; inspect its role refinements before committing. The migration/restore scripts are historical one-time tools, not normal build steps.

`node scripts/check-platform-theme.cjs` (also each app's `npm run test:theme`) audits CSS/JS paint values, valid role references, runtime copies, PHP/JS parity and contrast. `node scripts/audit-theme-keywords.cjs` checks named colors using the browser CSS parser. `node scripts/check-platform-theme-browser.cjs` exercises mocked preview/apply, all clients, automatic synchronization, light/dark/bright-primary palettes, modal and full-page form preservation, settings navigation and visible-text contrast. Screenshots and findings are in `artifacts/platform-theme/`.

Implementation validation covered backend theme/payment/assistant regressions, the four web-app test suites, desktop tests, production web builds and the desktop renderer build. Browser fixtures intercept writes and AI calls: no live theme was changed and no paid generation was performed. Native bridges have unit coverage; physical Android and packaged Windows device testing remains a release check. Installers/APKs were not published.
