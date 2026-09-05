# Notifications Web App

A full-stack Laravel platform for managing multiple communication "numbers" (channels), exchanging messages between them, and **delegating access to a number without ever sharing login credentials**. It ships as a Blade web app plus a token-authenticated JSON API for a native mobile client.

Built during a professional internship at **UAB Getweb** (Vilnius, Lithuania). The problem it solves: a single user often needs to administer several communication channels and hand off access to assistants — without sharing passwords, without a way to check whether the recipient is even available, and without a way to filter out unwanted senders. This app automates that with role-based access, shareable delegation links, availability-aware message routing, and per-number blocking rules.

## Features

**Authentication & roles**
- Email/password login (public self-registration is disabled — accounts are created by an administrator)
- Two roles: regular user and administrator, with separate access levels
- Password reset and email verification

**Number management**
- Create, edit, activate/deactivate, and delete "numbers" (communication channels) that belong to a user
- Each number automatically gets a unique UUID-based share token on creation

**Access delegation**
- Share a number's delegation link with another user
- The recipient opens the link and accepts to become an **assistant** on that number — no credentials are ever shared
- The owner can view all assistants and revoke access at any time
- Assistants can manage messages on the delegated number but cannot transfer ownership or manage other assistants

**Conversations & messaging**
- Messages are grouped into conversations — one thread per pair of numbers, ordered by latest activity, with a message preview and an unread badge per row
- The chat page shows the full history (replies included) with date separators, outbound/inbound bubbles and sent/read/queued ticks
- Free-text composer with pre-written templates: press `/` to search saved replies, insert one, then edit it before sending
- Filter the conversation list by an exact number, or jump straight to a thread from the quick-jump menu
- Opening a thread marks every inbound message in it as read

**Favorites**
- Star any number to keep it in a personal shortcut list, each entry linking straight to the conversation with that number
- Starring is unilateral — it needs no approval from the number's owner

**Live updates**
- The conversation list and any open chat poll the server every few seconds, so new messages, previews, ordering and unread badges appear without a reload
- Polling pauses while the browser tab is hidden and backs off if the server is unreachable

**JSON API for mobile clients**
- A token-authenticated (Laravel Sanctum) JSON API mirrors the web app's functionality for the planned native Android client
- `POST /api/login` issues a bearer token per device (30-day expiry); `POST /api/logout` revokes only the current one
- Every rule below is enforced by the same model code both clients call, so the two can't drift apart

**Presence & message queueing**
- Each user has a status: **Active**, **Busy**, or **Do Not Disturb**
- `Busy` → incoming messages are queued instead of delivered immediately
- `Do Not Disturb` → incoming messages are rejected outright
- Returning to `Active` automatically flushes all queued messages to "sent"

**Blocking rules**
- Per-number blocking by sender number, sender user, city, or country
- Checked automatically before a message is allowed through

**Admin panel**
- Manage users, all numbers, message categories, and message templates
- Built on AdminLTE, restricted to the administrator role via dedicated middleware

## Business rules

Every outgoing message is evaluated against three rules before it's delivered:

1. **Delivery eligibility** — the message is only delivered if the recipient's status isn't `Do Not Disturb` **and** the sender doesn't match any of the recipient's blocking rules (by number, user, city, or country).
2. **Status routing** — if the recipient is `Busy`, the message is stored with status `queued`; otherwise it's stored as `sent`.
3. **Queue release** — when a user returns to `Active`, every message queued against their numbers is switched from `queued` to `sent`.

Also enforced, in the models rather than in the controllers, so the web app and the API apply them identically:

- An `inactive` number can neither send nor receive.
- A number with message history cannot be deleted — history is deliberately preserved for the other party, so deactivate it instead.
- A message can be replied to once, reply threads are one level deep, and a reply must use an active reply-eligible template from the original message's category. Replies deliberately bypass the presence and blocking checks.
- Blocking rules and delegates can only be managed by a number's owner; an assistant can read and reply on a delegated number but cannot compose from it.

## Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.1+, Laravel 10 (MVC) |
| Database | MySQL / MariaDB, Eloquent ORM |
| Frontend | Blade templates, AdminLTE 3, Bootstrap 5, vanilla JavaScript |
| API | Laravel Sanctum bearer tokens, API Resources, Form Requests |
| Auth | `laravel/ui` (web sessions), Sanctum (API tokens), bcrypt password hashing |
| Build tooling | Vite, npm |
| Testing | PHPUnit 10 — 273 feature tests, run against an in-memory SQLite database |

## Architecture

The app is a monolithic, server-rendered Laravel application following the standard MVC pattern:

- **Models** (`app/Models`) — `User`, `Number`, `Delegate`, `Conversation`, `Message`, `MessageTemplate`, `MessageCategory`, `Block`, `Favorite`. Business logic lives here rather than in controllers, so both clients enforce the same rules: `Number::isAccessibleBy()`, `Number::canReceiveFrom()`, `Conversation::between()`, `Message::canBeRepliedWith()` and the shared inbox/update query scopes.
- **Actions** (`app/Actions`) — `SendMessage` and `ReplyToMessage`, the single delivery path every send goes through (DND → blocking → busy/queued routing).
- **Controllers** — web controllers in `app/Http/Controllers`, their JSON counterparts in `Controllers/Api`, admin-only ones in `Controllers/Admin` behind a dedicated `admin` route group. Authorization is explicit `abort_unless(...)` ownership/delegate checks in every method.
- **API layer** — a Form Request per endpoint (`app/Http/Requests/Api`) for validation, and API Resources (`app/Http/Resources`) for JSON shaping.
- **Views** (`resources/views`) — Blade templates that render the UI, with shared partials for conversation rows, message bubbles, the composer and the polling helper.
- **Middleware** (`app/Http/Middleware/AdminMiddleware.php`) — restricts the `/admin` routes to authenticated users with `is_admin = true`; anyone else gets a 403.

Request flow (web): `routes/web.php` → middleware (`auth`, `admin`) → controller → model/database → Blade view.
Request flow (API): `routes/api.php` → `auth:sanctum` + rate limiter → API controller → model/database → JSON resource.

Functional modules and how they depend on each other:

- **Auth & roles** — underpins every other module (all routes require login)
- **Numbers** — owns share-token generation; almost everything else references a number
- **Delegation** — assistants and invites, tied to a specific number
- **Messaging** — the central module; checks blocking rules and recipient status before delivering
- **Blocking** — per-number sender filters
- **Status** — drives queue release back into the messaging module
- **Admin** — manages users, numbers, categories, and templates

## Database schema

| Table | Purpose |
|---|---|
| `users` | Accounts: name, email, hashed password, status (`active`/`busy`/`dnd`), admin flag |
| `numbers` | Communication channels owned by a user; unique `number`, country, city, UUID share token |
| `number_delegates` | Pivot table linking a number to an assistant user (unique per pair) |
| `blocks` | Blocking rules per number: type (`number`/`user`/`city`/`country`) + value |
| `message_categories` | Groupings for templates |
| `message_templates` | Pre-written message bodies, flagged active/reply-eligible |
| `conversations` | One thread per (normalised) pair of numbers, with an indexed `last_message_at` driving list ordering |
| `messages` | Conversation, sender number, receiver number, body, optional template (provenance), optional parent (for threaded replies), status, read timestamp |
| `favorites` | A user's starred numbers (unique per user/number pair) |

Relationships: one user → many numbers (1:N); users ↔ numbers delegation is many-to-many via `number_delegates`; a number can have many blocking rules and many messages (as sender or receiver); every message belongs to the conversation for its number pair, and `messages` also has a self-referencing `parent_id` for reply threads. Referential integrity is enforced with foreign keys — cascading where a child has no meaning without its parent, restricting on `messages` so history is never silently destroyed — plus unique constraints on the number value, the share token, the owner/assistant pair, the conversation pair and the user/favorite pair.

## JSON API

A parallel, token-authenticated API exposes the same functionality for the planned Android client. Everything except login and the invite lookup sits behind `auth:sanctum`; responses are JSON resources (`{"data": ...}`), validation errors are Laravel's standard `422` shape.

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/login`, `/api/logout` | Issue / revoke a device bearer token |
| GET | `/api/user` | The signed-in user |
| GET, POST | `/api/numbers` | Owned numbers + numbers assisted; create |
| PATCH, DELETE | `/api/numbers/{id}` | Update; delete (refused when message history exists) |
| GET | `/api/numbers/search?number=` | Exact lookup of an active number |
| GET, DELETE | `/api/numbers/{id}/delegates[/{id}]` | List / revoke assistants (owner only) |
| GET, POST, DELETE | `/api/numbers/{id}/blocks[/{id}]` | Blocking rules (owner only) |
| GET, POST | `/api/invite/{token}[/accept]` | Resolve an invite link; accept it |
| GET | `/api/conversations` | Thread list, `?q=` filters by exact number |
| GET | `/api/conversations/{id}` | Full thread; marks inbound messages read |
| GET | `/api/conversations/{id}/messages?after_id=` | Messages newer than a cursor (polling / push fetch) |
| GET | `/api/conversations/updates?since=` | Threads whose activity moved since a server timestamp |
| GET, POST | `/api/messages` | Inbox (`?q=` filter); send |
| GET, POST | `/api/messages/{id}[/reply]` | Read one message (marks it read); reply to it |
| GET | `/api/numbers/{id}/messages` | Inbox scoped to a single number |
| GET | `/api/messages/compose-data` | Active categories with their active, non-reply templates |
| GET, POST, DELETE | `/api/favorites[/{id}]` | Starred numbers |
| PATCH | `/api/status` | Change presence, releasing queued messages |

Rate limits: 60 requests/minute per user (`api`), 5/minute per email+IP on login, and a separate 60/minute budget for the polling endpoints.

## Getting started

### Requirements

| Component | Version | Purpose |
|---|---|---|
| PHP | 8.1+ | Runs the application |
| Composer | 2.x | PHP dependency manager |
| MySQL / MariaDB | 8.0+ / 10.6+ | Database |
| Node.js & npm | 18 LTS+ (20 LTS recommended) & 9.x+ | Compiles frontend assets (Vite) |
| Apache / Nginx | 2.4 / latest | Production web server (not needed for local dev) |

### Installation

```bash
# 1. Clone the repository
git clone https://github.com/ArnasGlo/notifications_web_app.git
cd notifications_web_app

# 2. Install PHP dependencies
composer install

# 3. Install frontend dependencies
npm install

# 4. Create your .env file and generate an app key
cp .env.example .env
php artisan key:generate

# 5. Create the database, then set DB_* in .env to match
mysql -u root -p -e "CREATE DATABASE notifications CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 6. Run migrations
php artisan migrate

# 7. Seed message categories/templates and a test user, plus an admin account
php artisan db:seed
php artisan db:seed --class=AdminUserSeeder

# 8. Build frontend assets
npm run dev     # local development, with hot reload
# npm run build  # production build
```

### Running locally

In two terminals:

```bash
php artisan serve   # http://127.0.0.1:8000
npm run dev          # serves/watches frontend assets
```

Open `http://127.0.0.1:8000` in your browser.

### Running the tests

```bash
php artisan test                       # full suite
php artisan test --filter=SomeTest     # one test class or method
./vendor/bin/pint                      # PSR-12 formatting
```

The suite runs against an in-memory SQLite database (configured in `phpunit.xml`), so it never touches your development data and needs no extra setup.

### Default seeded accounts

| Role | Email | Password |
|---|---|---|
| Administrator | `admin@example.com` | `password` |
| Regular user | `user@example.com` | `password123` |

⚠️ Change these before deploying anywhere real.

### Key `.env` settings

```env
APP_NAME="Notifications Web App"
APP_ENV=local            # production in prod
APP_DEBUG=true            # false in prod
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=notifications
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=file
CACHE_DRIVER=file
QUEUE_CONNECTION=sync

MAIL_MAILER=smtp          # needed for password-reset emails
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
```

In development, the default file-based session/cache and synchronous queue need no extra services. In production, set `APP_ENV=production`, `APP_DEBUG=false`, and point `DB_*`/`MAIL_*` at real services.

## Usage

- **Log in** at `/login`. Admins additionally see an "Admin" link in the top bar.
- **Numbers** — add/edit/activate/deactivate a number from the "Numbers" menu.
- **Delegate access** — open a number's assistants page, copy its delegation link, and send it to whoever should manage it. They accept the link while logged in to become an assistant; you can revoke access from the same page.
- **Send a message** — "Compose" → pick one of your own active numbers as sender and look up a receiver number, then write the message (press `/` to insert a saved template). You land in the conversation the message joined.
- **Read & reply** — open a conversation to see the full history; opening it marks incoming messages as read, and the composer at the bottom replies in the same thread.
- **Favorites** — star a number from a conversation header or the Favorites page to keep a shortcut to that thread.
- **Live updates** — the conversation list and an open chat refresh themselves every few seconds, so incoming messages appear without reloading. Polling pauses while the browser tab is in the background.
- **Change status** — toggle Active / Busy / Do Not Disturb from the top bar; this immediately affects how your numbers handle incoming messages.
- **Blocking** — add rules (by number, user, city, or country) from a number's blocking page.
- **Admin panel** — `/admin/dashboard`, for managing users, numbers, categories, and templates.

## Possible improvements

Ideas identified but out of scope for the internship timeline:

- Real-time message delivery over WebSockets instead of the current polling (the update endpoints are already the ones a push event would trigger)
- Push notifications for the mobile client — the backend has no broadcasting layer yet
- A proper queue worker instead of the synchronous driver
- Actual use of the unused `blocked` message status, instead of silently rejecting blocked sends
- UI localization for non-English/Lithuanian users

## Background

This project was built as the practical deliverable for a professional internship (Vilniaus Kolegija, Faculty of Electronics and Informatics) hosted by UAB Getweb. Full requirements analysis, UML diagrams, and implementation notes are documented in the accompanying internship report.

The next stage is a native Android client (Kotlin) built on the JSON API documented above.
