=== Classic Games Arcade ===
Contributors: shortcodearcade
Tags: games, arcade, multiplayer, shortcodes
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 0.6.1
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modular WordPress arcade for classic card and board games with room-based multiplayer and AI opponents.

== Description ==
Classic Games Arcade provides room-based multiplayer gameplay with optional AI opponents. Rooms are created and managed server-side, and game state is stored in custom tables managed via `dbDelta()`.

== Installation ==
1. Upload the plugin files to the `/wp-content/plugins/classic-games-arcade` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Add a shortcode to any page or post.

== Usage ==
Use one of the following shortcodes:

* `[classic_games_arcade]` - Display the full arcade with all games
* `[sacga_game game="checkers"]` - Display a single game
* `[sacga_rules]` - Display master rules page with all games (accordion style)
* `[sacga_rules game="rummy"]` - Display rules for a specific game
* `[sacga_available_rooms]` - Display available rooms to join

== Room lifecycle & cleanup ==
Rooms begin in a `lobby` state, transition to `active` when a game starts, and are marked `completed` when the game ends. Cleanup is server-side and cron-driven. Completed rooms are eligible for fast cleanup after a short grace period, while long-lived non-completed rooms are removed by a hard-cap timer to avoid false positives.

== Guest tokens ==
Guests are issued a short-lived, cookie-based token that identifies them for a room session. No personally identifiable information is stored in the token.

== Changelog ==
= 0.6.1 =
* Add [sacga_rules] shortcode for displaying game rules
* Master rules page with collapsible accordion design grouped by game type
* In-game quick reference rules modal with accessibility features (focus trap, ESC key, ARIA)
* Rules button added to lobby header, room view, and in-game toolbar
* Expand All / Collapse All buttons on master rules page
* Add official rules to all 13 games (Board: Checkers, Chess, Fourfall, Backgammon; Card: Hearts, Spades, Euchre, Cribbage, Diamonds, Rummy, War; Dice: Pig, Overcut)
* Structured rules format with Objective, Setup, Gameplay, Winning, and Notes sections
* Section-specific color accents for visual clarity
* Fully responsive design for mobile devices

= 0.5.10 =
* Maintenance release for server authority and compliance updates.

