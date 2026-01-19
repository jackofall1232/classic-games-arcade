<?php
/**
 * Chess Game Module
 *
 * Standard 8x8 Chess with basic movement validation
 * Phase 1: Basic movement only (no check/checkmate detection)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_Game_Chess extends SACGA_Game_Contract {

    protected $id = 'chess';
    protected $name = 'Chess';
    protected $type = 'board';
    protected $min_players = 2;
    protected $max_players = 2;
    protected $has_teams = false;
    protected $ai_supported = false; // Human vs human only initially

    /**
     * Board constants
     */
    const BOARD_SIZE = 8;
    const EMPTY = 0;

    // White pieces (positive)
    const W_PAWN   = 1;
    const W_ROOK   = 2;
    const W_KNIGHT = 3;
    const W_BISHOP = 4;
    const W_QUEEN  = 5;
    const W_KING   = 6;

    // Black pieces (negative)
    const B_PAWN   = -1;
    const B_ROOK   = -2;
    const B_KNIGHT = -3;
    const B_BISHOP = -4;
    const B_QUEEN  = -5;
    const B_KING   = -6;

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
            'description'  => __( 'Classic Chess. Capture the opponent\'s king to win!', 'shortcode-arcade' ),
            'rules'        => [
                'objective' => __( 'Checkmate your opponent\'s King so it cannot escape capture.', 'shortcode-arcade' ),
                'setup'     => __( "2 players on an 8×8 board. White moves first.\nBack row (each side): Rook, Knight, Bishop, Queen, King, Bishop, Knight, Rook.\nFront row: 8 Pawns.", 'shortcode-arcade' ),
                'gameplay'  => __( "King: 1 square any direction.\nQueen: Any number of squares in any direction.\nRook: Any number of squares horizontally or vertically.\nBishop: Any number of squares diagonally.\nKnight: L-shape (2+1 squares), can jump over pieces.\nPawn: 1 square forward (2 on first move), captures diagonally.", 'shortcode-arcade' ),
                'winning'   => __( 'Checkmate the opponent\'s King (King is attacked and cannot escape). Game also ends if a King is captured.', 'shortcode-arcade' ),
                'notes'     => __( 'Pawns that reach the opposite end promote to Queen.', 'shortcode-arcade' ),
            ],
        ];
    }

    /**
     * Initialize game state
     */
    public function init_state( array $players, array $settings = [] ): array {
        return [
            'board'        => [],
            'current_turn' => 0, // White moves first (seat 0)
            'players'      => $this->format_players( $players ),
            'captured'     => [ 0 => [], 1 => [] ], // Captured pieces by each player
            'move_count'   => 0,
            'game_over'    => false,
            'winner'       => null,
            'last_move'    => null,
            'last_move_at' => time(),
        ];
    }

    /**
     * Set up the board
     */
    public function deal_or_setup( array $state ): array {
        $board = array_fill( 0, self::BOARD_SIZE, array_fill( 0, self::BOARD_SIZE, self::EMPTY ) );

        // Black pieces (top - row 0 and 1)
        $board[0] = [
            self::B_ROOK, self::B_KNIGHT, self::B_BISHOP, self::B_QUEEN,
            self::B_KING, self::B_BISHOP, self::B_KNIGHT, self::B_ROOK,
        ];
        $board[1] = array_fill( 0, 8, self::B_PAWN );

        // White pieces (bottom - row 6 and 7)
        $board[6] = array_fill( 0, 8, self::W_PAWN );
        $board[7] = [
            self::W_ROOK, self::W_KNIGHT, self::W_BISHOP, self::W_QUEEN,
            self::W_KING, self::W_BISHOP, self::W_KNIGHT, self::W_ROOK,
        ];

        $state['board'] = $board;
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
                'color' => $seat === 0 ? 'white' : 'black',
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

        // Check game not over
        if ( $state['game_over'] ) {
            return new WP_Error( 'game_over', __( 'Game is over.', 'shortcode-arcade' ) );
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

        // Check not capturing own piece
        $dest = $board[ $to_row ][ $to_col ];
        if ( $dest !== self::EMPTY && $this->is_player_piece( $dest, $player_seat ) ) {
            return new WP_Error( 'own_piece', __( 'Cannot capture your own piece.', 'shortcode-arcade' ) );
        }

        // Validate piece-specific movement
        if ( ! $this->is_valid_piece_move( $board, $piece, $from_row, $from_col, $to_row, $to_col, $player_seat ) ) {
            return new WP_Error( 'invalid_move', __( 'Invalid move for this piece.', 'shortcode-arcade' ) );
        }

        return true;
    }

    /**
     * Check if a move is valid for a specific piece type
     */
    private function is_valid_piece_move( array $board, int $piece, int $from_row, int $from_col, int $to_row, int $to_col, int $player_seat ): bool {
        $piece_type = abs( $piece );
        $row_delta = $to_row - $from_row;
        $col_delta = $to_col - $from_col;
        $abs_row = abs( $row_delta );
        $abs_col = abs( $col_delta );
        $dest = $board[ $to_row ][ $to_col ];
        $is_capture = $dest !== self::EMPTY;

        switch ( $piece_type ) {
            case 1: // Pawn
                return $this->is_valid_pawn_move( $board, $from_row, $from_col, $to_row, $to_col, $player_seat, $is_capture );

            case 2: // Rook
                return $this->is_valid_rook_move( $board, $from_row, $from_col, $to_row, $to_col );

            case 3: // Knight
                return ( $abs_row === 2 && $abs_col === 1 ) || ( $abs_row === 1 && $abs_col === 2 );

            case 4: // Bishop
                return $this->is_valid_bishop_move( $board, $from_row, $from_col, $to_row, $to_col );

            case 5: // Queen
                return $this->is_valid_rook_move( $board, $from_row, $from_col, $to_row, $to_col ) ||
                       $this->is_valid_bishop_move( $board, $from_row, $from_col, $to_row, $to_col );

            case 6: // King
                return $abs_row <= 1 && $abs_col <= 1 && ( $abs_row + $abs_col > 0 );

            default:
                return false;
        }
    }

    /**
     * Validate pawn movement
     */
    private function is_valid_pawn_move( array $board, int $from_row, int $from_col, int $to_row, int $to_col, int $player_seat, bool $is_capture ): bool {
        $row_delta = $to_row - $from_row;
        $col_delta = $to_col - $from_col;
        $abs_col = abs( $col_delta );

        // White pawns move up (decreasing row), black pawns move down (increasing row)
        $forward = $player_seat === 0 ? -1 : 1;
        $start_row = $player_seat === 0 ? 6 : 1;

        // Capture move (diagonal)
        if ( $is_capture ) {
            return $row_delta === $forward && $abs_col === 1;
        }

        // Forward move (straight)
        if ( $col_delta !== 0 ) {
            return false;
        }

        // Single step forward
        if ( $row_delta === $forward ) {
            return $board[ $to_row ][ $to_col ] === self::EMPTY;
        }

        // Double step from starting position
        if ( $from_row === $start_row && $row_delta === $forward * 2 ) {
            $mid_row = $from_row + $forward;
            return $board[ $mid_row ][ $from_col ] === self::EMPTY &&
                   $board[ $to_row ][ $to_col ] === self::EMPTY;
        }

        return false;
    }

    /**
     * Validate rook movement (straight lines)
     */
    private function is_valid_rook_move( array $board, int $from_row, int $from_col, int $to_row, int $to_col ): bool {
        $row_delta = $to_row - $from_row;
        $col_delta = $to_col - $from_col;

        // Must move in a straight line
        if ( $row_delta !== 0 && $col_delta !== 0 ) {
            return false;
        }

        // Must move at least one square
        if ( $row_delta === 0 && $col_delta === 0 ) {
            return false;
        }

        // Check path is clear
        return $this->is_path_clear( $board, $from_row, $from_col, $to_row, $to_col );
    }

    /**
     * Validate bishop movement (diagonal lines)
     */
    private function is_valid_bishop_move( array $board, int $from_row, int $from_col, int $to_row, int $to_col ): bool {
        $row_delta = abs( $to_row - $from_row );
        $col_delta = abs( $to_col - $from_col );

        // Must move diagonally
        if ( $row_delta !== $col_delta || $row_delta === 0 ) {
            return false;
        }

        // Check path is clear
        return $this->is_path_clear( $board, $from_row, $from_col, $to_row, $to_col );
    }

    /**
     * Check if the path between two squares is clear
     */
    private function is_path_clear( array $board, int $from_row, int $from_col, int $to_row, int $to_col ): bool {
        $row_step = $to_row > $from_row ? 1 : ( $to_row < $from_row ? -1 : 0 );
        $col_step = $to_col > $from_col ? 1 : ( $to_col < $from_col ? -1 : 0 );

        $row = $from_row + $row_step;
        $col = $from_col + $col_step;

        while ( $row !== $to_row || $col !== $to_col ) {
            if ( $board[ $row ][ $col ] !== self::EMPTY ) {
                return false;
            }
            $row += $row_step;
            $col += $col_step;
        }

        return true;
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
        $captured = $board[ $to_row ][ $to_col ];

        // Track captured piece
        if ( $captured !== self::EMPTY ) {
            $state['captured'][ $player_seat ][] = $captured;
        }

        // Move the piece
        $board[ $from_row ][ $from_col ] = self::EMPTY;
        $board[ $to_row ][ $to_col ] = $piece;

        // Pawn promotion (auto-promote to queen for simplicity)
        $piece_type = abs( $piece );
        if ( $piece_type === 1 ) {
            $promotion_row = $player_seat === 0 ? 0 : 7;
            if ( $to_row === $promotion_row ) {
                $board[ $to_row ][ $to_col ] = $player_seat === 0 ? self::W_QUEEN : self::B_QUEEN;
            }
        }

        $state['board'] = $board;
        $state['move_count']++;
        $state['last_move'] = [
            'from'  => [ 'row' => $from_row, 'col' => $from_col ],
            'to'    => [ 'row' => $to_row, 'col' => $to_col ],
            'piece' => $piece,
        ];

        return $state;
    }

    /**
     * Advance to next turn
     */
    public function advance_turn( array $state ): array {
        $state['current_turn'] = $state['current_turn'] === 0 ? 1 : 0;
        return $state;
    }

    /**
     * Check end condition (king captured)
     */
    public function check_end_condition( array $state ): array {
        $board = $state['board'];

        // Check if either king is missing (captured)
        $white_king = false;
        $black_king = false;

        for ( $row = 0; $row < self::BOARD_SIZE; $row++ ) {
            for ( $col = 0; $col < self::BOARD_SIZE; $col++ ) {
                $piece = $board[ $row ][ $col ];
                if ( $piece === self::W_KING ) {
                    $white_king = true;
                } elseif ( $piece === self::B_KING ) {
                    $black_king = true;
                }
            }
        }

        if ( ! $white_king ) {
            return [
                'ended'   => true,
                'reason'  => 'king_captured',
                'winners' => [ 1 ], // Black wins
            ];
        }

        if ( ! $black_king ) {
            return [
                'ended'   => true,
                'reason'  => 'king_captured',
                'winners' => [ 0 ], // White wins
            ];
        }

        return [
            'ended'   => false,
            'reason'  => null,
            'winners' => null,
        ];
    }

    /**
     * Score round
     */
    public function score_round( array $state ): array {
        return $state;
    }

    /**
     * Get AI move (not supported in this version)
     */
    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        // AI not supported for chess in v0.5.0
        return [];
    }

    /**
     * Get all valid moves for a player
     */
    public function get_valid_moves( array $state, int $player_seat ): array {
        $board = $state['board'];
        $moves = [];

        for ( $from_row = 0; $from_row < self::BOARD_SIZE; $from_row++ ) {
            for ( $from_col = 0; $from_col < self::BOARD_SIZE; $from_col++ ) {
                $piece = $board[ $from_row ][ $from_col ];

                if ( ! $this->is_player_piece( $piece, $player_seat ) ) {
                    continue;
                }

                // Check all possible destinations
                for ( $to_row = 0; $to_row < self::BOARD_SIZE; $to_row++ ) {
                    for ( $to_col = 0; $to_col < self::BOARD_SIZE; $to_col++ ) {
                        if ( $from_row === $to_row && $from_col === $to_col ) {
                            continue;
                        }

                        $dest = $board[ $to_row ][ $to_col ];

                        // Skip if capturing own piece
                        if ( $dest !== self::EMPTY && $this->is_player_piece( $dest, $player_seat ) ) {
                            continue;
                        }

                        if ( $this->is_valid_piece_move( $board, $piece, $from_row, $from_col, $to_row, $to_col, $player_seat ) ) {
                            $moves[] = [
                                'from' => [ 'row' => $from_row, 'col' => $from_col ],
                                'to'   => [ 'row' => $to_row, 'col' => $to_col ],
                            ];
                        }
                    }
                }
            }
        }

        return $moves;
    }

    /**
     * Get public state
     */
    public function get_public_state( array $state, int $player_seat ): array {
        return $state;
    }

    /**
     * Helper: Check bounds
     */
    private function in_bounds( int $row, int $col ): bool {
        return $row >= 0 && $row < self::BOARD_SIZE && $col >= 0 && $col < self::BOARD_SIZE;
    }

    /**
     * Helper: Check if piece belongs to player
     */
    private function is_player_piece( int $piece, int $player_seat ): bool {
        if ( $piece === self::EMPTY ) {
            return false;
        }
        // White (seat 0) has positive pieces, Black (seat 1) has negative pieces
        return ( $player_seat === 0 && $piece > 0 ) || ( $player_seat === 1 && $piece < 0 );
    }
}
