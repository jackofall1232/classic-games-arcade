## Shortcodes

Shortcode Arcade is built around flexible, composable shortcodes. Games, lobbies, rooms, and rules can be placed anywhere and wrapped in custom layouts.

---

### 🎮 Add Games to Any Page

#### Single Game
Embed a specific game directly on a page or post.

```txt
[sacga_game game="checkers"]
```

**Use cases**
- Dedicated game pages
- Landing pages
- Custom layouts with surrounding content

---

#### Full Arcade
Displays the full arcade lobby with game selection and room creation.

```txt
[classic_games_arcade]
```

**Use cases**
- Main arcade page
- Community hub
- Central lobby experience

---

## 📘 Rules Shortcodes

Rules are pulled directly from each game’s PHP definition to ensure a single source of truth.

---

### Master Rules Page
Displays **all game rules** in an accordion layout grouped by game type (Board, Card, Dice).

```txt
[sacga_rules]
```

**Features**
- Accordion layout with collapsible panels
- Expand All / Collapse All controls
- Grouped by game type with color-coded headers
- Displays player count and AI availability
- Ideal for a dedicated Rules or Help page

---

### Single Game Rules
Displays rules for a specific game.

```txt
[sacga_rules game="checkers"]
```

#### Attributes

| Attribute | Default | Options | Description |
|---------|--------|--------|-------------|
| `game` | required | Any game ID | Game to display rules for |
| `layout` | `sections` | `sections`, `compact` | Full rules or condensed view |
| `show_title` | `true` | `true`, `false` | Show or hide the game title |

#### Examples
```txt
[sacga_rules game="rummy"]
[sacga_rules game="checkers" layout="compact"]
[sacga_rules game="war" show_title="false"]
```

---

### Available Game IDs

```txt
checkers, chess, fourfall, backgammon,
hearts, spades, euchre, cribbage,
diamonds, rummy, war, pig, overcut
```

---

## 🏟️ Available Rooms Shortcode

### `[sacga_available_rooms]`
Displays a frontend-facing list of joinable game rooms. This shortcode is intentionally separate from the arcade to allow flexible placement.

```txt
[sacga_available_rooms]
```

#### Usage Examples
```txt
[sacga_available_rooms]
[sacga_available_rooms game="checkers"]
[sacga_available_rooms limit="20"]
[sacga_available_rooms refresh="30"]
[sacga_available_rooms show_players="false"]
```

#### Features
- Modern card-based UI with gradient headers
- Displays game name, room code, status, and available seats
- One-click **Join Room** links
- Friendly empty-state messaging
- Optional AJAX auto-refresh
- Theme-agnostic CSS using custom properties
- Automatic dark-mode support
- Mobile-first responsive design
- Staggered animations as rooms appear

#### Room Visibility Rules
- Shows rooms with `lobby` and `active` status only
- Excludes full rooms
- Excludes expired rooms

---

## Developer Notes

- Rules are sourced directly from each game’s PHP definition
- No duplicated documentation or hard-coded rule text
- Shortcodes are designed to be wrapped in custom HTML or theme layouts
- Admin UI remains intentionally minimal and factual
