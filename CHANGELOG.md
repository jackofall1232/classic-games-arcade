# Changelog

All notable changes to Classic Games Arcade will be documented in this file.

## [0.4.5] - 2026-01-15

### Changed
- Refactored CSS into layered architecture with shared core, game-type bases, and per-game overrides.
- Scoped card layouts to per-game styling to prevent cross-game layout bleed.
- Reduced active game container padding and removed fixed game board min-height to keep layouts content-driven.
- Updated plugin version to 0.4.5

## [0.4.4] - 2026-01-14

### Added
- **War Card Game**: Fully playable 2-player War implementation with AI support, mercy rule, and war resolution handling.
- **Camo War Theme**: Dedicated WAR board layout with camo styling, battle animations, and compact stack visuals.

### Changed
- Updated plugin version to 0.4.4

## [0.2.9] - 2026-01-14

### Changed
- **UI/UX Polish**: Comprehensive design system implementation for professional look and feel
  - Modern color palette with gradients and consistent theming
  - Improved typography with system font stack and proper sizing scale
  - Enhanced spacing and border radius system for visual harmony
  - Professional shadows and transitions throughout
  - Responsive design improvements for mobile, tablet, and desktop
  - Polished lobby interface with centered layout and gradient header
  - Enhanced room interface with card-based design and better visual hierarchy
  - Improved button styles with hover states and accessibility
  - Better player slot visualization with seat labels and badges
  - Professional loading spinner with backdrop blur
  - Touch-friendly tap targets (minimum 44px) for mobile devices
- Updated plugin version to 0.2.9

### Technical Notes
- Implemented comprehensive design system with CSS custom properties
- Mobile-first responsive approach with progressive enhancement
- Improved accessibility with focus states and proper contrast ratios
- Consistent spacing scale and typography system

## [0.2.4] - 2026-01-13

### Fixed
- **Hearts Passing Phase**: Fixed multiple critical bugs preventing game from starting
  - AI engine now correctly detects when AI players need to pass cards
  - AI engine processes all AI players during simultaneous passing phase
  - Fixed server-side turn validation to allow all players to pass simultaneously
  - Fixed client-side turn validation blocking player passes
  - Fixed card ID format mismatch (using `clubs_2` instead of `C2`, `spades_Q` instead of `SQ`)
  - Re-find 2 of clubs holder after card exchange to set correct starting player
- Removed debug logging code for cleaner production code

### Technical Notes
- Simultaneous move phases (like Hearts passing) now bypass turn validation on both client and server
- Card IDs use standard format: `{suit}_{rank}` (e.g., `clubs_2`, `spades_Q`)

## [0.2.3] - 2026-01-13

### Added
- **Hearts Card Game**: Classic 4-player trick-taking game implementation
  - Card passing phase with rotating directions (left, right, across, none)
  - Must lead with 2 of clubs on first trick
  - Hearts breaking mechanic - can't lead hearts until broken
  - Avoid taking hearts (1 point each) and Queen of Spades (13 points)
  - Shoot the moon: Take all 26 points to give opponents 26 each
  - First to 100 points loses, lowest score wins
  - Full AI support for passing and playing phases
  - Round-based gameplay with score tracking
- Game-specific CSS for Hearts with player positions and scoreboard

### Changed
- Updated version to 0.2.3
- Enhanced README with Hearts game documentation and shortcode

## [0.2.2] - 2026-01-13

### Added
- **Cribbage Card Game**: Classic 2-player cribbage implementation
  - Discard phase: Players discard 2 cards to the crib
  - Pegging phase: Play cards to count towards 31
  - Pegging scoring: 15s, pairs (2/3/4 of a kind), and 31s
  - Hand scoring: 15s, pairs, flushes, runs, and nobs
  - Starter card (cut) with "his heels" bonus for dealer
  - First player to 121 points wins
  - Full AI support for both phases
  - Traditional cribbage rules and scoring
- Game-specific CSS for Cribbage with scoreboard and progress bars

### Fixed
- Opponent card hands now display in compact stacks instead of spread out
- Improved card table positioning and seat layouts
- Better responsive design for card games on mobile devices
- Player info boxes more compact with better spacing

### Changed
- Updated version to 0.2.2
- Enhanced card game CSS with tighter opponent hand stacking
- Improved mobile breakpoints for card sizes and positioning

## [0.2.1] - 2026-01-13

### Fixed
- PHP 8.1+ nullable parameter deprecation in `CGA_Card_Game_Trait::sort_hand()`
- Card table layout using viewport-relative heights instead of fixed pixels
- Opponent hands now show as compact stacks (-40px/-55px overlap)
- Player info boxes and seat positioning improved with flexbox layout
- Better responsive scaling for all card elements on mobile

## [0.2.0] - 2026-01-13

### Added
- **Spades Card Game**: Complete 4-player team-based card game implementation
  - Bidding phase with support for nil bids
  - Spades as trump suit with "spades broken" mechanic
  - Team scoring with bag penalty system (10 bags = -100 points)
  - Win condition at 500 points or opponent reaches -200
  - Round-based gameplay with round continuation
  - AI support for all difficulty levels
- **Card Game Infrastructure**:
  - New `CGA_Card_Game_Trait` with shared card utilities (deck creation, shuffling, dealing, sorting)
  - Shared `cards.js` utility library for card rendering
  - Comprehensive `cards.css` styling for all card games
  - Reusable card UI components (hands, tricks, player info, scoreboards)
- Move timer with 3-minute timeout and automatic forfeit
- Forfeit game functionality with confirmation dialog
- Opportunistic room cleanup (5% chance on state polls as backup to cron)

### Fixed
- **Checkers Game Logic**:
  - Fixed field name mismatch between PHP (`must_capture`/`multi_jump`) and JS (`must_jump`/`jump_piece`)
  - Fixed "must continue jump" error when jump piece was captured
  - Fixed wrong piece disappearing during captures
  - Properly clear multi-jump state when turn changes
- **Game End Conditions**:
  - AI games now properly end when AI has no valid moves
  - Fixed games hanging when bot sees no logical win
  - Properly update room status to 'completed' when game ends
- **Room Cleanup**:
  - Fixed WordPress cron scheduling for automated room cleanup
  - Added custom 15-minute cron interval
  - Cleanup now runs reliably every 15 minutes
  - Added `expires_at` index for efficient cleanup queries
- **AI Engine**:
  - Fixed crash when AI returns no moves
  - Added end condition check before breaking AI loop
  - Simplified AI logic for better stability

### Changed
- Updated plugin version to 0.2.0
- Enhanced README with complete documentation
- Improved game state timestamp tracking with `last_move_at`
- Better error handling and validation throughout

### Technical Notes
- Card game trait promotes code reuse across card-based games
- Separated rendering logic (cards.js) from game-specific logic
- Phase-based game flow architecture for card games (bidding → playing → round_end)
- Input sanitization for all user-provided move data

## [0.1.7] - 2026-01-12

### Fixed
- Multi-jump state persisting after piece captured
- Game hanging on "must continue jump" error

## [0.1.6] - 2026-01-12

### Added
- Room timeout and cleanup system activation

### Fixed
- AI stopping when no logical win available
- Room cleanup cron job registration

## [0.1.5] - 2026-01-12

### Fixed
- Checkers move validation logic
- Piece capture mechanics

## [0.1.4] - 2026-01-11

### Added
- Initial stable release
- Checkers game with full gameplay
- Room-based multiplayer system
- AI opponent support
- Guest play functionality

### Features
- Room creation with unique codes
- Real-time state polling
- AI difficulty levels (easy, medium, hard)
- Admin settings panel (room timeout stubbed)

---

## Version Format

This project follows [Semantic Versioning](https://semver.org/):
- MAJOR version for incompatible API changes
- MINOR version for added functionality in a backward compatible manner
- PATCH version for backward compatible bug fixes
