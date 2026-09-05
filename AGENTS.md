# Laravel AI Gateway

Laravel 13 app (PHP ≥ 8.3). Fresh scaffold — routes, models, and controllers are mostly default. Build from scratch.

## Commands

```sh
composer setup          # install, .env, key:generate, migrate, npm install, npm run build
composer test           # clears config cache then runs php artisan test
composer dev            # starts artisan dev server (long-running)
npm run build           # vite production build
npm run dev             # vite dev server
```

Single test: `php artisan test --filter="test name"` or `vendor/bin/pest --filter="test name"`

## Stack

- **Testing:** Pest PHP 5 (phpunit.xml present but Pest is the runner)
- **CSS:** Tailwind CSS 4 via `@tailwindcss/vite`
- **Build:** Vite 8 + laravel-vite-plugin 3
- **Linting:** Laravel Pint (`vendor/bin/pint`)
- **DB (dev):** MySQL (`laravel_ai_gateway` database)
- **DB (test):** SQLite in-memory (set in phpunit.xml, no `.env` override needed)

## Conventions

- Models use Laravel 11+ attribute syntax: `#[Fillable]`, `#[Hidden]` (see `app/Models/User.php`)
- No `routes/api.php` — API routing is not yet wired. Only `routes/web.php` and `routes/console.php` exist
- Frontend entry points: `resources/css/app.css`, `resources/js/app.js`
- `.npmrc` sets `ignore-scripts=true` — postinstall scripts are skipped by design
- Test base class is `Tests\TestCase` with `RefreshDatabase` trait commented out in `tests/Pest.php`
- Feature tests live in `tests/Feature/`, unit tests in `tests/Unit/`
