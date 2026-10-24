<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## MKPOS Office administration and financial upgrades

Office supports administrator/super-administrator accounts, activity history,
variable founder shares, bank-book management, configurable subscription
receiving books and shared web branding. See the
[Office overview](../README.md#office-administration-finance-and-branding) and
[financial-book operations guide](../office/docs/financial-book.md).

For an existing database, use the
[financial preservation deployment procedure](../README.md#upgrading-an-existing-office-financial-book).
Take a full backup, stop financial writers, save a snapshot with
`php artisan office:financial-snapshot`, apply `php artisan migrate --force`,
and run the printed `--verify=FILENAME.json` command before reopening writes.
Snapshots are private financial exports under
`storage/app/office-financial-snapshots/`. They supplement the full backup.
Existing receipts remain in legacy book 2; changing the default affects future
receipts only. No balances or transaction history are reset by the upgrade.

The migration preservation regression suite runs in an isolated in-memory
legacy database:

```sh
php artisan test --filter=OfficeBankBookMigrationTest
```

See the main README for first-super-admin provisioning and the distinction
between migrated existing accounts and newly created administrator accounts.

## Demo shops

Run `php artisan db:seed --class=DemoShopSeeder` from this directory after migrations.
This opt-in seeder runs only in local/testing environments and creates 10 shops,
each with 20 published products and 10 POS customers (200 products and 100 POS
customers total), plus 10 shared marketplace customer accounts. Shops have owner
locations, active demo subscriptions, stocked inventory and cash on delivery.

Owners: `demo.shop01@example.com` through `demo.shop10@example.com`.
Customers: `demo.customer01@example.com` through `demo.customer10@example.com`.
All demo accounts initially use password `Demo12345!`.
Storefront slugs: `demo-shop-01` through `demo-shop-10`.

Images use public placeholder URLs such as
`https://picsum.photos/seed/mkpos-product-1/600/600.jpg` and
`https://picsum.photos/seed/mkpos-shop-1/600/600.jpg`.
They are generic photos, not product photographs. The first run needs internet
access and caches 30 images in local storage to work with the existing photo APIs.
Source URLs are saved in each shop's settings as `demo_logo_source_url` and
`demo_product_source_url_1` through `demo_product_source_url_20`.
Image download errors stop seeding before any database inserts. Re-running reuses
cached images and existing demo records, preserving edited stock and passwords.
The normal `DatabaseSeeder` does not automatically load demo data.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains over 2000 video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the Laravel [Patreon page](https://patreon.com/taylorotwell).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Cubet Techno Labs](https://cubettech.com)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[Many](https://www.many.co.uk)**
- **[Webdock, Fast VPS Hosting](https://www.webdock.io/en)**
- **[DevSquad](https://devsquad.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[OP.GG](https://op.gg)**
- **[WebReinvent](https://webreinvent.com/?utm_source=laravel&utm_medium=github&utm_campaign=patreon-sponsors)**
- **[Lendio](https://lendio.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
