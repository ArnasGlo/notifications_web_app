# Phase 0 API — Progress Tracker

Tracks build status of the token-based JSON API proposed in `ANDROID_APP_CONTEXT.md` §6. Update this file as part of each task (per `CLAUDE.md`'s "one endpoint per task" workflow) — check off the row, note the controller/request/resource/test files, and record any decision that deviated from a literal reading of §6.

**Last updated:** 2026-09-03

## Infra (one-time setup — done)

- [x] Sanctum wired up: `EnsureFrontendRequestsAreStateful` intentionally left commented out (not needed for pure bearer-token auth); `User` already had `HasApiTokens`.
- [x] `phpunit.xml` points at sqlite `:memory:` — safe to run `php artisan test` without touching the dev DB.
- [x] Response envelope convention decided (§6): Form Request per endpoint, single resource → `JsonResource` (`{data: {...}}`), list → `ResourceCollection`/`::collection()`, not-found → `abort_unless(..., 404)`.

## Endpoints

| # | Endpoint | Status | Files | Notes |
|---|---|---|---|---|
| 1 | `POST /api/login` | ✅ Done | `Api\AuthController@login`, `AuthTest.php` | Issues Sanctum token via `createToken('android')`. |
| 2 | `POST /api/logout` | ✅ Done | `Api\AuthController@logout`, `AuthTest.php` | Revokes current token. |
| 3 | `GET /api/numbers/search?number=` | ✅ Done | `Api\NumberController@lookup`, `NumberLookupRequest`, `NumberLookupResource`, `NumberLookupTest.php` | Path moved from proposed `/api/numbers/lookup` — collides with an existing web.php route at that exact URI (see §6 note). Old web route left untouched. |
| 4 | `GET, POST /api/numbers` | ✅ Done | `Api\NumberController@index,store`, `NumberStoreRequest`, `NumberResource`, `AssistingNumberResource`, `NumberCrudTest.php` | `index` mirrors *both* the web controller's owned-numbers query *and* the Blade view's separate delegated-numbers query (`resources/views/numbers/index.blade.php:112-114`) — returns `{data: {owned: [...], assisting: [...]}}`, not just the literal controller method. Deliberate deviation, confirmed with user. |
| 5 | `PATCH, DELETE /api/numbers/{id}` | ✅ Done | `Api\NumberController@update,destroy`, `NumberUpdateRequest`, `NumberCrudTest.php` | Uses the manual `abort_unless($number->user_id === ...)` pattern from the web `update()`/`destroy()`, **not** the broken `$this->authorize()` from web `edit()` (no Policy registered — always denies). |
| 6 | `GET /api/numbers/{id}/delegates` | ⏳ Next | | Mirrors `DelegateController@index`. |
| 7 | `DELETE /api/numbers/{id}/delegates/{delegateId}` | ⬜ Not started | | Mirrors `DelegateController@destroy`. |
| 8 | `GET /api/invite/{token}` | ⬜ Not started | | Mirrors `InviteController@show` — 4 UX states per §4 (guest / owner / existing assistant / new user) worth checking whether they all need distinct API responses. |
| 9 | `POST /api/invite/{token}/accept` | ⬜ Not started | | Mirrors `InviteController@accept` — idempotent `firstOrCreate`, owner-can't-accept-own-link rule. |
| 10 | `GET, POST /api/numbers/{id}/blocks` | ⬜ Not started | | Mirrors `BlockController@index,store`. |
| 11 | `DELETE /api/numbers/{id}/blocks/{blockId}` | ⬜ Not started | | Mirrors `BlockController@destroy`. |
| 12 | `GET /api/messages` | ⬜ Not started | | Mirrors `MessageController@index`. |
| 13 | `GET /api/numbers/{id}/messages` | ⬜ Not started | | Mirrors `MessageController@numberInbox`. |
| 14 | `GET /api/messages/compose-data` | ⬜ Not started | | Active categories + active non-reply templates. |
| 15 | `POST /api/messages` | ⬜ Not started | | Must replicate exact order: DND rejection → blocking rejection → busy(`queued`)/active(`sent`) routing. |
| 16 | `GET /api/messages/{id}` | ⬜ Not started | | Auto-marks `read` as a side effect, same as web. |
| 17 | `POST /api/messages/{id}/reply` | ⬜ Not started | | One-reply-per-message, same-category template restriction, bypasses DND/blocking/busy checks (documented asymmetry vs. §3). |
| 18 | `PATCH /api/status` | ⬜ Not started | | Must replicate the synchronous bulk `queued`→`sent` flip on return to `active`. |

## Legend
✅ Done · ⏳ In progress / next up · ⬜ Not started
