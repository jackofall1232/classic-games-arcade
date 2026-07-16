# SACGA Visual Design Spec — "Midnight Arcade" (v1.2.3)

Status: **approved direction** (owner requested "amazing, very modern" to replace the
"early-2000s 2D" feel). This spec is the reference for the v1.2.3 visual refresh and
the phases that follow. Implementation must stay additive: existing selectors and
markup are preserved; the modern look ships as override layers appended to existing
stylesheets so no game logic or HTML changes are required for theming.

---

## 1. Direction

**Midnight Arcade** — a premium game-table aesthetic:

- **Surface:** deep midnight navy/charcoal backdrop with a subtle radial "table felt"
  glow behind the play area. Games feel like they sit on a real table under a lamp,
  not on a white web page.
- **Depth:** layered soft shadows + 1px luminous borders (glass edge) instead of flat
  boxes. Interactive elements lift on hover (translateY + shadow spread).
- **Accents:** electric indigo→cyan gradient for primary actions; warm gold reserved
  for wins/kings/highlights; success green and danger red kept but re-tuned for dark
  surfaces.
- **Motion:** springy, short (150–600ms), and meaningful — deal, play, tumble, settle.
  Never decorative loops. All motion honors `prefers-reduced-motion`.
- **Typography:** existing system stack retained; heavier weights and wider tracking
  on labels/room codes (room code becomes a "neon ticket").

## 2. Token layer (scoped, non-invasive)

The existing `:root` token block in `sacga-core.css` stays untouched (other plugins/
themes may read it). The dark theme re-maps tokens **scoped to `.sacga-container`
and `.sacga-arcade`** so the theme applies only inside plugin markup:

| Token | Dark value | Use |
|---|---|---|
| `--sacga-surface-0` | `#0b1020` | page-level backdrop panel |
| `--sacga-surface-1` | `#131a30` | cards, panels |
| `--sacga-surface-2` | `#1c2542` | raised elements, inputs |
| `--sacga-felt` | radial `#14532d → #0b3320` | board/table under-glow |
| `--sacga-accent` | `#6366f1 → #22d3ee` gradient | primary buttons, focus |
| `--sacga-gold` | `#fbbf24` | wins, kings, crowns |
| `--sacga-ink` | `#e7ecf7` | primary text on dark |
| `--sacga-ink-dim` | `#9aa7c7` | secondary text |
| `--sacga-line` | `rgba(148,163,184,.18)` | hairline borders |
| `--sacga-glow` | `0 0 0 3px rgba(99,102,241,.35)` | focus rings |

## 3. Component specs (selectors are the real ones in the codebase)

### Core chrome (`sacga-core.css` modern layer)
- `.sacga-container`, `.sacga-arcade` — dark gradient backdrop, 20px radius, glass
  border, deep shadow; all text colors re-mapped.
- `.sacga-btn-primary` — accent gradient, white text, hover lift (−2px) + glow;
  active press (+1px, shadow collapse). `.sacga-btn-secondary` — glass w/ hairline.
  `.sacga-btn-danger/success` — tuned for dark.
- `.sacga-input` — `--sacga-surface-2` fill, hairline border, focus glow ring.
- `.sacga-game-card` (arcade grid) — glass card, hover lift + accent top edge.
- `.sacga-room-code-display` — mono, letterspaced, gold on dark chip w/ soft glow
  ("neon ticket").
- `.sacga-player-slot`/`.sacga-player-filled`/`.sacga-player-empty` — chip rows with
  avatars-by-initial, AI badge in accent, empty slots as dashed ghosts.
- `.sacga-turn-indicator` — glowing pill; pulses (2s) only while it is your turn.
- `.sacga-gameover-content` — glass modal, gold title on win.
- `#sacga-game-board` — sits on the felt radial glow.
- Emote bar / mute (`.sacga-game-controls-wrap`) — dark glass override (`!important`
  required: engine injects inline colors; follow-up may remove inline styles).

### Boards (legacy four, appended modern layers)
- **Chess (`sacga-chess.css`)** — walnut gradient frame w/ inner bevel; cells re-tuned
  (`#ecdab9`/`#ae8a68` classic-modern), selected = indigo glow inset, valid move =
  soft glowing dot, capture = pulsing ring, last move = warm highlight; pieces get
  drop-shadow depth + hover lift, white pieces get subtle stroke for contrast.
- **Checkers (`sacga-checkers.css`)** — same frame treatment; pieces rendered as
  glossy discs (radial highlight + rim shadow), kings crowned in gold glow;
  forced-jump notice as glowing amber banner.
- **Fourfall (`sacga-fourfall.css`)** — glossy blue board with inner shadow "holes",
  discs get radial gloss + drop bounce animation on the last-moved cell.
- **Rummy (`sacga-rummy.css`)** — dark glass header/score panels; meld groups as
  felt wells with hairline borders.

### Dice (shared)
- Keep pip dice (`.sacga-die`/`.die-face`, already gradient-lit) — **wire** the
  existing-but-orphaned 3D tumble: JS adds `.rolling` on each fresh roll
  (`dice-roll` keyframes: 720° X/Y + translateZ, 0.8s). Implemented for Pig in
  v1.2.3; Overcut/Even-at-Odds/Odd-Man-Out use bespoke dice markup — phase 2.
- Full 6-face CSS cube (true die that lands on the rolled face) is **phase 3**:
  requires `SACGADice.renderDice` markup change consumed by multiple games; do it
  behind the same API with a feature flag.

### Cards (shared)
- Existing `cardPlayIn`/`cardHighlight`/`trickCollect` retained.
- **New `sacga-card-deal-in`:** cards fly up from below with rotate + stagger
  (35ms/card, capped at 12 steps). Trigger: engine adds `.sacga-dealing` to
  `#sacga-game-container` in `showGameView()` for 1.8s — i.e., exactly when a game
  starts/rejoins, so poll re-renders never replay it.
- **Human play pop:** engine applies the same `.sacga-card-animating
  .sacga-card-just-played` pair to the last `.sacga-play-area .sacga-card` after a
  successful `play` move — this gives Euchre (and any `.sacga-play-area` game) both
  AI and human play animation with zero per-game JS.

## 4. Accessibility
- Text contrast ≥ 4.5:1 on all dark surfaces (ink `#e7ecf7` on `#131a30` ≈ 12:1).
- Focus visible on every interactive element (`--sacga-glow` ring).
- `@media (prefers-reduced-motion: reduce)` disables tumble/deal/pulse animations.
- No information conveyed by color alone (badges keep text labels).

## 5. Phasing
- **Phase A (this release, v1.2.3):** token layer, core chrome, four legacy boards,
  dice tumble wiring (Pig), deal-in + human play pop, bug fixes, version bump.
- **Phase B:** bespoke dice games (Overcut/EAO/OMO) on the shared tumble; mid-round
  re-deal animation (hand-size-aware gating per game); remove inline styles from
  engine-injected controls.
- **Phase C:** true 3D cube dice behind `SACGADice` flag; card flight (FLIP) from
  deck coordinates to hand; per-game table felts (hearts red felt, spades navy…).

## 6. File map (Phase A)
| File | Change |
|---|---|
| `assets/css/sacga-core.css` | append "Midnight Arcade" layer |
| `assets/css/games/sacga-chess.css` | append modern board layer |
| `assets/css/games/sacga-checkers.css` | append modern board layer |
| `assets/css/games/sacga-fourfall.css` | append modern board layer |
| `assets/css/games/sacga-rummy.css` | append modern panel layer |
| `assets/css/games/cards-base.css` | add deal-in keyframes + stagger |
| `assets/css/games/pig.css` | settle/bust animations (conflict fix) |
| `assets/js/sacga-engine.js` | `.sacga-dealing` hook; human play pop |
| `assets/js/games/sacga-pig.js` | `.rolling` wiring; Double Pig array support |
| `assets/js/games/sacga-checkers.js` | `active_jumper` fix |
