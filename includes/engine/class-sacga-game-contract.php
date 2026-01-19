<?php
/**
 * Game Contract - Abstract base class for all games
 *
 * Every game module must extend this class and implement all required methods.
 * This ensures a consistent interface for the engine to interact with any game.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class SACGA_Game_Contract {

    /**
     * Game metadata
     */
    protected $id;
    protected $name;
    protected $type; // 'card', 'board', 'dice'
    protected $min_players;
    protected $max_players;
    protected $has_teams;
    protected $ai_supported;

    /**
     * Register the game and return metadata
     *
     * @return array Game metadata
     */
    abstract public function register_game(): array;

    /**
     * Initialize a fresh game state
     *
     * @param array $players Array of player data
     * @param array $settings Game settings (difficulty, variants, etc.)
     * @return array Initial game state
     */
    abstract public function init_state( array $players, array $settings = [] ): array;

    /**
     * Deal cards or set up the board
     * Called after init_state to prepare the game for play
     *
     * @param array $state Current game state
     * @return array Updated game state with dealt cards / setup complete
     */
    abstract public function deal_or_setup( array $state ): array;

    /**
     * Validate a move before applying it
     *
     * @param array  $state Current game state
     * @param int    $player_seat The seat position of the player making the move
     * @param array  $move The move data
     * @return bool|WP_Error True if valid, WP_Error with reason if invalid
     */
    abstract public function validate_move( array $state, int $player_seat, array $move );

    /**
     * Apply a validated move to the game state
     *
     * @param array $state Current game state
     * @param int   $player_seat The seat position of the player making the move
     * @param array $move The move data
     * @return array Updated game state
     */
    abstract public function apply_move( array $state, int $player_seat, array $move ): array;

    /**
     * Advance to the next turn
     *
     * @param array $state Current game state
     * @return array Updated game state with next turn set
     */
    abstract public function advance_turn( array $state ): array;

    /**
     * Check if the game/round has ended
     *
     * @param array $state Current game state
     * @return array [ 'ended' => bool, 'reason' => string|null, 'winners' => array|null ]
     */
    abstract public function check_end_condition( array $state ): array;

    /**
     * Score the round/game
     *
     * @param array $state Current game state
     * @return array Updated state with scores
     */
    abstract public function score_round( array $state ): array;

    /**
     * Get AI move
     *
     * @param array  $state Current game state
     * @param int    $player_seat The AI's seat position
     * @param string $difficulty 'beginner', 'intermediate', or 'expert'
     * @return array The move the AI chooses to make
     */
    abstract public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array;

    /**
     * Get valid moves for a player
     * Used by AI and UI to determine available actions
     *
     * @param array $state Current game state
     * @param int   $player_seat The seat position to check
     * @return array List of valid moves
     */
    abstract public function get_valid_moves( array $state, int $player_seat ): array;

    /**
     * Get the public state (visible to all players)
     * Filters out hidden information like other players' cards
     *
     * @param array $state Full game state
     * @param int   $player_seat The seat requesting the view
     * @return array Filtered state safe for the player to see
     */
    abstract public function get_public_state( array $state, int $player_seat ): array;

    /**
     * Get game metadata (convenience wrapper)
     */
    public function get_metadata(): array {
        return $this->register_game();
    }

    /**
     * Get game ID
     */
    public function get_id(): string {
        return $this->id;
    }

    /**
     * Check if game supports AI
     */
    public function supports_ai(): bool {
        return $this->ai_supported;
    }

    /**
     * Get player count range
     */
    public function get_player_range(): array {
        return [
            'min' => $this->min_players,
            'max' => $this->max_players,
        ];
    }
}
