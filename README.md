# Classic Games Arcade

A modular WordPress arcade plugin for classic card and board games with room-based multiplayer and AI opponents.

## Description

Classic Games Arcade brings traditional games to your WordPress site with a modern, interactive interface. Players can create private rooms, invite friends, or play against AI opponents of varying difficulty levels.

## Features

- **Room-based Multiplayer**: Create private game rooms with unique codes
- **AI Opponents**: Play against bots with configurable difficulty (easy, medium, hard)
- **Guest Play**: No login required - guests can play using unique tokens
- **Real-time Updates**: State polling keeps games synchronized
- **Move Timer**: 3-minute move timeout prevents hung games
- **Automatic Cleanup**: Expired rooms are cleaned up automatically
- **Forfeit Option**: Players can forfeit games at any time
- **Professional UI**: Modern, responsive design with polished lobby and room interfaces
- **Modular Architecture**: Easy to add new games

## Games Included

### Checkers
- Standard 8x8 board
- Forced captures and multi-jumps
- King pieces with enhanced movement
- Win/loss/draw detection

### Spades (New in v0.2.0)
- 4-player team-based card game
- Bidding phase with nil bids
- Spades as trump suit
- Bag penalty system
- 500 point win threshold
- Round-based gameplay

### Cribbage (New in v0.2.2)
- Classic 2-player card game
- Discard phase (building the crib)
- Pegging phase with 31 counting
- Hand scoring (15s, pairs, runs, flushes)
- First to 121 points wins
- Traditional cribbage scoring rules

### Hearts (New in v0.2.3)
- Classic 4-player trick-taking game
- Pass 3 cards each round (left, right, across, none pattern)
- Avoid taking hearts (1 point each) and Queen of Spades (13 points)
- Hearts must be "broken" before leading
- Shoot the moon: Take all 26 points to give opponents 26 each
- First to 100 points loses, lowest score wins

### Euchre (New in v0.2.6)
- 4-player team-based trick-taking game with a 24-card deck

### Rummy (New in v0.2.6)
- Classic card game focused on forming sets and runs

### War (New in v0.4.4)
- Classic 2-player battle for the full deck
- Flip-based resolution with war tie-breaks
- Mercy rule option for long sessions

## Installation

1. Upload the `classic-games-arcade` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure settings in the Admin panel
4. Add games to pages using shortcodes

## Shortcodes

### Display Game List
```
[classic_games_arcade]
```

### Display Specific Game
```
[sacga_game game="checkers"]
[sacga_game game="spades"]
[sacga_game game="cribbage"]
[sacga_game game="hearts"]
[sacga_game game="war"]
```

## Admin Settings

- **Room Expiration**: Configure how long inactive rooms remain (default: 2 hours)
- **AI Difficulty**: Set default AI difficulty level
- **Game Settings**: Per-game configuration options

## System Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher

## Development

### File Structure
```
classic-games-arcade/
├── includes/
│   ├── engine/          # Core game engine
│   ├── games/           # Individual game implementations
│   ├── rest/            # REST API endpoints
│   └── shortcodes/      # WordPress shortcodes
├── assets/
│   ├── js/              # JavaScript files
│   ├── css/             # Stylesheets
│   └── images/          # Game assets
└── admin/               # Admin interface
```

### Adding New Games

1. Create game class in `includes/games/[game-name]/`
2. Extend `SACGA_Game_Contract` or use `SACGA_Card_Game_Trait`
3. Implement required methods: `get_info()`, `initialize_state()`, `validate_move()`, `apply_move()`, etc.
4. Add renderer in `assets/js/games/[game-name].js`
5. Register game in discovery system

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for detailed version history.

## License

GPL-2.0+

## Author

Shortcode Arcade - https://shortcodearcade.com

## Support

For issues and feature requests, please use the GitHub issue tracker.
