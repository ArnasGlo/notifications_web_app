# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project
Laravel 10 app ("Notifications Web App") — role-based messaging platform where users delegate access to communication "numbers" and exchange templated messages. Full domain model, schema, and business rules are documented in @ANDROID_APP_CONTEXT.md — treat that file as authoritative, not just background reading. Read it before starting any task here.

## Current focus: Phase 0 — adding a token-based JSON API
Goal: expose the existing web app's functionality as a Sanctum-authenticated JSON API for a future Android client, per the proposed contract in ANDROID_APP_CONTEXT.md §6.

**Do not touch:** existing Blade views, `routes/web.php`, or the session-based web auth flow. All Phase 0 work is additive — new routes in `routes/api.php`, new controllers under `app/Http/Controllers/Api/`, new Form Requests, new API Resource classes for JSON shaping.

## Stack & conventions
- Laravel 10, MySQL. Check `composer.json` for the actual test runner installed (PHPUnit or Pest) and use that — don't introduce a different one.
- PSR-12, match the existing code style already in `app/Http/Controllers`.
- Auth: Laravel Sanctum (already a dependency via `HasApiTokens`, not yet wired up — see audit §5 for exactly what's missing).
- New API controllers must replicate the **exact** validation and authorization logic of their existing web-controller counterparts (see audit §3). Do not invent new business rules or "improve" behavior along the way — flag anything that looks like it needs a product decision instead of silently changing it.
- No Policies/Gates are registered anywhere in this app (`AuthServiceProvider::$policies` is empty) — don't use `authorize()`. Use manual `abort_unless(...)` checks, matching the pattern already used correctly in every controller except the broken `NumberController::edit()`.

## Testing requirements (non-negotiable)
- Every new endpoint needs a Feature test before the task is considered done: happy path, validation failure, unauthorized/unauthenticated access, and the specific business-rule edge cases from audit §3 that apply to it (e.g. DND rejection, busy→queued routing, exact-match blocking, one-reply-per-message).
- Run the full test suite after each endpoint, before moving to the next one.
- Never report a task complete with a failing test.

## Workflow
- **Progress tracker:** `PHASE0_PROGRESS.md` lists all §6 endpoints with status, files, and any decisions that deviated from a literal reading of §6. Read it at the start of a Phase 0 task to see what's done/next; update its row (status, files, notes) as part of completing a task — don't leave it stale.
- One endpoint (or one tightly-coupled group, e.g. login+logout) per task — don't attempt the whole API surface in a single pass.
- Use plan mode before touching anything in `app/Http/Kernel.php` or auth middleware — that config is shared with the existing web app, so a mistake there breaks the live Blade app too.
- Stop and ask if a requirement in ANDROID_APP_CONTEXT.md §6 seems ambiguous rather than guessing.

## Commands

```bash
composer install               # PHP deps
npm install                    # frontend deps (unrelated to API work, but needed to boot the app)
cp .env.example .env && php artisan key:generate

php artisan migrate            # apply schema (MySQL — see .env for DB_* )
php artisan db:seed            # message categories/templates + a test user
php artisan db:seed --class=AdminUserSeeder

php artisan serve              # http://127.0.0.1:8000
npm run dev                    # Vite, hot reload (only needed for Blade/asset work)

php artisan test                                   # full suite
php artisan test --filter=SomeTest                 # single test class/method
vendor/bin/phpunit tests/Feature/SomeTest.php       # equivalent, direct phpunit
./vendor/bin/pint                                   # PSR-12 formatting (Laravel Pint, already a dependency)
```

**Test-DB gotcha:** `phpunit.xml` has its `DB_CONNECTION`/`DB_DATABASE` overrides commented out and there is no `.env.testing`, so `php artisan test` currently runs against whichever database `.env` points at (the real dev `notifications` DB), not an isolated sqlite/in-memory one. Before relying on `RefreshDatabase` in new Feature tests, either uncomment those two lines in `phpunit.xml` (sqlite `:memory:`) or otherwise confirm the test run isn't wiping dev data — don't assume isolation exists today.

## Architecture

Monolithic server-rendered Laravel 10 MVC app; Phase 0 adds a parallel token-based API surface beside it.

- **`app/Models`** — `User`, `Number`, `Delegate`, `Message`, `MessageTemplate`, `MessageCategory`, `Block`. Business logic lives here, not in controllers: `Number::isAccessibleBy()` (owner or delegate) and `Number::canReceiveFrom()` (blocking-rule check) are the two methods every send/access-control path routes through. `app/Models/Contact.php` is dead code (see ANDROID_APP_CONTEXT.md §3) — its table was dropped by a later migration; ignore it.
- **`app/Http/Controllers`** — one controller per resource (`NumberController`, `BlockController`, `DelegateController`, `InviteController`, `MessageController`, `StatusController`), each with its own inline `abort_unless(...)` ownership/delegate checks (no Policies/Gates are registered — `AuthServiceProvider::$policies` is empty). `Controllers/Admin/*` sit behind the separate `admin` route group and are out of scope for Phase 0. Phase 0's new controllers go in `Controllers/Api/` and must mirror this same manual-`abort_unless` pattern.
- **`routes/web.php`** — session/`auth` middleware, Blade-rendered; frozen for Phase 0 work except as read-only reference for what each new API endpoint must replicate.
- **`routes/api.php`** — currently just the Laravel-default `/api/user` stub; this is where all new Phase 0 routes go, under `auth:sanctum`.
- **Business-rule chokepoints** — message delivery in `MessageController` always evaluates, in order: DND rejection → blocking-rule rejection → busy-vs-active routing (`queued` vs `sent`). `StatusController::update` is the only place queued messages get flipped to `sent` (synchronous, no queue worker — `QUEUE_CONNECTION=sync`). Any new API endpoint touching messages or status must replicate this exact sequence, not just approximate it.
- Full schema, relationships, and the complete business-rule list are in `ANDROID_APP_CONTEXT.md` §2–3 — read it rather than re-deriving from migrations when in doubt.

## Git commit conventions
- Never add `Co-Authored-By: Claude` or `Claude-Session:` trailers to commit messages.
- Commit messages should contain only the summary/body — no AI attribution, no signature lines.
