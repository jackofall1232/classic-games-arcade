<?php
/**
 * Backgammon Game Module
 *
 * Standard backgammon for 2 players with dice rolling.
 * Points numbered 1-24, with player 1 moving from 24 toward 1 (bearing off at 0)
 * and player 2 moving from 1 toward 24 (bearing off at 25).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_Game_Backgammon extends SACGA_Game_Contract {

    protected $id = 'backgammon';
    protected $name = 'Backgammon';
    protected $type = 'board';
    protected $min_players = 2;
    protected $max_players = 2;
    protected $has_teams = false;
    protected $ai_supported = true;

    /**
     * Constants
     */
    const CHECKERS_PER_PLAYER = 15;
    const BAR = 'bar';
    const OFF = 'off';

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
            'description'  => __( 'Classic backgammon. Roll dice and race your checkers home.', 'shortcode-arcade' ),
            'rules'        => [
                'objective' => __( 'Move all 15 of your checkers into your home board and then bear them off before your opponent.', 'shortcode-arcade' ),
                'setup'     => __( "2 players with 15 checkers each on a board of 24 triangular points.\nCheckers start in a standard arrangement across both sides of the board.", 'shortcode-arcade' ),
                'gameplay'  => __( "Roll two dice and move checkers forward by the numbers shown.\nYou may move one checker for each die, or one checker using both dice.\nLand on a point with one opponent checker (a \"blot\") to send it to the bar.\nCheckers on the bar must re-enter before other moves.\nDoubles allow four moves instead of two.", 'shortcode-arcade' ),
                'winning'   => __( 'Once all your checkers are in your home board, bear them off by rolling their point numbers. First to bear off all checkers wins.', 'shortcode-arcade' ),
                'notes'     => __( 'You cannot land on a point occupied by 2+ opponent checkers.', 'shortcode-arcade' ),
            ],
        ];
    }

    /**
     * Initialize game state
     */
    public function init_state( array $players, array $settings = [] ): array {
        return [
            'board'         => [],
            'bar'           => [ 0 => 0, 1 => 0 ],
            'borne_off'     => [ 0 => 0, 1 => 0 ],
            'current_turn'  => 0,
            'dice'          => [],
            'dice_remaining'=> [],
            'players'       => $this->format_players( $players ),
            'phase'         => 'roll', // roll, move, game_over
            'must_use_all'  => true,
            'game_over'     => false,
            'winner'        => null,
            'last_move'     => null,
            'move_count'    => 0,
            'doubling_cube' => 1,
        ];
    }

    /**
     * Set up the board - standard backgammon starting position
     * Points 1-24, player 0 moves toward point 1 (bears off at 0)
     * Player 1 moves toward point 24 (bears off at 25)
     */
    public function deal_or_setup( array $state ): array {
        // Initialize empty board (points 1-24)
        $board = [];
        for ( $i = 1; $i <= 24; $i++ ) {
            $board[ $i ] = [ 'player' => null, 'count' => 0 ];
        }

        // Standard starting positions
        // Player 0 (moving toward 1, bears off at 0)
        $board[24] = [ 'player' => 0, 'count' => 2 ];
        $board[13] = [ 'player' => 0, 'count' => 5 ];
        $board[8]  = [ 'player' => 0, 'count' => 3 ];
        $board[6]  = [ 'player' => 0, 'count' => 5 ];

        // Player 1 (moving toward 24, bears off at 25)
        $board[1]  = [ 'player' => 1, 'count' => 2 ];
        $board[12] = [ 'player' => 1, 'count' => 5 ];
        $board[17] = [ 'player' => 1, 'count' => 3 ];
        $board[19] = [ 'player' => 1, 'count' => 5 ];

        $state['board'] = $board;
        $state['phase'] = 'roll';

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
        if ( $state['current_turn'] !== $player_seat ) {
            return new WP_Error( 'not_your_turn', __( 'It is not your turn.', 'shortcode-arcade' ) );
        }

        if ( ! empty( $state['game_over'] ) ) {
            return new WP_Error( 'game_over', __( 'The game is over.', 'shortcode-arcade' ) );
        }

        $action = $move['action'] ?? '';

        // Roll dice action
        if ( $action === 'roll' ) {
            if ( $state['phase'] !== 'roll' ) {
                return new WP_Error( 'invalid_phase', __( 'You cannot roll now.', 'shortcode-arcade' ) );
            }
            return true;
        }

        // Move checker action
        if ( $action === 'move' ) {
            if ( $state['phase'] !== 'move' ) {
                return new WP_Error( 'invalid_phase', __( 'You must roll first.', 'shortcode-arcade' ) );
            }

            $from = $move['from'] ?? null;
            $to = $move['to'] ?? null;
            $die_value = $move['die_value'] ?? null;

            if ( $from === null || $to === null || $die_value === null ) {
                return new WP_Error( 'invalid_move', __( 'Invalid move data.', 'shortcode-arcade' ) );
            }

            // Check die is available
            if ( ! in_array( (int) $die_value, $state['dice_remaining'], true ) ) {
                return new WP_Error( 'invalid_die', __( 'That die value is not available.', 'shortcode-arcade' ) );
            }

            return $this->validate_checker_move( $state, $player_seat, $from, $to, $die_value );
        }

        // End turn (when no moves possible or all dice used)
        if ( $action === 'end_turn' ) {
            if ( $state['phase'] !== 'move' ) {
                return new WP_Error( 'invalid_phase', __( 'Cannot end turn now.', 'shortcode-arcade' ) );
            }

            // Check if there are valid moves remaining
            $valid_moves = $this->get_all_valid_moves( $state, $player_seat );
            if ( ! empty( $valid_moves ) ) {
                return new WP_Error( 'moves_available', __( 'You must use your remaining dice if possible.', 'shortcode-arcade' ) );
            }

            return true;
        }

        return new WP_Error( 'invalid_action', __( 'Invalid action.', 'shortcode-arcade' ) );
    }

    /**
     * Validate a specific checker move
     */
    private function validate_checker_move( array $state, int $player_seat, $from, $to, int $die_value ) {
        $board = $state['board'];
        $bar = $state['bar'];

        // Direction of movement
        $direction = $player_seat === 0 ? -1 : 1;
        $home_start = $player_seat === 0 ? 1 : 19;
        $home_end = $player_seat === 0 ? 6 : 24;
        $bear_off_point = $player_seat === 0 ? 0 : 25;

        // Must enter from bar first
        if ( $bar[ $player_seat ] > 0 && $from !== 'bar' ) {
            return new WP_Error( 'must_enter', __( 'You must enter from the bar first.', 'shortcode-arcade' ) );
        }

        // Moving from bar
        if ( $from === 'bar' ) {
            if ( $bar[ $player_seat ] <= 0 ) {
                return new WP_Error( 'no_bar', __( 'You have no checkers on the bar.', 'shortcode-arcade' ) );
            }

            // Entry point calculation
            $entry_point = $player_seat === 0 ? ( 25 - $die_value ) : $die_value;

            if ( (int) $to !== $entry_point ) {
                return new WP_Error( 'invalid_entry', __( 'Invalid entry point for this die.', 'shortcode-arcade' ) );
            }

            // Check destination
            return $this->can_land_on( $board, $to, $player_seat );
        }

        // Moving from a point
        $from = (int) $from;
        if ( $from < 1 || $from > 24 ) {
            return new WP_Error( 'invalid_from', __( 'Invalid starting point.', 'shortcode-arcade' ) );
        }

        // Check we have a checker there
        if ( $board[ $from ]['player'] !== $player_seat || $board[ $from ]['count'] <= 0 ) {
            return new WP_Error( 'no_checker', __( 'You have no checker there.', 'shortcode-arcade' ) );
        }

        // Calculate expected destination
        $expected_to = $from + ( $direction * $die_value );

        // Bearing off
        if ( $to === 'off' || $to === 0 || $to === 25 ) {
            if ( ! $this->can_bear_off( $state, $player_seat ) ) {
                return new WP_Error( 'cannot_bear_off', __( 'You cannot bear off yet.', 'shortcode-arcade' ) );
            }

            // Check if exact or valid overshoot
            if ( $player_seat === 0 ) {
                // Moving toward 0
                if ( $expected_to > 0 ) {
                    return new WP_Error( 'not_bearing_off', __( 'This move does not bear off.', 'shortcode-arcade' ) );
                }
                // If overshooting, must be highest point
                if ( $expected_to < 0 && ! $this->is_highest_checker( $state, $player_seat, $from ) ) {
                    return new WP_Error( 'not_highest', __( 'Must bear off highest checker when overshooting.', 'shortcode-arcade' ) );
                }
            } else {
                // Moving toward 25
                if ( $expected_to < 25 ) {
                    return new WP_Error( 'not_bearing_off', __( 'This move does not bear off.', 'shortcode-arcade' ) );
                }
                // If overshooting, must be highest point
                if ( $expected_to > 25 && ! $this->is_highest_checker( $state, $player_seat, $from ) ) {
                    return new WP_Error( 'not_highest', __( 'Must bear off highest checker when overshooting.', 'shortcode-arcade' ) );
                }
            }

            return true;
        }

        // Normal move to a point
        $to = (int) $to;
        if ( $to < 1 || $to > 24 ) {
            return new WP_Error( 'invalid_to', __( 'Invalid destination point.', 'shortcode-arcade' ) );
        }

        if ( $to !== $expected_to ) {
            return new WP_Error( 'invalid_distance', __( 'Move does not match die value.', 'shortcode-arcade' ) );
        }

        return $this->can_land_on( $board, $to, $player_seat );
    }

    /**
     * Check if a player can land on a point
     */
    private function can_land_on( array $board, int $point, int $player_seat ) {
        $dest = $board[ $point ];

        // Empty or own checkers
        if ( $dest['player'] === null || $dest['player'] === $player_seat ) {
            return true;
        }

        // Opponent's blot (single checker) - can hit
        if ( $dest['count'] === 1 ) {
            return true;
        }

        // Opponent has 2+ checkers - blocked
        return new WP_Error( 'blocked', __( 'That point is blocked.', 'shortcode-arcade' ) );
    }

    /**
     * Check if player can bear off (all checkers in home board)
     */
    private function can_bear_off( array $state, int $player_seat ): bool {
        $board = $state['board'];
        $bar = $state['bar'];

        // Cannot bear off if on bar
        if ( $bar[ $player_seat ] > 0 ) {
            return false;
        }

        // Home board points
        $home_start = $player_seat === 0 ? 1 : 19;
        $home_end = $player_seat === 0 ? 6 : 24;

        // Check if any checkers outside home
        for ( $i = 1; $i <= 24; $i++ ) {
            if ( $board[ $i ]['player'] === $player_seat && $board[ $i ]['count'] > 0 ) {
                if ( $player_seat === 0 && $i > 6 ) {
                    return false;
                }
                if ( $player_seat === 1 && $i < 19 ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if this is the highest/furthest checker (for bearing off with overshoot)
     */
    private function is_highest_checker( array $state, int $player_seat, int $point ): bool {
        $board = $state['board'];

        if ( $player_seat === 0 ) {
            // Check for any checker on higher point (6 down to point+1)
            for ( $i = 6; $i > $point; $i-- ) {
                if ( $board[ $i ]['player'] === $player_seat && $board[ $i ]['count'] > 0 ) {
                    return false;
                }
            }
        } else {
            // Check for any checker on lower point (19 up to point-1)
            for ( $i = 19; $i < $point; $i++ ) {
                if ( $board[ $i ]['player'] === $player_seat && $board[ $i ]['count'] > 0 ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Apply a move
     */
    public function apply_move( array $state, int $player_seat, array $move ): array {
        $action = $move['action'];

        if ( $action === 'roll' ) {
            $die1 = wp_rand( 1, 6 );
            $die2 = wp_rand( 1, 6 );
            $state['dice'] = [ $die1, $die2 ];

            // Doubles get 4 moves
            if ( $die1 === $die2 ) {
                $state['dice_remaining'] = [ $die1, $die1, $die1, $die1 ];
            } else {
                $state['dice_remaining'] = [ $die1, $die2 ];
            }

            $state['phase'] = 'move';

            // Check if player can make any moves
            $valid_moves = $this->get_all_valid_moves( $state, $player_seat );
            if ( empty( $valid_moves ) ) {
                // No valid moves, turn ends automatically
                $state['dice_remaining'] = [];
            }

            return $state;
        }

        if ( $action === 'move' ) {
            $from = $move['from'];
            $to = $move['to'];
            $die_value = (int) $move['die_value'];

            $board = $state['board'];

            // Remove from source
            if ( $from === 'bar' ) {
                $state['bar'][ $player_seat ]--;
            } else {
                $from = (int) $from;
                $board[ $from ]['count']--;
                if ( $board[ $from ]['count'] === 0 ) {
                    $board[ $from ]['player'] = null;
                }
            }

            // Add to destination
            if ( $to === 'off' || $to === 0 || $to === 25 ) {
                $state['borne_off'][ $player_seat ]++;
            } else {
                $to = (int) $to;

                // Check for hit
                if ( $board[ $to ]['player'] !== null && $board[ $to ]['player'] !== $player_seat ) {
                    // Hit! Send opponent to bar
                    $opponent = $player_seat === 0 ? 1 : 0;
                    $state['bar'][ $opponent ]++;
                    $board[ $to ]['count'] = 0;
                }

                $board[ $to ]['player'] = $player_seat;
                $board[ $to ]['count']++;
            }

            $state['board'] = $board;

            // Remove used die
            $die_index = array_search( $die_value, $state['dice_remaining'], true );
            if ( $die_index !== false ) {
                array_splice( $state['dice_remaining'], $die_index, 1 );
            }

            $state['last_move'] = $move;
            $state['move_count']++;

            // Check if more moves available
            if ( empty( $state['dice_remaining'] ) ) {
                // All dice used
            } else {
                // Check for valid moves with remaining dice
                $valid_moves = $this->get_all_valid_moves( $state, $player_seat );
                if ( empty( $valid_moves ) ) {
                    $state['dice_remaining'] = [];
                }
            }

            return $state;
        }

        if ( $action === 'end_turn' ) {
            $state['dice_remaining'] = [];
            return $state;
        }

        return $state;
    }

    /**
     * Advance to next turn
     */
    public function advance_turn( array $state ): array {
        if ( ! empty( $state['game_over'] ) ) {
            return $state;
        }

        // Only advance if dice are used up
        if ( ! empty( $state['dice_remaining'] ) && $state['phase'] === 'move' ) {
            return $state;
        }

        $state['current_turn'] = $state['current_turn'] === 0 ? 1 : 0;
        $state['dice'] = [];
        $state['dice_remaining'] = [];
        $state['phase'] = 'roll';

        return $state;
    }

    /**
     * Check end condition
     */
    public function check_end_condition( array $state ): array {
        // Check if either player has borne off all checkers
        foreach ( [ 0, 1 ] as $seat ) {
            if ( $state['borne_off'][ $seat ] >= self::CHECKERS_PER_PLAYER ) {
                return [
                    'ended'   => true,
                    'reason'  => 'borne_off_all',
                    'winners' => [ $seat ],
                ];
            }
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
     * Get all valid moves for a player
     */
    public function get_valid_moves( array $state, int $player_seat ): array {
        if ( $state['phase'] === 'roll' ) {
            return [ [ 'action' => 'roll' ] ];
        }

        if ( $state['phase'] !== 'move' ) {
            return [];
        }

        return $this->get_all_valid_moves( $state, $player_seat );
    }

    /**
     * Get all valid checker moves
     */
    private function get_all_valid_moves( array $state, int $player_seat ): array {
        $moves = [];
        $board = $state['board'];
        $bar = $state['bar'];
        $dice_remaining = $state['dice_remaining'];

        if ( empty( $dice_remaining ) ) {
            return [];
        }

        // Get unique dice values
        $unique_dice = array_unique( $dice_remaining );

        $direction = $player_seat === 0 ? -1 : 1;

        // Must enter from bar first
        if ( $bar[ $player_seat ] > 0 ) {
            foreach ( $unique_dice as $die ) {
                $entry_point = $player_seat === 0 ? ( 25 - $die ) : $die;

                $can_land = $this->can_land_on( $board, $entry_point, $player_seat );
                if ( $can_land === true ) {
                    $moves[] = [
                        'action'    => 'move',
                        'from'      => 'bar',
                        'to'        => $entry_point,
                        'die_value' => $die,
                    ];
                }
            }
            return $moves;
        }

        // Check bearing off
        $can_bear = $this->can_bear_off( $state, $player_seat );

        // Check each point with player's checkers
        for ( $point = 1; $point <= 24; $point++ ) {
            if ( $board[ $point ]['player'] !== $player_seat || $board[ $point ]['count'] <= 0 ) {
                continue;
            }

            foreach ( $unique_dice as $die ) {
                $dest = $point + ( $direction * $die );

                // Normal move
                if ( $dest >= 1 && $dest <= 24 ) {
                    $can_land = $this->can_land_on( $board, $dest, $player_seat );
                    if ( $can_land === true ) {
                        $moves[] = [
                            'action'    => 'move',
                            'from'      => $point,
                            'to'        => $dest,
                            'die_value' => $die,
                        ];
                    }
                }

                // Bear off
                if ( $can_bear ) {
                    $bear_off_target = $player_seat === 0 ? 0 : 25;

                    if ( $player_seat === 0 && $dest <= 0 ) {
                        // Exact or overshoot for player 0
                        if ( $dest === 0 || $this->is_highest_checker( $state, $player_seat, $point ) ) {
                            $moves[] = [
                                'action'    => 'move',
                                'from'      => $point,
                                'to'        => 'off',
                                'die_value' => $die,
                            ];
                        }
                    } elseif ( $player_seat === 1 && $dest >= 25 ) {
                        // Exact or overshoot for player 1
                        if ( $dest === 25 || $this->is_highest_checker( $state, $player_seat, $point ) ) {
                            $moves[] = [
                                'action'    => 'move',
                                'from'      => $point,
                                'to'        => 'off',
                                'die_value' => $die,
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
     * Get AI move
     */
    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        if ( $state['current_turn'] !== $player_seat ) {
            return [];
        }

        if ( ! empty( $state['game_over'] ) ) {
            return [];
        }

        // Roll if needed
        if ( $state['phase'] === 'roll' ) {
            return [ 'action' => 'roll' ];
        }

        // Get valid moves
        $valid_moves = $this->get_all_valid_moves( $state, $player_seat );

        if ( empty( $valid_moves ) ) {
            return [ 'action' => 'end_turn' ];
        }

        // AI Strategy based on difficulty
        if ( $difficulty === 'expert' ) {
            return $this->get_best_move( $state, $player_seat, $valid_moves );
        }

        if ( $difficulty === 'intermediate' ) {
            // 50% chance of smart move
            if ( mt_rand( 1, 100 ) <= 50 ) {
                return $this->get_best_move( $state, $player_seat, $valid_moves );
            }
        }

        // Beginner: Random move with slight preference for hitting and bearing off
        $hits = array_filter( $valid_moves, function( $m ) use ( $state, $player_seat ) {
            if ( $m['to'] === 'off' ) {
                return false;
            }
            $to = (int) $m['to'];
            $board = $state['board'];
            return $board[ $to ]['player'] !== null &&
                   $board[ $to ]['player'] !== $player_seat &&
                   $board[ $to ]['count'] === 1;
        });

        $bear_offs = array_filter( $valid_moves, fn( $m ) => $m['to'] === 'off' );

        // Prioritize bearing off
        if ( ! empty( $bear_offs ) && mt_rand( 0, 2 ) > 0 ) {
            return $bear_offs[ array_rand( $bear_offs ) ];
        }

        // Then hitting
        if ( ! empty( $hits ) && mt_rand( 0, 2 ) > 0 ) {
            return $hits[ array_rand( $hits ) ];
        }

        return $valid_moves[ array_rand( $valid_moves ) ];
    }

    /**
     * Get best move using simple heuristics
     */
    private function get_best_move( array $state, int $player_seat, array $valid_moves ): array {
        $best_move = $valid_moves[0];
        $best_score = PHP_INT_MIN;
        $board = $state['board'];

        foreach ( $valid_moves as $move ) {
            $score = 0;

            // Bearing off is great
            if ( $move['to'] === 'off' ) {
                $score += 100;
            }

            // Hitting opponent is good
            if ( $move['to'] !== 'off' && $move['from'] !== 'bar' ) {
                $to = (int) $move['to'];
                if ( $board[ $to ]['player'] !== null &&
                     $board[ $to ]['player'] !== $player_seat &&
                     $board[ $to ]['count'] === 1 ) {
                    $score += 50;
                }
            }

            // Getting off bar is important
            if ( $move['from'] === 'bar' ) {
                $score += 30;
            }

            // Making points (landing where we have 1) is good
            if ( $move['to'] !== 'off' && $move['from'] !== 'bar' ) {
                $to = (int) $move['to'];
                if ( $board[ $to ]['player'] === $player_seat && $board[ $to ]['count'] === 1 ) {
                    $score += 20;
                }
            }

            // Moving forward toward home is good
            if ( $move['to'] !== 'off' && $move['from'] !== 'bar' ) {
                $from = (int) $move['from'];
                $to = (int) $move['to'];

                if ( $player_seat === 0 ) {
                    // Lower is better
                    $score += ( 25 - $to );
                } else {
                    // Higher is better
                    $score += $to;
                }
            }

            // Avoid leaving blots (single checkers) if possible
            if ( $move['from'] !== 'bar' ) {
                $from = (int) $move['from'];
                if ( $board[ $from ]['count'] === 2 ) {
                    // This would leave a blot
                    $score -= 15;
                }
            }

            if ( $score > $best_score ) {
                $best_score = $score;
                $best_move = $move;
            }
        }

        return $best_move;
    }
}
