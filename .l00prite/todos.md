# Prioritized TODOs

Audit scope (2026-07-15): **core platform only** — bootstrap, engine, REST, shortcodes,
traits, admin, shared assets. **Excluded:** per-game modules under `includes/games/` and
`assets/js/games/` / `assets/css/games/` (except where core hardcodes game-specific phases).

## Next (P0 — correctness / security)

- [ ] **P0.1 Room lifecycle cleanup uses inactivity `expires_at`** — `cleanup_expired_rooms()` only deletes completed rooms by `expires_at` and non-completed rooms by hard-cap `created_at`. Lobby/active rooms are never cleaned for inactivity despite `touch_room()` updating `expires_at`. Fix query so inactive lobby/active rooms expire correctly without waiting for hard cap.
- [ ] **P0.2 Authorize room mutations by membership** — `start_game`, `add_ai`, and similar write endpoints only require a valid guest/user token, not that the caller is a player in that room. Anyone who knows a room code can start the game or fill seats with AI. Require seat membership (or host role) before these actions.
- [ ] **P0.3 Multi-player timeout / forfeit winners** — move-timeout auto-forfeit and forfeit endpoints assume two seats (`opponent_seat = current_turn === 0 ? 1 : 0`). Breaks 3–4 player games. Compute winners from remaining human seats / game rules, not fixed binary opponent.
- [ ] **P0.4 Gate vs column `current_turn` consistency** — Turn gate uses `-1` in JSON state; `SACGA_Game_State` still documents/handles SQL `NULL`; AI engine still checks `current_turn === null` in places. Normalize on one sentinel (`-1` preferred given DB history), document it, and align create/update/read + AI/REST turn checks.
- [ ] **P0.5 Gate unconditional `error_log` noise** — Cribbage-specific and turn-failure `error_log` calls in REST/AI run without `WP_DEBUG` guards. Gate all debug logs behind `WP_DEBUG` (or a plugin debug option).

## Next (P1 — reliability / scale)

- [ ] **P1.1 Optimistic concurrency on moves** — etag locking exists but `generate_etag()` mixes `microtime(true)`, so etags are not pure state hashes; concurrent `apply_move` lacks DB-level transactions/`FOR UPDATE`. Add transactional read-modify-write (or version column CAS) around moves.
- [ ] **P1.2 Rate-limit moves and AI-triggering polls** — rate limits only wrap create/join. Unbounded `make_move` and poll-driven `process_ai_turns` can hammer DB. Rate-limit moves; consider AI work queue / “AI only after human move” so GETs don’t always drive AI.
- [ ] **P1.3 Asset loading misses shortcodes / block content** — `should_load_assets()` only checks `sacga_game`, `classic_games_arcade`, `sacga_rules` on `$post->post_content`. Misses `[sacga_available_rooms]`, block/widget/template shortcodes, and multi-page builders. Expand detection or always enqueue when shortcode renders (shortcode-driven enqueue).
- [ ] **P1.4 Public room / state exposure** — GET room and state are open (`__return_true`). Responses strip raw tokens but still expose structure useful for probing. Decide minimum public surface (code existence vs full player list) and document privacy model.
- [ ] **P1.5 Rejoin auth is client_id possession** — `rejoin-check` trusts `client_id` from localStorage with no guest token binding. Steal/guess risk is low for UUIDs but identity should bind `client_id` + guest/user. Tighten before shipping as security-sensitive multiplayer.

## Later (P2 — architecture / product)

- [ ] **P2.1 Extract game-specific AI phases from core** — `SACGA_AI_Engine` hardcodes Hearts passing, Cribbage discard, Overcut rolloff, simultaneous bids. Add contract hooks (e.g. `ai_needs_action`, `ai_select_move`) so core stays game-agnostic.
- [ ] **P2.2 Host / room roles** — no host seat: any member (or outsider after P0.2) can start/add AI. Introduce host = creator seat; only host starts game / kicks / adds AI (configurable).
- [ ] **P2.3 `sacga_is_pro()` stub always true** — Pro badge and gating are fake. Either wire real licensing or remove Pro UI until real.
- [ ] **P2.4 Version / license alignment** — Plugin header `Version: 0.1.0` / `SACGA_VERSION` vs branch `1.2.2-build` / `readme.txt` stable tag; header `GPL-2.0+` vs `readme.txt` GPLv3. Align before WordPress.org / release.
- [ ] **P2.5 Schema migration hygiene** — `sacga_schema_version` is 4 but “Migration 5” (nullable `current_turn`) runs inside that version block. Bump schema version cleanly per migration; add automated migration tests.
- [ ] **P2.6 Admin observability** — settings only expiration + default difficulty. Add active room counts, failed table notice actions, cleanup last-run, optional debug toggle.
- [ ] **P2.7 Polling cost** — default `pollInterval` 2000ms × open rooms. Consider longer idle poll, Page Visibility API pause, or SSE/WebSockets later. Document expected load.
- [ ] **P2.8 Shared card/dice assets always loaded** — engine enqueue always loads cards + dice even for board-only pages. Split dependencies by game type at shortcode render time.
- [ ] **P2.9 Column naming: `guest_token` stores guest_id** — DB column / player field named `guest_token` but stores UUID guest_id, not HMAC token. Rename (migration) or document clearly to avoid future seat bugs.
- [ ] **P2.10 i18n / languages pack** — `wp_set_script_translations` points at `languages/` but no pot/po shipped. Generate pot and document translation workflow.

## Later (P3 — quality / DX)

- [ ] **P3.1 Automated tests** — no PHPUnit/WP test suite. Add unit tests for: guest token sign/verify, seat lookup, turn-gate open/close, room cleanup query, rate limit, move CAS.
- [ ] **P3.2 Real CI** — `.github/workflows/ci.yml` is a placeholder. Add PHP lint, PHPCS (WordPress-Coding-Standards), and tests on PR.
- [ ] **P3.3 API docs** — expand `docs/api.md` with full `sacga/v1` routes, headers (`X-WP-Nonce`, `X-SACGA-Guest-Token`, `X-SACGA-Client-ID`), error codes, rate limits.
- [ ] **P3.4 Architecture overview** — expand `docs/overview.md` with room lifecycle diagram, gate model, polling/AI loop.
- [ ] **P3.5 Composer / autoload optional** — still manual `require_once` tree; fine for WP plugin but document load order; consider classmap autoload if file count grows.
- [ ] **P3.6 Reduce always-on table creation logging** — `create_tables()` logs heavily even outside WP_DEBUG. Restrict to failures or debug mode.

## Game-layer (explicitly deferred — not in this audit)

- Per-game rule bugs, AI strength, UI polish under `includes/games/*` and `assets/js/games/*`
- Per-game CSS
- AI support matrix documentation (still useful later)

## Done

- [x] 2026-07-15 — Scaffolded l00prite protocol (PR #13)
- [x] 2026-07-15 — Core platform audit (games code excluded); improvements written to memory/todos
