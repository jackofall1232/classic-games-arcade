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
