# Android App Context

This document is background for **planning** a native Android app that replicates the functionality of this Laravel web app ("Notifications Web App"). It's the product of a full-codebase audit (models, controllers, migrations, routes, Blade views, JS, config/auth infrastructure) — not itself an implementation plan for the Android app. Use it as the shared source of truth when a real Android project plan gets written.

## 1. Product overview

Users own communication **"numbers"** (channels) and can **delegate access** to a number to another user — an "assistant" — without ever sharing login credentials, via a shareable UUID invite link. Numbers exchange **pre-written templated messages** (not free text), organized into categories. Delivery is gated by the recipient's presence status and per-number blocking rules.

Three business rules govern every send:

1. **Delivery eligibility** — a message is only accepted if the receiving number's owner isn't `dnd` **and** the sender doesn't match any of the receiver's blocking rules (by number, user, city, or country).
2. **Status routing** — if the receiver's owner is `busy`, the message is stored as `queued`; otherwise `sent`.
3. **Queue release** — when a user's status returns to `active`, every `queued` message addressed to their numbers flips to `sent`, synchronously, as part of the status-update request.

Self-registration is disabled — accounts are created by an administrator (or seeders) only. There are two roles: regular user and admin, gated by a boolean `is_admin` flag (no granular permissions).

## 2. Domain model & schema

Exact columns as defined in `database/migrations/`:

### `users`
| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint PK | | | |
| name | string | no | | |
| email | string | no | | **unique** |
| status | enum('active','busy','dnd') | no | 'active' | |
| is_admin | boolean | no | false | |
| email_verified_at | timestamp | yes | null | |
| password | string | no | | bcrypt |
| remember_token | string(100) | yes | null | |
| timestamps | | | | |

### `numbers`
| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint PK | | | |
| user_id | FK → users.id | no | | cascade delete |
| number | string | no | | **unique**, e.g. `+37060000001`; immutable after creation |
| country | string | yes | null | |
| city | string | yes | null | |
| status | enum('active','inactive') | no | 'active' | inactive numbers can't send/receive |
| share_token | uuid | no | | **unique**; auto-generated on create (`Str::uuid()`); not rotatable |
| timestamps | | | | |

### `number_delegates` (model `Delegate`)
| Column | Type | Null | Notes |
|---|---|---|---|
| id | bigint PK | | |
| number_id | FK → numbers.id | no | cascade delete |
| assistant_user_id | FK → users.id | no | cascade delete |
| timestamps | | | **unique(number_id, assistant_user_id)** |

### `blocks`
| Column | Type | Null | Notes |
|---|---|---|---|
| id | bigint PK | | |
| number_id | FK → numbers.id | no | cascade delete; "who is blocking" |
| type | string | no | `number` \| `user` \| `city` \| `country` (app-level enum, not DB-enforced) |
| value | string | no | exact-match value (e.g. a number string, a numeric user ID as string, a city/country name) |
| timestamps | | | |

### `message_categories`
| Column | Type | Null | Default |
|---|---|---|---|
| id | bigint PK | | |
| name | string | no | |
| icon | string | yes | null (FontAwesome class, e.g. `fas fa-calendar`) |
| is_active | boolean | no | true |
| timestamps | | | |

### `message_templates`
| Column | Type | Null | Default |
|---|---|---|---|
| id | bigint PK | | |
| category_id | FK → message_categories.id | no | cascade delete |
| body | string | no | max 255 chars (enforced client-side/admin form) |
| is_reply | boolean | no | false |
| is_active | boolean | no | true |
| timestamps | | | |

### `messages`
| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint PK | | | |
| sender_number_id | FK → numbers.id | no | | no cascade (RESTRICT on delete) |
| receiver_number_id | FK → numbers.id | no | | no cascade |
| template_id | FK → message_templates.id | no | | no cascade |
| parent_id | FK → messages.id (self) | **yes** | null | set for replies, threading |
| status | enum('sent','queued','blocked','read') | no | 'sent' | `blocked` is defined but **never actually used** — a blocked send just returns a flash error and nothing is persisted |
| read_at | timestamp | yes | null | set when the receiver opens the message |
| timestamps | | | |

**Relationships**: one user → many numbers (1:N). User ↔ number delegation is many-to-many via `number_delegates`. A number has many `blocks` and many `messages` (as sender or receiver). `messages.parent_id` self-references for reply threads (currently one level deep only). Referential integrity uses FKs; uniqueness on `numbers.number`, `numbers.share_token`, and `(number_id, assistant_user_id)`.

## 3. Business rules to preserve exactly

- **Number uniqueness/immutability** — `number` is globally unique and cannot be changed after creation (only country/city/status are editable); enforced by `unique:numbers,number` validation, and the edit form disables the field.
- **Access model** — `Number::isAccessibleBy(User)` = owner (`user_id` match) OR has a `Delegate` row. Assistants can **view and reply to** messages on a delegated number, but **cannot**: initiate a new outbound message from it (compose requires `sender->user_id === auth()->id()`), manage its blocking rules, manage its other delegates, or edit/delete the number itself.
- **Presence routing** — `dnd` → message rejected outright, nothing persisted. `busy` → persisted with `status='queued'`. Returning to `active` bulk-flips all of that user's **received** `queued` messages to `sent` (`StatusController::update`), synchronously, no queue worker involved.
- **Blocking** — `Number::canReceiveFrom(Number $sender)`: exact-match only (no partial/wildcard) against the sender number's `number`, `user_id` (compared as a string), `city`, or `country`. Checked only at send time, only against the sender's `Number` record fields.
- **Templates/categories** — only `is_active` categories, and only `is_active && !is_reply` templates, appear in the initial compose flow. Replies are limited to `is_active && is_reply` templates **within the same category** as the original message. Message body is capped at 255 characters. **One reply per message** — soft-enforced (checked by whether a reply already exists), not a DB constraint. Replies **bypass** the DND/blocking/busy-queue checks entirely and are always stored as `sent` — this is a real asymmetry vs. the initial-compose flow, worth a deliberate decision either way in the new design.
- **Read receipts** — opening a message (`GET` as an accessible receiver) auto-marks it `read` with a `read_at` timestamp; there's no separate "mark as read" action.
- **Share-token invite flow** — UUID generated once on number creation; `/invite/{token}` resolves to the number; accepting creates a `Delegate` via `firstOrCreate` (idempotent, safe to hit twice); the owner gets an error if they try to accept their own link.

**Two things found during the audit that should *not* be carried into the Android/API design:**
- `NumberController::edit()` calls `$this->authorize('update', $number)` but no `NumberPolicy`/Gate is registered anywhere in the app (`AuthServiceProvider::$policies` is empty) — this ability **always denies**, so the web "edit number" page is effectively broken today. Use the same manual `abort_unless($number->user_id === auth()->id())` pattern every other controller method already uses correctly.
- `app/Models/Contact.php` / `app/Http/Controllers/ContactController.php` reference a `contacts` table that a later migration (`2026_05_26_000002_drop_contacts_table.php`) drops. This is dead code from before the `Delegate`/`number_delegates` mechanism replaced it — ignore it entirely.

## 4. Screen-by-screen UX reference

Source: `resources/views`. Each maps to a likely Android screen.

- **Login / Forgot password / Reset password** — email+password login, "remember me"; forgot-password sends a reset email; reset form takes token+email+new password. **No registration screen** (self-signup disabled).
- **Numbers — list** — "My Numbers" (cards: number, city/country, active/inactive badge, assistant count, per-card invite link with copy-to-clipboard) plus a second "Numbers I Assist" section (numbers owned by others where the user is a delegate, with an owner name and a "View Messages" shortcut into that number's inbox).
- **Numbers — create** — phone number (required, globally unique), country, city (both optional).
- **Numbers — edit** — number field read-only; country/city/status (active/inactive) editable; invite link + copy button; a "danger zone" delete action.
- **Delegates ("Assistants") screen** (owner-only) — full invite link with copy button; list of current assistants (name, email, since-date) each with a "Revoke" action.
- **Invite accept landing page** (`/invite/{token}`) — has 4 distinct states to design for: (a) guest → "Log in to accept" (should deep-link back to this invite after login), (b) the owner viewing their own link → no accept action, link to view assistants instead, (c) already an assistant → "Go to inbox", (d) new user → "Accept & Become Assistant" button.
- **Blocking rules — list** — type (icon+badge), value, added-date, delete action.
- **Blocking rules — create** — type selector (number / user / city / country) with type-dependent hint/placeholder text (e.g. for `user`, the value is literally a numeric user ID — worth reconsidering as a user search/picker in the native app instead of asking for a raw ID).
- **Inbox (unified + per-number)** — paginated, top-level messages only (replies nested under their parent, not shown flat); per-row: direction, read/unread/queued visual cue, other party's number, "Sent" vs "Assistant" badge, category badge, template body preview, relative timestamp, "Queued (recipient busy)" note where relevant.
- **Compose (3-step wizard)** — Step 1: pick one of *your own active* numbers as sender. Step 2: type a number and look it up (exact match, active only) before it's accepted as receiver. Step 3: pick a category, then a non-reply active template from that category (categories+templates can be fetched once and filtered client-side, mirroring the web app's pattern of embedding them as one JSON payload). Each step unlocks the next only once the prior one is chosen.
- **Message thread / reply** — shows the message + any existing reply; opening it marks it read; a reply composer (single template picker, one reply per message) appears only while eligible; if the current user was the original sender, no reply UI is shown to them.
- **Status switcher** — active/busy/dnd toggle (e.g. in a top bar/drawer), each with a short explainer of what it means for incoming messages.
- **Admin panel** (users/numbers/categories/templates CRUD) exists on the web but is recommended as **out of scope for a v1 Android app** — see Recommendations.

## 5. Current backend gaps vs. an Android client

This backend is a traditional server-rendered monolith and is **not API-ready today**:

- Auth is 100% session-cookie + CSRF (`web` middleware group); there is no token-based auth guard configured (`config/auth.php` only defines the `web` guard).
- `laravel/sanctum` is a dependency and `User` has the `HasApiTokens` trait, but it's not actually wired up: `EnsureFrontendRequestsAreStateful` is commented out in the `api` middleware group (`app/Http/Kernel.php`), and no route anywhere calls `$user->createToken(...)` — there is no login-for-mobile/token-issuance endpoint.
- `routes/api.php` contains only the Laravel-default `GET /api/user` (unreachable in practice since nothing issues a usable token).
- The one real JSON endpoint that exists, `GET /api/numbers/lookup`, is defined inline inside `routes/web.php` and runs under the session-authenticated `web` group — it is not a token-protected API route.
- No Policies/Gates are registered anywhere (`AuthServiceProvider::$policies` is empty); all authorization today is ad hoc `abort_unless()` checks inside controllers.
- No broadcasting/push infrastructure: `resources/js/bootstrap.js` has Laravel Echo/Pusher fully commented out, `config/broadcasting.php` is unconfigured, and the only defined channel is the unused stock per-user channel. All "new message" awareness today is via page reload, not push.
- Queue driver is `sync` (no background worker); there are no scheduled/cron tasks (`Console/Kernel.php::schedule()` is empty) — the queue-release logic runs inline in the status-update request.
- A default `api` rate limiter (60 req/min, keyed by user or IP) is already configured and can be reused as-is.

**Bottom line: essentially nothing exists to reuse beyond the DB schema and business logic itself.** A real API layer has to be designed and built before an Android app can talk to this backend.

## 6. Proposed API contract

This is a **proposal** for what the Laravel backend would need to add — none of it exists yet.

**Auth** (token-based, not session-based):
- `POST /api/login` — email + password → issues a Sanctum personal access token (`$user->createToken('android')->plainTextToken`).
- `POST /api/logout` — revokes the current token.
- All other endpoints behind `auth:sanctum` (bearer token), reusing the existing `api` rate limiter.

**Resource endpoints** (mirroring today's web controllers and their existing validation/authorization — *not* the broken `edit`-authorize bug):

| Method | Path | Mirrors |
|---|---|---|
| GET, POST | `/api/numbers` | `NumberController@index,store` |
| PATCH, DELETE | `/api/numbers/{id}` | `NumberController@update,destroy` (manual ownership check, not `authorize()`) |
| GET | `/api/numbers/search?number=` | existing inline lookup route (path changed from the original `/api/numbers/lookup` proposal — see envelope note below) |
| GET | `/api/numbers/{id}/delegates` | `DelegateController@index` |
| DELETE | `/api/numbers/{id}/delegates/{delegateId}` | `DelegateController@destroy` |
| GET | `/api/invite/{token}` | `InviteController@show` |
| POST | `/api/invite/{token}/accept` | `InviteController@accept` |
| GET, POST | `/api/numbers/{id}/blocks` | `BlockController@index,store` |
| DELETE | `/api/numbers/{id}/blocks/{blockId}` | `BlockController@destroy` |
| GET | `/api/messages` | `MessageController@index` |
| GET | `/api/numbers/{id}/messages` | `MessageController@numberInbox` |
| GET | `/api/messages/compose-data` | active categories + their active, non-reply templates |
| POST | `/api/messages` | `MessageController@store` |
| GET | `/api/messages/{id}` | `MessageController@show` (auto-marks read as a side effect, same as today) |
| POST | `/api/messages/{id}/reply` | `MessageController@reply` |
| PATCH | `/api/status` | `StatusController@update` |

Each endpoint should replicate the exact validation rules and authorization checks found in its corresponding controller (see section 3), returning JSON resources/paginated collections rather than Blade views. Laravel already renders validation errors as JSON automatically for JSON-accepting requests, so no custom error-envelope work is strictly required, though a consistent shape is worth deciding on up front.

**Response envelope convention (decided when building `GET /api/numbers/lookup`, applies to every endpoint in the table above):**
- Validation: a Form Request per endpoint (`app/Http/Requests/Api/`), not inline `$request->validate()` — Laravel's default `422` shape is used as-is.
- Single resource: wrap in a dedicated API Resource (`app/Http/Resources/`) and return it directly — Laravel wraps it as `{"data": {...}}` automatically.
- Collections/lists: return a `ResourceCollection` (or `Resource::collection(...)`) so pagination comes through Laravel's default shape — `{"data": [...], "links": {...}, "meta": {...}}`.
- Not-found: `abort_unless($model, 404)` / `abort(404)` — Laravel's default `{"message": "..."}` shape, no hand-rolled error bodies.
- This first got applied to the lookup endpoint specifically because the old inline `routes/web.php` version returned a bare model array on a hit and a bare `{}` on a miss with always-`200` status — awkward as a general API contract, and worth fixing before 12 more endpoints copy the pattern. That inline route itself was **kept as-is** (not removed) because `resources/views/messages/compose.blade.php`'s Step 2 JS still depends on its exact shape; the new `auth:sanctum` route in `routes/api.php` is a separate, additive endpoint per the Phase 0 "don't touch existing Blade views/web routes" rule.
- **Path note:** the new route lives at `GET /api/numbers/search`, not `/api/numbers/lookup` as originally proposed. The old `routes/web.php` route hardcodes the literal path `/api/numbers/lookup` (an API-shaped path oddly living in the session-auth web file). `routes/api.php` auto-prefixes with `api/`, so a same-named new route would resolve to the identical literal URI + HTTP method — and Laravel's `RouteCollection` stores routes keyed by `method+domain+uri` in a plain array (`addToCollections()`), so the later-registered route (web.php loads after api.php per `RouteServiceProvider`) silently overwrites the earlier one. Two routes cannot coexist at the same URI+method regardless of middleware/guard, so the new endpoint had to move. This collision is isolated to this one endpoint — no other web.php route uses an `/api/...` path — so it shouldn't recur for the rest of the §6 table.

## 7. Recommendations

- **v1 scope**: auth, numbers CRUD, delegation/invite (support deep-linking `/invite/{token}` URLs directly into the app), inbox (unified + per-number), the compose wizard, message thread/reply, the status switcher, and blocking rules. **Defer the admin panel** — treat it as web-only for now; it manages users/categories/templates/numbers globally and isn't part of the core end-user flow.
- **Architecture**: Kotlin + Jetpack Compose, MVVM, Retrofit/OkHttp against the proposed API above; store the Sanctum bearer token via `EncryptedSharedPreferences`/DataStore.
- **Real-time**: since the web app itself has no push/real-time layer today (Echo/Pusher fully commented out), don't block v1 on building that from scratch. Start with polling (matching current web behavior, which is also just reload-driven) and treat FCM push as a fast-follow once/if the backend adds broadcasting support.
- **Localization**: no `lang/lt` (or any app-level `lang/`) exists server-side despite the project's Lithuanian origin — all UI text is hardcoded English in Blade. Android string resources for any additional languages will need original translation work, not extraction from the backend.
- **Visual design**: there's no strict brand system to replicate — the web app is stock Bootstrap 5 + Nunito font with a muted background, no custom component library. Free to use native Material Design idioms; just keep the same functional color semantics used throughout the web app (green = active/sent, yellow = busy/queued, red = dnd/blocked, blue = primary actions, grey = inactive/read).
