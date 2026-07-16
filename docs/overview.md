# Classic Games Arcade — Architectural Overview

This document specifies the high-level architecture, database schemas, room lifecycles, and turn-gate orchestration models powering the Classic Games Arcade.

---

## High-Level Architecture Overview

Classic Games Arcade is built as a modular, stateless, polling-driven WordPress plugin. The platform consists of four main architectural layers:

```
[ Frontend Engine (sacga-engine.js) ]
               │
               ▼ (HTTPS REST API / JSON)
[ REST Routing Controller (class-sacga-rest-controller.php) ]
               │
               ├────────────────────────────┐
               ▼                            ▼
[ Room Manager (class-sacga-room-manager.php) ]   [ Game State Manager (class-sacga-game-state.php) ]
               │                            │
               ▼                            ▼
[ MySQL Tables (wp_sacga_rooms/players) ]  [ MySQL Table (wp_sacga_game_state) ]
                                            │
                                            ▼
                                   [ Game Module Engine ]
                                   (e.g., class-sacga-game-checkers.php)
```

1.  **The Frontend Client Engine (`sacga-engine.js`):** A jQuery-driven single-page application shell embedded via shortcodes. Manages player lobbies, local session persistence, dynamic visibility-aware adaptive polling, and renders game boards using modular game-specific render scripts.
2.  **The REST API Controller (`class-sacga-rest-controller.php`):** Exposes endpoints under `/wp-json/sacga/v1`. Verifies nonces, guest tokens, and validates seat membership to enforce secure room state modifications.
3.  **The Room Manager (`class-sacga-room-manager.php`):** Orchestrates matchroom lifecycles, player registrations, automated seat assignments, and handles the cron-based database purges.
4.  **The Game State Manager (`class-sacga-game-state.php`):** Enforces thread-safe state writes using database-level optimistic concurrency Compare-And-Swap (CAS) state version locking. Delegates moves, turn advances, and end conditions to the active game class.

---

## The Room Lifecycle Model

A matchroom transitions through three distinct phases:

```
                  [ Room Created (Lobby) ]
                             │
                             ├◄─────────────────────────┐ (AI Added or
                             ▼                          │  Player Joined/Left)
                 [ Host Launches Match ]                │
                             │                          │
                             ▼                          │
                     [ Game Active ] ───────────────────┘
                             │
                             ├──────────────────────────┐
                             ▼                          ▼
                   [ Match Completed ]         [ Match Expired (Inactivity) ]
                             │                          │
                             ▼                          ▼
                   [ Preserved State ]         [ Purged DB Rows (Cron) ]
```

1.  **Lobby:** Created when a player chooses a game type. Holds the open room code (6 capital letters/numbers) and awaits human player entries or AI additions. The host retains exclusive rights to add AIs, kick seats, or start the match.
2.  **Active:** Triggered when the host launches the game. The table is locked, seat positions are frozen, and the game state machine is initialized. Players submit moves sequentially or simultaneously depending on active rules.
3.  **Completed / Expired:**
    *   **Completed:** Triggers when a game class detects an end condition (e.g., checkmate in Chess or target score in Spades), displaying the winners list.
    *   **Expired:** If players leave or close their browser tabs, the room manager's cron cleanup purges the room, players list, and game state records from the database if they exceed the inactivity threshold (`expires_at` is older than `$now`).

---

## The Turn-Gate Orchestration Model

The platform utilizes a structured **Turn Gate Trait** (`trait-turn-gate.php`) to govern turn suspensions and transitions.

### 1. Sequential Turn-taking
A standard turn belongs to exactly one active player. The database stores the seat index in `current_turn`. Only that seat can submit moves. 

### 2. Simultaneous Phases (Passing, Discards)
For game phases where all players act simultaneously (e.g. card passing in Hearts or discarding in Cribbage):
*   `current_turn` is set to `-1` (suspending sequential turns).
*   Any player can submit a move to populate their respective sub-state (e.g. `$state['discards'][$seat]`).
*   The game class monitors when all seats have completed their action, resolves the simultaneous results, and restores `current_turn` to a sequential seat index to resume normal play.

### 3. Session Gates (Awaiting Turn Continuations)
After tricks are complete or rounds end:
*   `current_turn` is suspended (`-1`).
*   `awaiting_gate` is set to `true`, displaying a `"Continue"` button for all players.
*   Once players acknowledge, the gate closes, advancing the turn cleanly without skip-turn desyncs.

---

## The Polling & AI Orchestration Loop

Because standard WordPress installations are stateless and rely on PHP execution limits, AI bot players do not run on separate persistent threads. Instead, the AI loop is elegantly driven by **client-side polling**:

```
[ Client Polls State ]
          │
          ▼
[ REST Controller Retrieves State ]
          │
          ▼
[ Trigger process_ai_turns() ]
          │
          ├───────────────────────────┐
          ▼ (No AI turn pending)      ▼ (AI Turn is Active)
  [ Return JSON to Client ]   [ Compute AI Move via Game Class ]
                                      │
                                      ▼
                              [ Apply Move to DB ]
                                      │
                                      ▼
                             [ Trigger Gemini Chat ]
                                      │
                                      ▼
                            [ Return Updated JSON ]
```

1.  When a player's client polls `/game/state/{code}`, the REST Controller loads the room and triggers the `SACGA_AI_Engine`.
2.  The engine inspects the current state. If `current_turn` belongs to an AI seat, the engine queries the game class's `ai_move()` method to compute the bot's action.
3.  The engine applies the AI's move using `apply_move()` and triggers the cURL-based **Gemini Bot Commentary Generator** to generate fun, context-aware speech dialogue.
4.  The updated state (carrying the bot's move and Gemini speech bubble payload) is saved to the database and returned to the polling client, keeping the game interactive and responsive.

---

## Class Load Order & File Map (P3.5)

To maintain a lightweight footprint without the overhead of heavy PSR-4 autoloader composers, the plugin utilizes a structured, manual sequential load order. This order ensures dependencies (such as abstract contracts and traits) are fully compiled before classes extend or implement them.

### Load Order Sequence
The bootstrap file `classic-games-arcade.php` requires and compiles core components in this exact order:

1.  **Constants & Constants Map:** Initializes namespace paths, directories, and plugin URL constants.
2.  **Traits & Contracts:**
    *   `includes/traits/trait-turn-gate.php` (Required before game engines or state managers instantiate).
    *   `includes/engine/class-sacga-game-contract.php` (The master game logic abstract base class).
3.  **Database & Engine Core:**
    *   `includes/engine/class-sacga-game-registry.php` (Self-registers all 15 game modules).
    *   `includes/engine/class-sacga-room-manager.php` (Manages room state DB writes).
    *   `includes/engine/class-sacga-game-state.php` (Enforces transactional Move CAS writes).
    *   `includes/engine/class-sacga-ai-engine.php` (Triggers AI choices and Gemini Flash API prompts).
4.  **Routing & UI:**
    *   `includes/rest/class-sacga-rest-controller.php` (REST permission gates and endpoint callbacks).
    *   `includes/shortcodes/class-sacga-shortcodes.php` (Main shortcode HTML layout rendering).
5.  **Admin Dashboard (Admin Area Only):**
    *   `admin/class-sacga-admin.php` (Draws live room observability charts and purges stale matchrooms).

### Optional Future Autoloading
If the codebase continues to grow beyond 50+ classes, a Standard PHP Classmap Autoloader can be registered to dynamically resolve class loads using `spl_autoload_register`.
