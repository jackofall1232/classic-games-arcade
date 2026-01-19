# Shortcode Arcade: Classic Games

A modular WordPress plugin for embedding classic multiplayer card, dice, and board games using shortcodes. Includes lobbies, room-based play, AI opponents, and a clean engine designed for extension.

---

## Overview

**Shortcode Arcade: Classic Games** brings traditional games to WordPress with a modern, shortcode-driven architecture. Site owners can embed individual games or a full arcade, while players can create or join rooms, play with friends, or face AI opponents.

This repository contains the **open-core engine** and free games. Pro features and additional games are layered cleanly on top.

---

## Core Features

- **Shortcode-Based Embedding**  
  Add games anywhere using simple, flexible shortcodes.

- **Room-Based Multiplayer**  
  Players create or join rooms using shareable room codes.

- **Lobby System**  
  Central lobby UI for creating rooms and joining games.

- **AI Opponents**  
  Built-in AI support with configurable difficulty levels.

- **Guest Play**  
  No user accounts required — session-based participation.

- **Automatic Room Cleanup**  
  Inactive rooms expire automatically to keep the system clean.

- **Modular Game Engine**  
  Games are isolated, self-contained, and easy to extend.

- **Developer-Friendly Architecture**  
  Clean contracts, traits, and registries designed for long-term maintenance.

---

## Included Games

> Game availability depends on version and license tier.

- **Checkers** – Classic 2-player board game with AI support  
- **War** – Automatic card game, idle-friendly  
- *(Additional games may be included or added via extensions)*

---

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate **Shortcode Arcade: Classic Games** from the Plugins menu
3. Configure global settings under **Games Arcade → Settings**
4. Add games to pages using shortcodes

---

## Shortcodes

### Full Arcade
Displays the main lobby and game selector.
```txt
[classic_games_arcade]
