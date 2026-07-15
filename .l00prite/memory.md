# Durable Memory

## Project facts
- Repo: jackofall1232/classic-games-arcade
- Default branch for agent work: `1.2.2-build`
- WordPress plugin: Classic Games Arcade / Shortcode Arcade (prefix SACGA, text domain shortcode-arcade)
- Recent fixes on this lineage: multiplayer seat identity on `make_move`; turn gate uses `-1` instead of `null` for open `current_turn`

## Decisions
- Protocol scaffolded as **medium** tier: modular multi-game plugin with REST + multiplayer, single deployable package.
- Existing layout (`includes/`, `assets/`, `admin/`) is the architecture — do not invent a parallel `src/` tree.

## Notes for future agents
- When touching multiplayer, verify seat index consistency between PHP room state and JS engine.
- Game rules content belongs in the PHP game class, not duplicated ad hoc in JS.
