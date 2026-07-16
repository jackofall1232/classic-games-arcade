# Prioritized TODOs

Audit scope (2026-07-15): **core platform only** — bootstrap, engine, REST, shortcodes,
traits, admin, shared assets. **Excluded:** per-game modules under `includes/games/` and
`assets/js/games/` / `assets/css/games/` (except where core hardcodes game-specific phases).

## Next (P0 — correctness / security)

- [x] **P0.1 Room lifecycle cleanup uses inactivity `expires_at`** — `cleanup_expired_rooms()` only deletes completed rooms by `expires_at` and non-completed rooms by hard-cap `created_at`. Lobby/active rooms are never cleaned for inactivity despite `touch_room()` updating `expires_at`. Fix query so inactive lobby/active rooms expire correctly without waiting for hard cap. (Completed 2026-07-16)
- [x] **P0.2 Authorize room mutations by membership** — `start_game`, `add_ai`, and similar write endpoints only require a valid guest/user token, not that the caller is a player in that room. Anyone who knows a room code can start the game or fill seats with AI. Require seat membership (or host role) before these actions. (Completed 2026-07-16)
- [x] **P0.3 Multi-player timeout / forfeit winners** — move-timeout auto-forfeit and forfeit endpoints assume two seats (`opponent_seat = current_turn === 0 ? 1 : 0`). Breaks 3–4 player games. Compute winners from remaining human seats / game rules, not fixed binary opponent. (Completed 2026-07-16)
- [x] **P0.4 Gate vs column `current_turn` consistency** — Turn gate uses `-1` in JSON state; `SACGA_Game_State` still documents/handles SQL `NULL`; AI engine still checks `current_turn === null` in places. Normalize on one sentinel (`-1` preferred given DB history), document it, and align create/update/read + AI/REST turn checks. (Completed 2026-07-16)
- [x] **P0.5 Gate unconditional `error_log` noise** — Cribbage-specific and turn-failure `error_log` calls in REST/AI run without `WP_DEBUG` guards. Gate all debug logs behind `WP_DEBUG` (or a plugin debug option). (Completed 2026-07-16)

## Next (P1 — reliability / scale)

- [x] **P1.1 Optimistic concurrency on moves** — etag locking exists but `generate_etag()` mixes `microtime(true)`, so etags are not pure state hashes; concurrent `apply_move` lacks DB-level transactions/`FOR UPDATE`. Add transactional read-modify-write (or version column CAS) around moves. (Completed 2026-07-16)
- [x] **P1.2 Rate-limit moves and AI-triggering polls** — rate limits only wrap create/join. Unbounded `make_move` and poll-driven `process_ai_turns` can hammer DB. Rate-limit moves; consider AI work queue / “AI only after human move” so GETs don’t always drive AI. (Completed 2026-07-16)
- [x] **P1.3 Asset loading misses shortcodes / block content** — `should_load_assets()` only checks `sacga_game`, `classic_games_arcade`, `sacga_rules` on `$post->post_content`. Misses `[sacga_available_rooms]`, block/widget/template shortcodes, and multi-page builders. Expand detection or always enqueue when shortcode renders (shortcode-driven enqueue). (Completed 2026-07-16)
- [x] **P1.4 Public room / state exposure** — GET room and state are open (`__return_true`). Responses strip raw tokens but still expose structure useful for probing. Decide minimum public surface (code existence vs full player list) and document privacy model. (Completed 2026-07-16)
- [x] **P1.5 Rejoin auth is client_id possession** — `rejoin-check` trusts `client_id` from localStorage with no guest token binding. Steal/guess risk is low for UUIDs but identity should bind `client_id` + guest/user. Tighten before shipping as security-sensitive multiplayer. (Completed 2026-07-16)
- [x] **P1.6 Dynamic, Visibility-Aware Polling** — Use Page Visibility API (`document.hidden`) to pause polling when hidden, and implement exponential backoff/idle slowdown to reduce server database load. (Completed 2026-07-16)

## Later (P2 — architecture / product)

- [x] **P2.1 Extract game-specific AI phases from core** — `SACGA_AI_Engine` hardcodes Hearts passing, Cribbage discard, Overcut rolloff, simultaneous bids. Documented dynamic hook contracts (e.g., `process_simultaneous_ai`) to let core stay agnostic. (Completed 2026-07-16)
- [x] **P2.2 Host / room roles** — no host seat: any member (or outsider after P0.2) can start/add AI. Introduce host = creator seat; only host starts game / kicks / adds AI (configurable). (Completed 2026-07-16)
- [x] **P2.3 `sacga_is_pro()` stub always true** — Pro badge and gating are fake. Cleaned up Pro UI badges and aligned to standard GPLv3 Open Source license. (Completed 2026-07-16)
- [x] **P2.4 Version / license alignment** — Plugin header `Version: 1.2.2` / `SACGA_VERSION` vs branch `1.2.2-build` / `readme.txt` stable tag; header updated to `GPLv3` and aligned. (Completed 2026-07-16)
- [x] **P2.5 Schema migration hygiene** — `sacga_schema_version` aligned cleanly to version 5 to match active Migration 5. (Completed 2026-07-16)
- [x] **P2.6 Admin observability** — settings only expiration + default difficulty. Add active room counts, failed table notice actions, cleanup last-run, optional debug toggle. (Completed 2026-07-16)
- [x] **P2.7 Polling cost** — default `pollInterval` 2000ms × open rooms. Fully optimized via visibility-aware, backoff setTimeout loops (P1.6). (Completed 2026-07-16)
- [x] **P2.8 Shared card/dice assets always loaded** — engine enqueue always loads cards + dice even for board-only pages. Split dependencies by game type at shortcode render time. (Completed 2026-07-16)
- [x] **P2.9 Column naming: `guest_token` stores guest_id** — DB column / player field named `guest_token` but stores UUID guest_id, not HMAC token. Documented clearly in class comments to avoid future seat bugs. (Completed 2026-07-16)
- [x] **P2.10 i18n / languages pack** — `wp_set_script_translations` points at `languages/` but no pot/po shipped. Documented i18n pot template generation workflow. (Completed 2026-07-16)
- [x] **P2.11 Read-Only Spectator Mode** — Bypass "Room Full" locks on the frontend for late visitors, rendering a read-only gameplay view with a spectator indicator. (Completed 2026-07-16)
- [x] **P2.12 Visual Disconnected Countdown Banners** — Leverage the 90-second disconnect grace period to display warning banners with countdown timers on the seats of offline players. (Completed 2026-07-16)
- [x] **P2.13 Local Guest Profiles & Persistence** — Let guest players choose custom names and colors stored in browser `localStorage` and sent with room join actions. (Completed 2026-07-16)
- [x] **P2.14 Comprehensive WP Admin Dashboard** — Build an observability dashboard with active/completed room metrics, manual room cleanup actions, a live error/debug log visualizer, and global limit controls. (Completed 2026-07-16)
- [x] **P2.15 Gemini Bot Chat Admin Settings** — Register options to toggle Gemini bot commentary on/off, securely save the API key, select models (e.g. gemini-1.5-flash), and adjust commentary frequency (every X turns). (Completed 2026-07-16)
- [x] **P2.16 Gemini Bot Commentary API Engine** — Build a server-side curl wrapper and prompt template generator in PHP that calls Gemini 1.5 Flash when bots take turns, generating fun contextual "trash talk" or moves analysis. (Completed 2026-07-16)
- [x] **P2.17 Frontend Bot Speech Bubbles** — Design and render stylish, floating, animated CSS speech bubbles above the bot avatars on the table to showcase their real-time Gemini commentary. (Completed 2026-07-16)

## Later (P3 — quality / DX)

- [x] **P3.1 Automated tests** — no PHPUnit/WP test suite. Add unit tests for: guest token sign/verify, seat lookup, turn-gate open/close, room cleanup query, rate limit, move CAS. (Completed 2026-07-16)
- [x] **P3.2 Real CI** — `.github/workflows/ci.yml` is a placeholder. Add PHP lint, PHPCS (WordPress-Coding-Standards), and tests on PR. (Completed 2026-07-16)
- [x] **P3.3 API docs** — expand `docs/api.md` with full `sacga/v1` routes, headers (`X-WP-Nonce`, `X-SACGA-Guest-Token`, `X-SACGA-Client-ID`), error codes, rate limits. (Completed 2026-07-16)
- [x] **P3.4 Architecture overview** — expand `docs/overview.md` with room lifecycle diagram, gate model, polling/AI loop. (Completed 2026-07-16)
- [x] **P3.5 Composer / autoload optional** — still manual `require_once` tree; fine for WP plugin but document load order; consider classmap autoload if file count grows. (Completed 2026-07-16)
- [x] **P3.6 Reduce always-on table creation logging** — `create_tables()` logs heavily even outside WP_DEBUG. Restrict to failures or debug mode. (Completed 2026-07-16)

## Game-layer / AI / Rules (P4)

- [ ] **P4.1 Chess Check & Checkmate Detection** — Implement full board virtual check-scanning in `validate_move`. Reject moves that leave the King in check, and properly trigger `game_over` on checkmate.
- [ ] **P4.2 Chess Advanced Mechanics** — Add state tracking and validation for Castling and En Passant captures.
- [ ] **P4.3 Chess Minimax AI** — Build a lightweight Alpha-Beta pruning Minimax heuristic AI to handle single-player chess.
- [ ] **P4.4 Checkers Forced Jumps** — Override valid moves if a capture is available (enforcing the mandatory jump rule) and implement multi-jump chaining.
- [ ] **P4.5 Backgammon Bear-Off Validation** — Enforce that checkers can only be borne off when all 15 are in the home quadrant. Add doubling cube mechanics.
- [ ] **P4.6 Spades Strict Reneging** — Validate that players follow the led suit if they possess it in their hand. Add Overtrick (bag) penalty tracking and Blind Nil bidding.
- [ ] **P4.7 Hearts "Shoot the Moon"** — Detect if a player captures all 26 points and invert the score penalty to opponents.
- [ ] **P4.8 Cribbage Server-Side Scoring** — Implement auto-scoring algorithms for combinations (fifteen-twos, runs, pairs, flushes, his knobs) for both the pegging phase and hand counting.
- [ ] **P4.9 Rummy Lay-offs & Discard Pulls** — Allow players to extend existing melds on the board and implement the traditional "Rummy!" call for deep discard pile pulls.
- [ ] **P4.10 Pig "Double Pig" 2-Dice Variant** — Implement a room setting for 2 dice, penalizing single 1s, bankrupting on double 1s, and forcing re-rolls on other doubles.
- [ ] **P4.11 Probability Games Virtual Wagers** — Add a 100-chip starting bank to "Even at Odds" and "Odd Man Out", requiring wager commitments per round.

## UX & 3D Aesthetics (P5)

- [ ] **P5.1 Fancy 3D Tumbling Dice** — Replace static text dice with interactive CSS 3D cubes (`transform: rotateX() rotateY()`) that tumble and settle on the server's rolled face, paired with haptic-like animations.
- [ ] **P5.2 Fluid CSS Coordinate Card Dealing** — Replace instantaneous card-draw swaps with smooth CSS3 translations, visually flying cards from a central deck graphic directly into the player's hand array.
- [ ] **P5.3 "Action Phrase" Emote Board** — Add a quick-chat emote panel ("Good game!", "Oops!") that visually floats text above the player's avatar.
- [ ] **P5.4 Integrated Audio Feedback** — Add an optional (muted-by-default) HTML5 audio layer for card shuffling, piece sliding, dice rattling, and victory trumpets.

## Done

- [x] 2026-07-15 — Scaffolded l00prite protocol (PR #13)
- [x] 2026-07-15 — Core platform audit (games code excluded); improvements written to memory/todos
- [x] 2026-07-16 — Game-layer & rules audit; mapped missing mechanics and prioritized modern 3D UI / CSS features.
