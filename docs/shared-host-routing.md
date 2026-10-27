# Main-domain routing on shared hosting

The preferred document root for the main domain is the deployed Laravel
`public/` directory. Keep the Laravel project, `.env`, dependencies and storage
outside the publicly served directory whenever the hosting panel supports it.

For hosts fixed to the Laravel project directory, deploy `laravel/.htaccess`
alongside `artisan`, and retain `public/.htaccess` and `public/index.php`.
The root rules internally map requests into `public/`, rather than denying the
landing page or serving existing project files. Hidden files and private paths
remain blocked. Existing `/public/...` URLs continue to work. Both rulesets are
needed; do not copy the project-root rules into `public/.htaccess`.

Apache/LiteSpeed must allow these rewrite rules. Nginx requires its own server
configuration with the document root set to `public/`; `.htaccess` does not
configure Nginx. This fallback does not change the separate Shop, Business,
Mobile or Office subdomain document roots.

After uploading the two `.htaccess` files, check `/`, `/documentation`, a
landing-page asset, and the existing `/public/` entry. Verify `/.env`,
`/composer.json`, `/vendor/autoload.php`, and `/config/app.php` return 403.
If the host/CDN cached a previous 403, purge that cached response. No frontend
rebuild or database migration is needed for this routing change.

Run `python scripts/check-shared-host-routing.py` from the repository root for
an isolated Apache regression check. It exercises both document-root layouts
against fixture files and does not connect to the database or live server.
