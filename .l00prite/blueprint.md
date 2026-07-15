# Project Blueprint

## Mission

Classic Games Arcade is a WordPress plugin that embeds multiplayer classic card, dice, and
board games via shortcodes. Site owners get room-based play with shareable codes, guest
sessions, and AI opponents. Agents maintain and extend this on branch `1.2.2-build` without
breaking server-authoritative game state or public shortcode/REST contracts.

## Architecture

- **Bootstrap:** `classic-games-arcade.php` — singleton plugin class, hooks, enqueue.
- **Engine:** `includes/engine/` — contract, registry, rooms, state, AI, card trait.
- **Games:** `includes/games/<game-id>/` — one PHP class per game implementing `SACGA_Game_Contract`.
- **REST:** `includes/rest/` — `sacga/v1` room and move endpoints.
- **Shortcodes:** `includes/shortcodes/` — arcade, single game, rules, available rooms.
- **Frontend:** `assets/js/` + `assets/css/` — shared engine and per-game UI.
- **Admin:** `admin/` — settings (expiry, AI difficulty).
- **Runtime:** WordPress 6.3+, PHP 7.4+, MySQL; guest cookie tokens; cron room cleanup.

## Requirements

- [ ] Stable multiplayer seat identity and turn gating
- [ ] Clean lobby → start → play for all registered games
- [ ] AI paths without desyncing human seats
- [ ] Rules single-sourced from PHP game classes
- [ ] Backward-compatible shortcodes and `sacga/v1` routes
- [ ] GPL-compatible; no heavy new dependencies without human approval

## Definition of Done

- [ ] Unit of work verified with evidence in `ledger.md`
- [ ] No unrelated game/room regressions
- [ ] Memory files (`state.json`, `todos.md`, `failures.md`) updated
- [ ] Human gates observed for push/merge/release/API breaks

## Non-Execution Boundary

This blueprint is guidance for later implementation loops. Scaffolding tools must not execute
the project unless a human explicitly starts an implementation session.
