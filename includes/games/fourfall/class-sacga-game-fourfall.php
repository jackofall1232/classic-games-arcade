<?php
/**
 * Fourfall Game Module
 *
 * Drop discs into columns to connect 4 in a row
 * - 7 columns, 6 rows
 * - Gravity-based disc dropping
 * - Win by connecting 4 horizontally, vertically, or diagonally
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_Game_Fourfall extends SACGA_Game_Contract {

    protected $id = 'fourfall';
    protected $name = 'Fourfall';
    protected $type = 'board';
    protected $min_players = 2;
    protected $max_players = 2;
    protected $has_teams = false;
    protected $ai_supported = true;

    /**
     * Board constants
     */
    const ROWS = 6;
    const COLS = 7;
    const EMPTY = 0;
    const RED = 1;
    const YELLOW = 2;

    /**
     * Register the game
     */
    public function register_game(): array {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'type'         => $this->type,
            'min_players'  => $this->min_players,
            'max_players'  => $this->max_players,
            'has_teams'    => $this->has_teams,
            'ai_supported' => $this->ai_supported,
            'description'  => __( 'Drop your pieces into the grid and be the first to connect four in a row. Pieces fall from the top into the lowest available space.', 'shortcode-arcade' ),
            'rules'        => [
                'objective' => __( 'Be the first to connect four of your pieces in a row.', 'shortcode-arcade' ),
                'setup'     => __( "2 players with a 7-column × 6-row vertical grid.\nOne player is Red, the other is Yellow. Red goes first.", 'shortcode-arcade' ),
                'gameplay'  => __( "On your turn, drop one piece into any column that isn't full.\nThe piece falls to the lowest available space in that column.\nPlayers alternate turns.", 'shortcode-arcade' ),
                'winning'   => __( 'Win by connecting 4 pieces in a row horizontally, vertically, or diagonally. The game is a draw if the board fills with no winner.', 'shortcode-arcade' ),
            ],
        ];
    }

    /**
     * Initialize game state
     */
    public function init_state( array $players, array $settings = [] ): array {
        $match_settings = $this->get_match_settings( $settings );
        return [
            'board'        => [],
            'current_turn' => $match_settings['starting_player'], // Red moves first (seat 0)
            'players'      => $this->format_players( $players ),
            'move_count'   => 0,
            'game_over'    => false,
            'winner'       => null,
            'winning_cells' => [],
            'match'        => [
                'games_per_match' => $match_settings['games_per_match'],
                'wins_required'   => $match_settings['wins_required'],
                'games_played'    => 0,
                'wins'            => [ 0, 0 ],
                'draws'           => 0,
                'game_number'     => 1,
                'starting_player' => $match_settings['starting_player'],
                'starting_policy' => $match_settings['starting_policy'],
                'match_over'      => false,
                'winner'          => null,
                'end_reason'      => null,
                'last_result'     => null,
            ],
            'last_move_at' => time(),
        ];
    }

    /**
     * Set up the board (empty)
     */
    public function deal_or_setup( array $state ): array {
        // Create empty 6x7 board (row 0 is top, row 5 is bottom)
        $state['board'] = $this->create_empty_board();
        return $state;
    }

    /**
     * Format players for state
     */
    private function format_players( array $players ): array {
        $formatted = [];
        foreach ( $players as $player ) {
            $seat = (int) $player['seat_position'];
            $formatted[ $seat ] = [
                'name'  => $player['display_name'],
                'is_ai' => (bool) $player['is_ai'],
                'color' => $seat === 0 ? 'red' : 'yellow',
            ];
        }
        return $formatted;
    }

    /**
     * Validate a move (column selection)
     */
    public function validate_move( array $state, int $player_seat, array $move ) {
        // Check if it's this player's turn
        if ( $state['current_turn'] !== $player_seat ) {
            return new WP_Error( 'not_your_turn', __( 'It is not your turn.', 'shortcode-arcade' ) );
        }

        // Check game not over
        if ( $state['game_over'] ) {
            return new WP_Error( 'game_over', __( 'Game is over.', 'shortcode-arcade' ) );
        }

        // Extract column
        $col = $move['col'] ?? null;

        if ( $col === null || ! is_int( $col ) ) {
            return new WP_Error( 'invalid_move', __( 'Must specify a column.', 'shortcode-arcade' ) );
        }

        // Validate column bounds
        if ( $col < 0 || $col >= self::COLS ) {
            return new WP_Error( 'out_of_bounds', __( 'Column is out of bounds.', 'shortcode-arcade' ) );
        }

        // Check column not full
        if ( $state['board'][0][ $col ] !== self::EMPTY ) {
            return new WP_Error( 'column_full', __( 'That column is full.', 'shortcode-arcade' ) );
        }

        return true;
    }

    /**
     * Apply a move (drop disc in column)
     */
    public function apply_move( array $state, int $player_seat, array $move ): array {
        $col = $move['col'];
        $disc = $player_seat === 0 ? self::RED : self::YELLOW;

        $drop = $this->drop_disc( $state['board'], $col, $disc );
        $state['board'] = $drop['board'];
        $state['move_count']++;
        $state['last_move'] = [ 'row' => $drop['row'], 'col' => $col ];

        return $this->maybe_handle_game_end( $state );
    }

    /**
     * Advance to next turn
     */
    public function advance_turn( array $state ): array {
        if ( ! empty( $state['skip_advance'] ) ) {
            unset( $state['skip_advance'] );
            return $state;
        }
        $state['current_turn'] = $state['current_turn'] === 0 ? 1 : 0;
        return $state;
    }

    /**
     * Check end condition (4 in a row or draw)
     */
    public function check_end_condition( array $state ): array {
        if ( ! empty( $state['match']['match_over'] ) ) {
            return [
                'ended'   => true,
                'reason'  => $state['match']['end_reason'] ?? 'match_over',
                'winners' => $state['match']['winner'] !== null ? [ $state['match']['winner'] ] : [],
            ];
        }

        return [
            'ended'   => false,
            'reason'  => null,
            'winners' => null,
        ];
    }

    /**
     * Check for a winning pattern
     */
    private function check_win( array $board ): ?array {
        // Directions: horizontal, vertical, diagonal down-right, diagonal down-left
        $directions = [
            [ 0, 1 ],   // horizontal
            [ 1, 0 ],   // vertical
            [ 1, 1 ],   // diagonal \
            [ 1, -1 ],  // diagonal /
        ];

        for ( $row = 0; $row < self::ROWS; $row++ ) {
            for ( $col = 0; $col < self::COLS; $col++ ) {
                $disc = $board[ $row ][ $col ];
                if ( $disc === self::EMPTY ) {
                    continue;
                }

                foreach ( $directions as $dir ) {
                    $cells = $this->check_direction( $board, $row, $col, $dir[0], $dir[1], $disc );
                    if ( $cells !== null ) {
                        $winner = $disc === self::RED ? 0 : 1;
                        return [
                            'winner' => $winner,
                            'cells'  => $cells,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check 4 in a row in a specific direction
     */
    private function check_direction( array $board, int $row, int $col, int $dr, int $dc, int $disc ): ?array {
        $cells = [];

        for ( $i = 0; $i < 4; $i++ ) {
            $r = $row + ( $dr * $i );
            $c = $col + ( $dc * $i );

            if ( $r < 0 || $r >= self::ROWS || $c < 0 || $c >= self::COLS ) {
                return null;
            }

            if ( $board[ $r ][ $c ] !== $disc ) {
                return null;
            }

            $cells[] = [ 'row' => $r, 'col' => $c ];
        }

        return $cells;
    }

    /**
     * Score round (Fourfall doesn't have rounds, just win/lose/draw)
     */
    public function score_round( array $state ): array {
        return $state;
    }

    /**
     * Get AI move
     */
    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        $difficulty = $difficulty === 'easy' ? 'beginner' : $difficulty;
        $valid_moves = $this->get_valid_moves( $state, $player_seat );

        if ( empty( $valid_moves ) ) {
            return [];
        }

        // Expert: Use minimax with alpha-beta pruning
        if ( $difficulty === 'expert' ) {
            return $this->minimax_move( $state, $player_seat, 5 );
        }

        // Intermediate: Shallower minimax
        if ( $difficulty === 'intermediate' ) {
            return $this->minimax_move( $state, $player_seat, 3 );
        }

        // Beginner: Simple strategy - check for wins/blocks, then prefer center
        return $this->simple_ai_move( $state, $player_seat, $valid_moves );
    }

    /**
     * Simple AI strategy for beginner level
     */
    private function simple_ai_move( array $state, int $player_seat, array $valid_moves ): array {
        $board = $state['board'];

        // Check for winning move
        foreach ( $valid_moves as $move ) {
            $test_state = $this->simulate_state_after_move( $state, $player_seat, $move );
            $win = $this->check_win( $test_state['board'] );
            if ( $win !== null && $win['winner'] === $player_seat ) {
                return $move;
            }
        }

        // Check for blocking move
        $opponent = $player_seat === 0 ? 1 : 0;
        foreach ( $valid_moves as $move ) {
            $test_state = $this->simulate_state_after_move( $state, $opponent, $move );
            $win = $this->check_win( $test_state['board'] );
            if ( $win !== null && $win['winner'] === $opponent ) {
                return $move;
            }
        }

        // Prefer center column, then adjacent, then edges
        $col_priority = [ 3, 2, 4, 1, 5, 0, 6 ];
        foreach ( $col_priority as $col ) {
            foreach ( $valid_moves as $move ) {
                if ( $move['col'] === $col ) {
                    return $move;
                }
            }
        }

        // Fallback to first available
        return $valid_moves[0];
    }

    /**
     * Get all valid moves for a player
     */
    public function get_valid_moves( array $state, int $player_seat ): array {
        $moves = [];
        $board = $state['board'];

        for ( $col = 0; $col < self::COLS; $col++ ) {
            if ( $board[0][ $col ] === self::EMPTY ) {
                $moves[] = [ 'col' => $col ];
            }
        }

        return $moves;
    }

    /**
     * Get public state (board games don't hide info)
     */
    public function get_public_state( array $state, int $player_seat ): array {
        return $state;
    }

    /**
     * Minimax with alpha-beta pruning
     */
    private function minimax_move( array $state, int $player_seat, int $depth ): array {
        $valid_moves = $this->get_valid_moves( $state, $player_seat );
        $best_move = $valid_moves[0] ?? [];
        $best_score = PHP_INT_MIN;

        foreach ( $valid_moves as $move ) {
            $new_state = $this->apply_move( $state, $player_seat, $move );
            $new_state = $this->advance_turn( $new_state );

            $score = $this->minimax( $new_state, $depth - 1, false, $player_seat, PHP_INT_MIN, PHP_INT_MAX );

            if ( $score > $best_score ) {
                $best_score = $score;
                $best_move = $move;
            }
        }

        return $best_move;
    }

    private function minimax( array $state, int $depth, bool $is_maximizing, int $ai_seat, int $alpha, int $beta ): int {
        // Check for terminal state
        $result = $this->get_game_result( $state['board'] );
        if ( $result['ended'] ) {
            if ( $result['reason'] === 'draw' ) {
                return 0;
            }
            $winner = $result['winner'] ?? -1;
            return $winner === $ai_seat ? 10000 + $depth : -10000 - $depth;
        }

        if ( $depth === 0 ) {
            return $this->evaluate_board( $state['board'], $ai_seat );
        }

        $current_player = $state['current_turn'];
        $valid_moves = $this->get_valid_moves( $state, $current_player );

        if ( empty( $valid_moves ) ) {
            return 0;
        }

        if ( $is_maximizing ) {
            $max_eval = PHP_INT_MIN;
            foreach ( $valid_moves as $move ) {
                $new_state = $this->simulate_state_after_move( $state, $current_player, $move );
                $new_state = $this->advance_turn( $new_state );
                $eval = $this->minimax( $new_state, $depth - 1, false, $ai_seat, $alpha, $beta );
                $max_eval = max( $max_eval, $eval );
                $alpha = max( $alpha, $eval );
                if ( $beta <= $alpha ) {
                    break;
                }
            }
            return $max_eval;
        } else {
            $min_eval = PHP_INT_MAX;
            foreach ( $valid_moves as $move ) {
                $new_state = $this->simulate_state_after_move( $state, $current_player, $move );
                $new_state = $this->advance_turn( $new_state );
                $eval = $this->minimax( $new_state, $depth - 1, true, $ai_seat, $alpha, $beta );
                $min_eval = min( $min_eval, $eval );
                $beta = min( $beta, $eval );
                if ( $beta <= $alpha ) {
                    break;
                }
            }
            return $min_eval;
        }
    }

    /**
     * Evaluate board position for AI
     */
    private function evaluate_board( array $board, int $ai_seat ): int {
        $score = 0;
        $my_disc = $ai_seat === 0 ? self::RED : self::YELLOW;
        $opp_disc = $ai_seat === 0 ? self::YELLOW : self::RED;

        // Evaluate all windows of 4
        $directions = [
            [ 0, 1 ],   // horizontal
            [ 1, 0 ],   // vertical
            [ 1, 1 ],   // diagonal \
            [ 1, -1 ],  // diagonal /
        ];

        for ( $row = 0; $row < self::ROWS; $row++ ) {
            for ( $col = 0; $col < self::COLS; $col++ ) {
                foreach ( $directions as $dir ) {
                    $score += $this->evaluate_window( $board, $row, $col, $dir[0], $dir[1], $my_disc, $opp_disc );
                }
            }
        }

        // Bonus for center column control
        $center_col = 3;
        for ( $row = 0; $row < self::ROWS; $row++ ) {
            if ( $board[ $row ][ $center_col ] === $my_disc ) {
                $score += 3;
            }
        }

        return $score;
    }

    /**
     * Evaluate a window of 4 cells
     */
    private function evaluate_window( array $board, int $row, int $col, int $dr, int $dc, int $my_disc, int $opp_disc ): int {
        $my_count = 0;
        $opp_count = 0;
        $empty_count = 0;

        for ( $i = 0; $i < 4; $i++ ) {
            $r = $row + ( $dr * $i );
            $c = $col + ( $dc * $i );

            if ( $r < 0 || $r >= self::ROWS || $c < 0 || $c >= self::COLS ) {
                return 0;
            }

            $cell = $board[ $r ][ $c ];
            if ( $cell === $my_disc ) {
                $my_count++;
            } elseif ( $cell === $opp_disc ) {
                $opp_count++;
            } else {
                $empty_count++;
            }
        }

        // Score the window
        if ( $my_count === 4 ) {
            return 100;
        }
        if ( $my_count === 3 && $empty_count === 1 ) {
            return 5;
        }
        if ( $my_count === 2 && $empty_count === 2 ) {
            return 2;
        }
        if ( $opp_count === 3 && $empty_count === 1 ) {
            return -4;
        }

        return 0;
    }

    /**
     * Match settings defaults
     */
    private function get_match_settings( array $settings ): array {
        $match_settings = $settings['match'] ?? [];

        $games_per_match = max( 1, (int) ( $match_settings['games_per_match'] ?? 5 ) );
        $wins_required = max( 1, (int) ( $match_settings['wins_required'] ?? 3 ) );

        if ( $wins_required > $games_per_match ) {
            $wins_required = $games_per_match;
        }

        return [
            'games_per_match' => $games_per_match,
            'wins_required'   => $wins_required,
            'starting_player' => 0,
            'starting_policy' => $match_settings['starting_player'] ?? 'alternate',
        ];
    }

    /**
     * Create an empty board
     */
    private function create_empty_board(): array {
        $board = [];
        for ( $row = 0; $row < self::ROWS; $row++ ) {
            $board[ $row ] = array_fill( 0, self::COLS, self::EMPTY );
        }

        return $board;
    }

    /**
     * Drop a disc into a column
     */
    private function drop_disc( array $board, int $col, int $disc ): array {
        $target_row = -1;
        for ( $row = self::ROWS - 1; $row >= 0; $row-- ) {
            if ( $board[ $row ][ $col ] === self::EMPTY ) {
                $target_row = $row;
                break;
            }
        }

        $board[ $target_row ][ $col ] = $disc;

        return [
            'board' => $board,
            'row'   => $target_row,
        ];
    }

    /**
     * Simulate a move without affecting match tracking
     */
    private function simulate_state_after_move( array $state, int $player_seat, array $move ): array {
        $disc = $player_seat === 0 ? self::RED : self::YELLOW;
        $drop = $this->drop_disc( $state['board'], $move['col'], $disc );
        $state['board'] = $drop['board'];
        $state['last_move'] = [ 'row' => $drop['row'], 'col' => $move['col'] ];

        return $state;
    }

    /**
     * Determine if the current board is a win or draw
     */
    private function get_game_result( array $board ): array {
        $win = $this->check_win( $board );
        if ( $win !== null ) {
            return [
                'ended'         => true,
                'reason'        => 'four_in_row',
                'winner'        => $win['winner'],
                'winning_cells' => $win['cells'],
            ];
        }

        $is_full = true;
        for ( $col = 0; $col < self::COLS; $col++ ) {
            if ( $board[0][ $col ] === self::EMPTY ) {
                $is_full = false;
                break;
            }
        }

        if ( $is_full ) {
            return [
                'ended'  => true,
                'reason' => 'draw',
                'winner' => null,
            ];
        }

        return [
            'ended'  => false,
            'reason' => null,
            'winner' => null,
        ];
    }

    /**
     * Update state for match progression if a game ended
     */
    private function maybe_handle_game_end( array $state ): array {
        $result = $this->get_game_result( $state['board'] );
        if ( ! $result['ended'] ) {
            return $state;
        }

        $state['match']['games_played']++;
        $state['match']['last_result'] = [
            'winner'      => $result['winner'],
            'reason'      => $result['reason'],
            'game_number' => $state['match']['game_number'],
        ];

        if ( $result['winner'] !== null ) {
            $state['match']['wins'][ $result['winner'] ]++;
        } else {
            $state['match']['draws']++;
        }

        $match_end = $this->get_match_end( $state['match'] );
        $state['match']['match_over'] = $match_end['over'];
        $state['match']['winner'] = $match_end['winner'];
        $state['match']['end_reason'] = $match_end['reason'];

        if ( $match_end['over'] ) {
            $state['winning_cells'] = $result['winning_cells'] ?? [];
            $state['winner'] = $match_end['winner'];
            return $state;
        }

        $state['board'] = $this->create_empty_board();
        $state['move_count'] = 0;
        $state['winning_cells'] = [];
        $state['last_move'] = null;

        $state['match']['game_number']++;
        $state['match']['starting_player'] = $this->get_next_starting_player(
            $state['match']['starting_player'],
            $state['match']['starting_policy']
        );
        $state['current_turn'] = $state['match']['starting_player'];
        $state['skip_advance'] = true;

        return $state;
    }

    /**
     * Determine match completion
     */
    private function get_match_end( array $match ): array {
        $wins_required = (int) ( $match['wins_required'] ?? 0 );
        $games_per_match = (int) ( $match['games_per_match'] ?? 0 );
        $wins = $match['wins'] ?? [ 0, 0 ];
        $games_played = (int) ( $match['games_played'] ?? 0 );

        foreach ( $wins as $seat => $count ) {
            if ( $count >= $wins_required ) {
                return [
                    'over'   => true,
                    'winner' => $seat,
                    'reason' => 'match_win',
                ];
            }
        }

        if ( $games_played >= $games_per_match ) {
            return [
                'over'   => true,
                'winner' => null,
                'reason' => 'match_draw',
            ];
        }

        return [
            'over'   => false,
            'winner' => null,
            'reason' => null,
        ];
    }

    /**
     * Determine next starting player for a new game
     */
    private function get_next_starting_player( int $current, string $policy ): int {
        if ( $policy === 'alternate' ) {
            return $current === 0 ? 1 : 0;
        }

        return $current;
    }
}
