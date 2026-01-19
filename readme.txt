=== Shortcode Arcade: Classic Games ===
Contributors: shortcodearcade
Tags: games, arcade, multiplayer, shortcodes, card games, board games, dice games
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A modular WordPress arcade for classic multiplayer card, dice, and board games with room-based play, AI opponents, and shortcode-driven embedding.

== Description ==

**Shortcode Arcade: Classic Games** provides room-based multiplayer gameplay for classic games using simple WordPress shortcodes.

Games are hosted in server-authoritative rooms with validated state transitions. Players can create private rooms, invite others using shareable room codes, or play against AI opponents. Guest players are supported without requiring WordPress user accounts.

The plugin is built with a modular architecture designed for performance, extensibility, and future Pro features.

== Features ==

* Shortcode-based embedding for games, lobbies, rules, and rooms
* Room-based multiplayer with shareable room codes
* Lobby system for creating and joining games
* AI opponents with configurable difficulty
* Guest play with session-based identification
* Automatic cleanup of expired rooms
* Modular game engine built for extension
* Clean, factual admin interface
* Theme-agnostic frontend styling
* Mobile-first responsive layouts

== Included Games ==

**Board Games**
* Checkers (AI)
* Chess
* Fourfall (AI)
* Backgammon (AI)

**Card Games**
* Hearts (AI)
* Spades (AI)
* Euchre (AI)
* Cribbage (AI)
* Diamonds (AI)
* Rummy (AI)
* War

**Dice Games**
* Pig (AI)
* Overcut (AI)

(Game availability may vary by version and license tier.)

== Rules System ==

Official rules are included for all 13 games and are sourced directly from each game’s PHP definition to ensure a single source of truth.

* Structured rules format:
  * Objective
  * Setup
  * Gameplay
  * Winning
  * Notes
* Section-specific color accents for visual clarity
* Fully responsive design for mobile devices
* Expandable accordion layout for master rules view
* In-game quick reference available from lobby and room UI

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate **Shortcode Arcade: Classic Games** from the Plugins menu
3. Configure global settings under **Games Arcade → Settings**
4. Add games or lobbies to pages using shortcodes

== Usage ==

Use one or more of the following shortcodes:

* `[classic_games_arcade]` – Display the full arcade lobby
* `[sacga_game game="checkers"]` – Display a single game
* `[sacga_rules]` – Display the master rules page (accordion layout)
* `[sacga_rules game="rummy"]` – Display rules for a specific game
* `[sacga_available_rooms]` – Display available rooms players can join

== Room Lifecycle & Cleanup ==

Rooms are created in a `lobby` state, transition to `active` when gameplay begins, and are marked `completed` when a game ends.

Cleanup is handled server-side and cron-driven. Completed rooms are eligible for cleanup after a short grace period. Long-lived rooms that do not complete are removed by a hard-cap expiration timer to prevent orphaned or stalled sessions.

== Guest Tokens ==

Guest players are issued a short-lived, cookie-based token used to identify them within a room session. No personally identifiable information is stored in the token.

Guest tokens are scoped to gameplay sessions and expire automatically, ensuring privacy while allowing seamless multiplayer participation without user accounts.

== Admin Settings ==

* Room expiration timeout
* Default AI difficulty
* Expansion-ready settings for future versions

The admin interface is intentionally minimal and factual. Roadmaps and planning tools are not exposed to site administrators.

== System Requirements ==

* WordPress 6.3 or higher
* PHP 7.4 or higher
* MySQL 5.6 or higher

== Frequently Asked Questions ==

= Does this plugin require user accounts? =
No. Players can join games as guests using session-based tokens.

= Can I place games anywhere on my site? =
Yes. All gameplay, rules, and room listings are available via shortcodes and can be wrapped in custom layouts.

= Is this plugin free? =
Yes. This plugin provides a free, GPL-licensed core. Additional games or features may be offered separately.

= How is multiplayer handled safely? =
Games run in server-authoritative rooms with validated state transitions and automatic cleanup.

== Screenshots ==

1. Arcade lobby view
2. Game room interface
3. Available rooms list
4. Rules accordion layout
5. In-game rules quick reference
6. Admin dashboard

== Changelog ==

= 0.1.0 =
* Initial public release under the Shortcode Arcade name
* Modular game engine
* Room-based multiplayer system
* Lobby UI
* AI opponent support
* Guest token session handling
* Official rules added for all 13 games
* Structured rules format (Objective, Setup, Gameplay, Winning, Notes)
* Rules shortcodes (master and per-game)
* In-game rules quick reference
* Available rooms shortcode
* Clean admin dashboard and settings

== Upgrade Notice ==

= 0.1.0 =
Initial release.

== License ==

This plugin is licensed under the GNU General Public License v3.0 (GPLv3).

== Support ==

For bug reports and feature requests, please use the GitHub issue tracker.
