# Durable Memory

## Project facts
- Repo: jackofall1232/classic-games-arcade
- Default branch for agent work: `1.2.2-build`
- WordPress plugin: Classic Games Arcade / Shortcode Arcade (prefix `SACGA_`, text domain `shortcode-arcade`)
- Recent merges: multiplayer seat identity on `make_move`; turn gate uses `-1` for open `current_turn`; l00prite protocol (PR #13)
- Stack: PHP 7.4+ / WordPress 6.3+, vanilla JS + jQuery, custom tables `wp_sacga_rooms`, `wp_sacga_room_players`, `wp_sacga_game_state`, REST `sacga/v1`

## Decisions
- Protocol tier: **medium** — modular multi-game plugin, single deployable package.
- Layout: keep `includes/`, `assets/`, `admin/` — no parallel `src/` tree.
- **2026-07-15 audit decision:** Prioritize platform (rooms/REST/gates/cleanup/auth) over per-game work until P0 items land.
- Turn-gate intended model: gates are not turns; `current_turn` suspended while gate open. Implementation currently uses `-1` in trait; state layer still mentions NULL — normalize to one sentinel.

## Architecture snapshot (non-game)
| Layer | Key files | Role |
|-------|-----------|------|
| Bootstrap | `classic-games-arcade.php` | Singleton, guest tokens, schema migrate/create, cron, enqueue |
| Rooms | `class-sacga-room-manager.php` | Create/join/leave/AI/start/cleanup/rejoin |
| State | `class-sacga-game-state.php` | Persist JSON state, etag, apply_move |
| REST | `class-sacga-rest-controller.php` | `sacga/v1` public + write endpoints |
| AI core | `class-sacga-ai-engine.php` | Process AI after start/move/poll (game-phase hardcodes) |
| Gates | `trait-turn-gate.php` | open/close gate, `-1` current_turn |
| Frontend | `assets/js/sacga-engine.js` | Lobby/room/poll/move; 2s poll; client_id localStorage |
| Admin | `admin/class-sacga-admin.php` | Dashboard, settings, shortcode help |

## Audit findings summary (core only, 2026-07-15)

### Correctness
1. **Inactivity cleanup gap:** `expires_at` updated by `touch_room()` but cleanup ignores it for lobby/active rooms (hard-cap on `created_at` only).
2. **2-player assumptions** in timeout forfeit and forfeit winner selection.
3. **`current_turn` sentinel inconsistency** (`-1` vs `null` across trait, state, AI).

### Security / multiplayer integrity
4. **Room write actions not membership-scoped** (start/add AI with any valid guest token + room code).
5. **Rejoin trusts client_id alone.**
6. **Rate limits only on create/join**, not moves.
7. Public GET room/state — intentional for guests but worth a privacy review.

### Reliability / performance
8. **AI work on GET state polls** — scales poorly.
9. **etag includes microtime** — weak for pure content hashing; no DB transaction around move.
10. **Asset detection incomplete** for `sacga_available_rooms` / non-post shortcodes.
11. Cards+dice scripts always enqueued with engine.

### Product / release hygiene
12. Version `0.1.0` vs branch `1.2.2-build`; GPL-2.0+ header vs GPLv3 readme.
13. `sacga_is_pro()` always true.
14. Schema version bookkeeping messy (migration 5 under version 4).
15. Placeholder CI; no automated tests; incomplete API docs.
16. Debug `error_log` not always WP_DEBUG-gated (cribbage/turn paths, table creation).

## Notes for future agents
- When touching multiplayer, verify seat index consistency between PHP room players and JS `mySeat`.
- Guest identity: HMAC token in cookie/header; **DB stores guest_id** in column named `guest_token`.
- Seat lookup prefers `X-SACGA-Guest-Token` header over cookie (documented in REST controller).
- Do not “fix” cleanup by only looking at completed rooms — lobby/active inactivity is the gap.
- Game rules content belongs in PHP game classes; core should not grow more per-game phase branches.
- Full prioritized list: `.l00prite/todos.md`. Do not implement games in the same pass as P0 platform fixes unless human asks.

## Design Updates and Feature Proposals (Proposed & Committed 2026-07-16)

### Architectural & Core Code Design Updates
1. **Dynamic, Visibility-Aware Polling (Frontend Engine):**
   - Use Page Visibility API (`document.hidden`) to pause polling when the tab is hidden and poll instantly when it becomes visible.
   - Implement exponential backoff/idle slowdown: increase poll interval from 2s to 5s, 10s, and up to 30s if no moves occur. Reset to 2s on player action or a new move.
   - Separate lobby vs. active gameplay polling: poll at 5s-8s for lobbies, ramp to 2s for active play.
2. **Optimistic Concurrency with Numeric Version Check:**
   - Augment or replace `etag` microtime hashing with an explicit `state_version` column in `wp_sacga_game_state` and a CAS (Compare-And-Swap) `UPDATE` statement.
   - Wrap multi-player operations (such as seat identity setting, forfeit processing, and start game) in DB transaction blocks (`START TRANSACTION` ... `COMMIT`).
3. **Game-Agnostic AI Engine Decoupling:**
   - Refactor `SACGA_AI_Engine` to call hook contracts in game classes (e.g. `$game->get_ai_move(...)`) rather than hardcoding game-specific logic branches.
4. **Dynamic Asset Allocation & Dependency Splitting:**
   - Split card and dice script enqueuing so board games (Chess, Checkers) don't load card assets unnecessarily. Let the specific game registry define and load required visual dependencies dynamically at shortcode render time.

### Security & Integrity Design Updates
1. **Membership-Scoped Write Authorization:**
   - Restrict write endpoints in `can_write_room_action` to users registered as players in `wp_sacga_room_players` for that specific room, preventing unauthorized state manipulation.
2. **Secure Local-Storage Rejoin Validation:**
   - Bind the local storage `client_id` with verified session tokens/cookies inside `wp_sacga_room_players` on room join. Do not rejoin based on a raw `client_id` payload alone.
3. **Move-Specific Rate Limiting:**
   - Dedicate separate rate-limiting buckets for moves vs. creating/joining rooms.

### Product & Gameplay Features
1. **Read-Only Spectator Mode:**
   - Bypass "Room Full" screens for late visitors. Render the game table in a read-only layout (no interactions) with a `"Spectator Mode — Watching Live"` banner.
2. **Room Host Management & Kick Controls:**
   - Assign host privileges to the room creator (typically seat `0` or `is_host = 1`). Provide buttons to kick inactive players/AIs, configure AI difficulty, or adjust custom rule parameters (e.g., scoring caps) in the lobby.
3. **Visual "Player Disconnected" Countdown Banners:**
   - Leverage the 90-second disconnect grace period. Show a visual banner with a real-time countdown when an opponent goes offline, alerting players they can forfeit or wait.
4. **Local Guest Profiles & Persistence:**
   - Let guest players set a custom nickname and avatar color stored in browser `localStorage`, transmitting this metadata to avoid generic "Guest" seat labels.
5. **WordPress Admin Observability Dashboard:**
   - Build a dashboard showing active games, active player counts, database metrics, manual cleanup button, error/debug log outputs, and global limits.
6. **Gemini AI Bot Commentary & Chat:**
   - **Admin Settings:** Global toggle to turn Gemini bot chat on/off, safe API key database encryption/storage, model selector (defaulting to `gemini-1.5-flash`), and commentary frequency slider.
   - **API Engine (PHP):** A server-side curl handler that triggers when a bot moves. Feeds Gemini Flash the current game ID, state overview (scores, cards/pieces positions, last moves), bot seat position, and player names. Prompt guides Gemini to generate highly context-aware "trash talk," strategic advice, or simple game-related greetings.
   - **Frontend UI (JS):** Animates stylish, floating CSS speech bubbles directly above the bot avatars on the table whenever a comment payload is received in the state poll, automatically fading out after 5 seconds.
