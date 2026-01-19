<?php
/**
 * Checkers Game Module
 * 
 * Standard 8x8 American Checkers (English Draughts)
 * - 12 pieces per player
 * - Mandatory captures
 * - Kings can move backward
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_Game_Checkers extends SACGA_Game_Contract {

    protected $id = 'checkers';
    protected $name = 'Checkers';
    protected $type = 'board';
    protected $min_players = 2;
    protected $max_players = 2;
    protected $has_teams = false;
    protected $ai_supported = true;

    /**
     * Board constants
     */
    const BOARD_SIZE = 8;
    const EMPTY = 0;
    const BLACK = 1;
    const WHITE = 2;
    const BLACK_KING = 3;
    const WHITE_KING = 4;

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
            'description'  => __( 'Classic American Checkers. Capture all opponent pieces to win.', 'shortcode-arcade' ),
            'rules'        => [
                'objective' => __( 'Capture all of your opponent\'s pieces or block them so they cannot move.', 'shortcode-arcade' ),
                'setup'     => __( "2 players on an 8×8 board.\nEach player starts with 12 pieces on the dark squares of their three nearest rows.", 'shortcode-arcade' ),
                'gameplay'  => __( "Pieces move diagonally forward one square to an empty dark square.\nCaptures are mandatory: jump over an adjacent opponent piece to an empty square beyond.\nMultiple jumps in one turn are allowed if available.\nWhen a piece reaches the opposite end, it becomes a King.", 'shortcode-arcade' ),
                'winning'   => __( 'Win by capturing all opponent pieces or blocking them from moving.', 'shortcode-arcade' ),
                'notes'     => __( 'Kings can move and capture diagonally in both directions.', 'shortcode-arcade' ),
            ],
        ];
    }

    /**
     * Initialize game state
     */
    public function init_state( array $players, array $settings = [] ): array {
        return [
            'board'        => [],
            'current_turn' => 0, // Black moves first (seat 0)
            'players'      => $this->format_players( $players ),
            'captured'     => [ 0 => 0, 1 => 0 ],
            'must_jump'    => false,
            'jump_piece'   => null, // Position if in middle of multi-jump
            'move_count'   => 0,
            'game_over'    => false,
            'last_move_at' => time(), // Timestamp of last move for timeout detection
        ];
    }

    /**
     * Set up the board
     */
    public function deal_or_setup( array $state ): array {
        $board = array_fill( 0, self::BOARD_SIZE, array_fill( 0, self::BOARD_SIZE, self::EMPTY ) );

        // Place black pieces (top 3 rows)
        for ( $row = 0; $row < 3; $row++ ) {
            for ( $col = 0; $col < self::BOARD_SIZE; $col++ ) {
                if ( ( $row + $col ) % 2 === 1 ) {
                    $board[ $row ][ $col ] = self::BLACK;
                }
            }
        }

        // Place white pieces (bottom 3 rows)
        for ( $row = 5; $row < self::BOARD_SIZE; $row++ ) {
            for ( $col = 0; $col < self::BOARD_SIZE; $col++ ) {
                if ( ( $row + $col ) % 2 === 1 ) {
                    $board[ $row ][ $col ] = self::WHITE;
                }
            }
        }

        $state['board'] = $board;
        $state['must_jump'] = $this->has_capture_available( $board, 0 );

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
                'color' => $seat === 0 ? 'black' : 'white',
            ];
        }
        return $formatted;
    }

    /**
     * Validate a move
     */
    public function validate_move( array $state, int $player_seat, array $move ) {
        // Check if it's this player's turn
        if ( $state['current_turn'] !== $player_seat ) {
            return new WP_Error( 'not_your_turn', __( 'It is not your turn.', 'shortcode-arcade' ) );
        }

        // Extract move data
        $from_row = $move['from']['row'] ?? null;
        $from_col = $move['from']['col'] ?? null;
        $to_row = $move['to']['row'] ?? null;
        $to_col = $move['to']['col'] ?? null;

        if ( $from_row === null || $from_col === null || $to_row === null || $to_col === null ) {
            return new WP_Error( 'invalid_move', __( 'Move must include from and to positions.', 'shortcode-arcade' ) );
        }

        // Validate bounds
        if ( ! $this->in_bounds( $from_row, $from_col ) || ! $this->in_bounds( $to_row, $to_col ) ) {
            return new WP_Error( 'out_of_bounds', __( 'Position is out of bounds.', 'shortcode-arcade' ) );
        }

        $board = $state['board'];
        $piece = $board[ $from_row ][ $from_col ];

        // Check piece belongs to player
        if ( ! $this->is_player_piece( $piece, $player_seat ) ) {
            return new WP_Error( 'not_your_piece', __( 'That is not your piece.', 'shortcode-arcade' ) );
        }

        // If in multi-jump, must continue with same piece - but check if piece still exists
        if ( $state['jump_piece'] !== null ) {
            $jump_row = $state['jump_piece']['row'];
            $jump_col = $state['jump_piece']['col'];
            $jump_piece = $board[ $jump_row ][ $jump_col ];

            // If the jump piece was captured or is no longer ours, clear the jump state
            if ( ! $this->is_player_piece( $jump_piece, $player_seat ) ) {
                $state['jump_piece'] = null;
                $state['must_jump'] = $this->has_capture_available( $board, $player_seat );
            } elseif ( $jump_row !== $from_row || $jump_col !== $from_col ) {
                // Jump piece still exists but player is trying to move a different piece
                return new WP_Error( 'must_continue_jump', __( 'You must continue jumping with the same piece.', 'shortcode-arcade' ) );
            }
        }

        // Check destination is empty
        if ( $board[ $to_row ][ $to_col ] !== self::EMPTY ) {
            return new WP_Error( 'occupied', __( 'Destination square is occupied.', 'shortcode-arcade' ) );
        }

        // Calculate move deltas
        $row_delta = $to_row - $from_row;
        $col_delta = abs( $to_col - $from_col );

        $is_king = $piece === self::BLACK_KING || $piece === self::WHITE_KING;
        $forward = $player_seat === 0 ? 1 : -1; // Black moves down, white moves up

        // Simple move (1 diagonal)
        if ( abs( $row_delta ) === 1 && $col_delta === 1 ) {
            // Check direction for non-kings
            if ( ! $is_king && $row_delta !== $forward ) {
                return new WP_Error( 'wrong_direction', __( 'Pieces can only move forward.', 'shortcode-arcade' ) );
            }

            // Must capture if capture is available
            if ( $state['must_jump'] || $state['jump_piece'] !== null ) {
                return new WP_Error( 'must_capture', __( 'You must make a capture.', 'shortcode-arcade' ) );
            }

            return true;
        }

        // Jump move (2 diagonal)
        if ( abs( $row_delta ) === 2 && $col_delta === 2 ) {
            // Check direction for non-kings
            if ( ! $is_king && $row_delta !== $forward * 2 ) {
                return new WP_Error( 'wrong_direction', __( 'Pieces can only move forward.', 'shortcode-arcade' ) );
            }

            // Check there's an enemy piece to jump
            $mid_row = $from_row + ( $row_delta / 2 );
            $mid_col = $from_col + ( ( $to_col - $from_col ) / 2 );
            $jumped = $board[ (int) $mid_row ][ (int) $mid_col ];

            if ( ! $this->is_opponent_piece( $jumped, $player_seat ) ) {
                return new WP_Error( 'invalid_jump', __( 'Must jump over an opponent piece.', 'shortcode-arcade' ) );
            }

            return true;
        }

        return new WP_Error( 'invalid_move', __( 'Invalid move.', 'shortcode-arcade' ) );
    }

    /**
     * Apply a move
     */
    public function apply_move( array $state, int $player_seat, array $move ): array {
        $from_row = $move['from']['row'];
        $from_col = $move['from']['col'];
        $to_row = $move['to']['row'];
        $to_col = $move['to']['col'];

        $board = $state['board'];
        $piece = $board[ $from_row ][ $from_col ];

        // Move the piece
        $board[ $from_row ][ $from_col ] = self::EMPTY;
        $board[ $to_row ][ $to_col ] = $piece;

        // Handle capture
        $was_capture = false;
        $row_delta = abs( $to_row - $from_row );

        if ( $row_delta === 2 ) {
            $mid_row = (int) ( ( $from_row + $to_row ) / 2 );
            $mid_col = (int) ( ( $from_col + $to_col ) / 2 );
            $board[ $mid_row ][ $mid_col ] = self::EMPTY;
            $state['captured'][ $player_seat ]++;
            $was_capture = true;
        }

        // Check for king promotion
        $promotion_row = $player_seat === 0 ? self::BOARD_SIZE - 1 : 0;
        if ( $to_row === $promotion_row && ! $this->is_king( $piece ) ) {
            $board[ $to_row ][ $to_col ] = $player_seat === 0 ? self::BLACK_KING : self::WHITE_KING;
        }

        $state['board'] = $board;
        $state['move_count']++;

        // Check for multi-jump
        if ( $was_capture ) {
            $can_continue = $this->get_jumps_from( $board, $to_row, $to_col, $player_seat );
            if ( ! empty( $can_continue ) ) {
                $state['jump_piece'] = [ 'row' => $to_row, 'col' => $to_col ];
                $state['must_jump'] = true;
                return $state; // Don't advance turn
            }
        }

        // Clear multi-jump state
        $state['jump_piece'] = null;

        return $state;
    }

    /**
     * Advance to next turn
     */
    public function advance_turn( array $state ): array {
        $state['current_turn'] = $state['current_turn'] === 0 ? 1 : 0;
        $state['jump_piece'] = null; // Clear multi-jump state when turn changes
        $state['must_jump'] = $this->has_capture_available( $state['board'], $state['current_turn'] );
        return $state;
    }

    /**
     * Check end condition
     */
    public function check_end_condition( array $state ): array {
        $current = $state['current_turn'];
        $valid_moves = $this->get_valid_moves( $state, $current );

        // No valid moves = loss
        if ( empty( $valid_moves ) ) {
            $winner = $current === 0 ? 1 : 0;
            return [
                'ended'   => true,
                'reason'  => 'no_moves',
                'winners' => [ $winner ],
            ];
        }

        // Check if opponent has any pieces
        $opponent = $current === 0 ? 1 : 0;
        $opponent_pieces = $this->count_pieces( $state['board'], $opponent );

        if ( $opponent_pieces === 0 ) {
            return [
                'ended'   => true,
                'reason'  => 'captured_all',
                'winners' => [ $current ],
            ];
        }

        return [
            'ended'   => false,
            'reason'  => null,
            'winners' => null,
        ];
    }

    /**
     * Score round (checkers doesn't have rounds, just win/lose)
     */
    public function score_round( array $state ): array {
        return $state;
    }

    /**
     * Get AI move
     */
    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        $valid_moves = $this->get_valid_moves( $state, $player_seat );

        if ( empty( $valid_moves ) ) {
            return [];
        }

        // Expert: Use minimax
        if ( $difficulty === 'expert' ) {
            return $this->minimax_move( $state, $player_seat, 4 );
        }

        // Intermediate: Mix of optimal and random
        if ( $difficulty === 'intermediate' ) {
            if ( mt_rand( 1, 100 ) <= 50 ) {
                return $this->minimax_move( $state, $player_seat, 2 );
            }
        }

        // Beginner: Mostly random, slight preference for captures
        $captures = array_filter( $valid_moves, fn( $m ) => $m['is_capture'] );

        if ( ! empty( $captures ) && mt_rand( 0, 3 ) > 0 ) {
            return $captures[ array_rand( $captures ) ];
        }

        return $valid_moves[ array_rand( $valid_moves ) ];
    }

    /**
     * Get all valid moves for a player
     */
    public function get_valid_moves( array $state, int $player_seat ): array {
        $board = $state['board'];
        $moves = [];
        $captures = [];

        // If in multi-jump, only consider that piece
        if ( ! empty( $state['jump_piece'] ) ) {
            $row = $state['jump_piece']['row'];
            $col = $state['jump_piece']['col'];
            return $this->get_jumps_from( $board, $row, $col, $player_seat );
        }

        // Find all pieces and their moves
        for ( $row = 0; $row < self::BOARD_SIZE; $row++ ) {
            for ( $col = 0; $col < self::BOARD_SIZE; $col++ ) {
                $piece = $board[ $row ][ $col ];

                if ( ! $this->is_player_piece( $piece, $player_seat ) ) {
                    continue;
                }

                // Get jumps
                $piece_jumps = $this->get_jumps_from( $board, $row, $col, $player_seat );
                $captures = array_merge( $captures, $piece_jumps );

                // Get simple moves
                $piece_moves = $this->get_simple_moves( $board, $row, $col, $player_seat );
                $moves = array_merge( $moves, $piece_moves );
            }
        }

        // Must capture if captures available
        if ( ! empty( $captures ) ) {
            return $captures;
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
     * Get jumps from a position
     */
    private function get_jumps_from( array $board, int $row, int $col, int $player_seat ): array {
        $piece = $board[ $row ][ $col ];
        $is_king = $this->is_king( $piece );
        $jumps = [];

        $directions = $is_king
            ? [ [ -1, -1 ], [ -1, 1 ], [ 1, -1 ], [ 1, 1 ] ]
            : ( $player_seat === 0 ? [ [ 1, -1 ], [ 1, 1 ] ] : [ [ -1, -1 ], [ -1, 1 ] ] );

        foreach ( $directions as $dir ) {
            $mid_row = $row + $dir[0];
            $mid_col = $col + $dir[1];
            $to_row = $row + ( $dir[0] * 2 );
            $to_col = $col + ( $dir[1] * 2 );

            if ( ! $this->in_bounds( $to_row, $to_col ) ) {
                continue;
            }

            if ( $this->is_opponent_piece( $board[ $mid_row ][ $mid_col ], $player_seat ) &&
                 $board[ $to_row ][ $to_col ] === self::EMPTY ) {
                $jumps[] = [
                    'from'       => [ 'row' => $row, 'col' => $col ],
                    'to'         => [ 'row' => $to_row, 'col' => $to_col ],
                    'is_capture' => true,
                ];
            }
        }

        return $jumps;
    }

    /**
     * Get simple (non-capture) moves from a position
     */
    private function get_simple_moves( array $board, int $row, int $col, int $player_seat ): array {
        $piece = $board[ $row ][ $col ];
        $is_king = $this->is_king( $piece );
        $moves = [];

        $directions = $is_king
            ? [ [ -1, -1 ], [ -1, 1 ], [ 1, -1 ], [ 1, 1 ] ]
            : ( $player_seat === 0 ? [ [ 1, -1 ], [ 1, 1 ] ] : [ [ -1, -1 ], [ -1, 1 ] ] );

        foreach ( $directions as $dir ) {
            $to_row = $row + $dir[0];
            $to_col = $col + $dir[1];

            if ( $this->in_bounds( $to_row, $to_col ) && $board[ $to_row ][ $to_col ] === self::EMPTY ) {
                $moves[] = [
                    'from'       => [ 'row' => $row, 'col' => $col ],
                    'to'         => [ 'row' => $to_row, 'col' => $to_col ],
                    'is_capture' => false,
                ];
            }
        }

        return $moves;
    }

    /**
     * Check if capture is available
     */
    private function has_capture_available( array $board, int $player_seat ): bool {
        for ( $row = 0; $row < self::BOARD_SIZE; $row++ ) {
            for ( $col = 0; $col < self::BOARD_SIZE; $col++ ) {
                if ( $this->is_player_piece( $board[ $row ][ $col ], $player_seat ) ) {
                    if ( ! empty( $this->get_jumps_from( $board, $row, $col, $player_seat ) ) ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Helper functions
     */
    private function in_bounds( int $row, int $col ): bool {
        return $row >= 0 && $row < self::BOARD_SIZE && $col >= 0 && $col < self::BOARD_SIZE;
    }

    private function is_player_piece( int $piece, int $player_seat ): bool {
        if ( $player_seat === 0 ) {
            return $piece === self::BLACK || $piece === self::BLACK_KING;
        }
        return $piece === self::WHITE || $piece === self::WHITE_KING;
    }

    private function is_opponent_piece( int $piece, int $player_seat ): bool {
        if ( $player_seat === 0 ) {
            return $piece === self::WHITE || $piece === self::WHITE_KING;
        }
        return $piece === self::BLACK || $piece === self::BLACK_KING;
    }

    private function is_king( int $piece ): bool {
        return $piece === self::BLACK_KING || $piece === self::WHITE_KING;
    }

    private function count_pieces( array $board, int $player_seat ): int {
        $count = 0;
        for ( $row = 0; $row < self::BOARD_SIZE; $row++ ) {
            for ( $col = 0; $col < self::BOARD_SIZE; $col++ ) {
                if ( $this->is_player_piece( $board[ $row ][ $col ], $player_seat ) ) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Minimax for AI
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
        if ( $depth === 0 || ! empty( $state['game_over'] ) ) {
            return $this->evaluate_board( $state['board'], $ai_seat );
        }

        $current_player = $state['current_turn'];
        $valid_moves = $this->get_valid_moves( $state, $current_player );

        if ( empty( $valid_moves ) ) {
            return $is_maximizing ? PHP_INT_MIN : PHP_INT_MAX;
        }

        if ( $is_maximizing ) {
            $max_eval = PHP_INT_MIN;
            foreach ( $valid_moves as $move ) {
                $new_state = $this->apply_move( $state, $current_player, $move );
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
                $new_state = $this->apply_move( $state, $current_player, $move );
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

    private function evaluate_board( array $board, int $ai_seat ): int {
        $score = 0;
        $opponent = $ai_seat === 0 ? 1 : 0;

        for ( $row = 0; $row < self::BOARD_SIZE; $row++ ) {
            for ( $col = 0; $col < self::BOARD_SIZE; $col++ ) {
                $piece = $board[ $row ][ $col ];

                if ( $this->is_player_piece( $piece, $ai_seat ) ) {
                    $score += $this->is_king( $piece ) ? 3 : 1;
                    // Bonus for advanced position
                    $advance = $ai_seat === 0 ? $row : ( self::BOARD_SIZE - 1 - $row );
                    $score += $advance * 0.1;
                } elseif ( $this->is_player_piece( $piece, $opponent ) ) {
                    $score -= $this->is_king( $piece ) ? 3 : 1;
                }
            }
        }

        return (int) ( $score * 100 );
    }
}
