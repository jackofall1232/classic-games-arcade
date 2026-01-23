<?php
/**
 * Overcut Dice Game Module
 *
 * A strategic 2-player dice game where players bid on their roll total.
 * Score points based on how your roll compares to your bid.
 *
 * Core Rules:
 * - Roll 6 dice (values 6-36 possible)
 * - Bid before rolling: choose a target between 6-36
 * - Scoring:
 *   - Roll > Bid: You score your bid, opponent scores (roll - bid)
 *   - Roll < Bid: You score 0, opponent scores your roll
 *   - Roll = Bid: You score bid x 2, opponent scores 0 (Exact Hit!)
 * - First to target score wins
 * - Null Roll: If both would win on same roll, no points awarded
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_Game_Overcut extends SACGA_Game_Contract {
    use SACGA_Turn_Gate_Trait;

    protected $id = 'overcut';
    protected $name = 'Overcut';
    protected $type = 'dice';
    protected $min_players = 2;
    protected $max_players = 2;
    protected $has_teams = false;
    protected $ai_supported = true;

    /**
     * Game constants
     */
    const DICE_COUNT = 6;
    const ROLLOFF_DICE = 3;
    const MIN_BID = 6;
    const MAX_BID = 36;

    /**
     * Game phases
     */
    const PHASE_ROLLOFF = 'rolloff';
    const PHASE_WAITING = 'waiting'; // Gate/pause phase - waiting for user action
    const PHASE_BIDDING = 'bidding';
    const PHASE_ROLLING = 'rolling';
    const PHASE_SCORING = 'scoring';
    const PHASE_GAME_OVER = 'game_over';

    /**
     * Target score variants
     */
    const VARIANTS = [
        'shootout_100'  => [ 'name' => 'Shootout', 'target' => 100 ],
        'casual_250'    => [ 'name' => 'Casual', 'target' => 250 ],
        'original_500'  => [ 'name' => 'Original', 'target' => 500 ],
        'marathon_1000' => [ 'name' => 'Marathon', 'target' => 1000 ],
    ];

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
            'description'  => __( 'A strategic dice game where you bid on your roll. Nail an exact hit for double points!', 'shortcode-arcade' ),
            'variants'     => self::VARIANTS,
            'rules'        => [
                'objective' => __( 'Be the first player to reach the target score by bidding wisely on your dice rolls.', 'shortcode-arcade' ),
                'setup'     => __( "2 players with 6 dice.\nRoll-off determines who goes first (highest 3-dice total).\nChoose difficulty: Shootout (100), Casual (250), Original (500), or Marathon (1000).", 'shortcode-arcade' ),
                'gameplay'  => __( "On your turn: Choose a bid between 6-36, then roll all 6 dice.\nOvercut (Roll > Bid): You score your bid, opponent scores the difference.\nUndercut (Roll < Bid): You score 0, opponent scores your roll.\nExact Hit (Roll = Bid): You score double your bid, opponent scores 0!", 'shortcode-arcade' ),
                'winning'   => __( 'First to the target score wins. If both would win on the same roll, no points are awarded (Null Roll).', 'shortcode-arcade' ),
            ],
        ];
    }

    /**
     * Initialize game state
     */
    public function init_state( array $players, array $settings = [] ): array {
        $variant = $settings['variant'] ?? 'original_500';
        $target_score = self::VARIANTS[ $variant ]['target'] ?? 500;

        return [
            'phase'          => self::PHASE_ROLLOFF,
            'current_turn'   => -1, // Will be set after rolloff
            'starting_player' => null, // Rolloff winner - used for round counting
            'players'        => $this->format_players( $players ),
            'scores'         => [ 0 => 0, 1 => 0 ],
            'target_score'   => $target_score,
            'variant'        => $variant,
            'round_number'   => 0,
            'rolloff'        => [
                'dice'    => [ 0 => [], 1 => [] ],
                'totals'  => [ 0 => 0, 1 => 0 ],
                'winner'  => null,
                'waiting' => [ 0 => true, 1 => true ],
            ],
            'current_bid'    => null,
            'current_roll'   => [],
            'last_result'    => null, // Stores result of last round for display
            'game_over'      => false,
            'winner'         => null,
            'move_history'   => [],
            // Gate control fields
            'game_started'   => false, // False until user clicks Begin Game after rolloff
            'awaiting_gate'  => null, // 'start_game', 'next_turn', or null
            'turn_step'      => 'rolloff_wait', // Backend truth for UI step
            'gate'           => null, // Gate data: { type, next_turn, next_round }
        ];
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
            ];
        }
        return $formatted;
    }

    /**
     * Deal or setup - perform initial rolloff
     */
    public function deal_or_setup( array $state ): array {
        // State is already initialized, rolloff will happen via moves
        return $state;
    }

    /**
     * Validate a move
     */
    public function validate_move( array $state, int $player_seat, array $move ) {
        $action = $move['action'] ?? '';

        // GATE WHITELIST: Gate actions are NEVER turn-restricted
        // These are session-level controls, not player turn actions
        if ( $this->is_gate_action( $move ) ) {
            // Validate gate action is appropriate for current awaiting_gate state
            if ( $action === 'begin_game' && $this->validate_gate_action( $state, 'begin_game', 'start_game' ) ) {
                return true;
            }
            if ( $action === 'continue' && $this->validate_gate_action( $state, 'continue', 'next_turn' ) ) {
                return true;
            }
            // Gate action at wrong time
            return new WP_Error( 'invalid_gate_action', __( 'This action is not available right now.', 'shortcode-arcade' ) );
        }

        // Global gate enforcement - if awaiting gate, only allow gate actions
        if ( $this->is_gate_open( $state ) ) {
            if ( $state['awaiting_gate'] === 'start_game' ) {
                return new WP_Error( 'awaiting_gate', __( 'Please click Begin Game to start.', 'shortcode-arcade' ) );
            }
            if ( $state['awaiting_gate'] === 'next_turn' ) {
                return new WP_Error( 'awaiting_gate', __( 'Please click Continue to proceed.', 'shortcode-arcade' ) );
            }
        }

        // Hard block: Prevent rolloff after winner is determined
        if ( $action === 'rolloff' && isset( $state['rolloff']['winner'] ) && $state['rolloff']['winner'] !== null ) {
            return new WP_Error( 'rolloff_complete', __( 'The rolloff has already been completed.', 'shortcode-arcade' ) );
        }

        // Phase-specific validation
        switch ( $state['phase'] ) {
            case self::PHASE_ROLLOFF:
                if ( $action !== 'rolloff' ) {
                    return new WP_Error( 'invalid_action', __( 'You must roll for starting position.', 'shortcode-arcade' ) );
                }
                if ( ! $state['rolloff']['waiting'][ $player_seat ] ) {
                    return new WP_Error( 'already_rolled', __( 'You have already rolled.', 'shortcode-arcade' ) );
                }
                return true;

            case self::PHASE_BIDDING:
                if ( $state['current_turn'] !== $player_seat ) {
                    return new WP_Error( 'not_your_turn', __( 'It is not your turn.', 'shortcode-arcade' ) );
                }
                if ( $action !== 'bid' ) {
                    return new WP_Error( 'invalid_action', __( 'You must place a bid.', 'shortcode-arcade' ) );
                }
                $bid = $move['bid'] ?? 0;
                if ( ! is_numeric( $bid ) || $bid < self::MIN_BID || $bid > self::MAX_BID ) {
                    return new WP_Error(
                        'invalid_bid',
                        sprintf(
                            /* translators: %1$d: minimum bid, %2$d: maximum bid */
                            __( 'Bid must be between %1$d and %2$d.', 'shortcode-arcade' ),
                            self::MIN_BID,
                            self::MAX_BID
                        )
                    );
                }
                return true;

            case self::PHASE_ROLLING:
                if ( $state['current_turn'] !== $player_seat ) {
                    return new WP_Error( 'not_your_turn', __( 'It is not your turn.', 'shortcode-arcade' ) );
                }
                if ( $action !== 'roll' ) {
                    return new WP_Error( 'invalid_action', __( 'You must roll the dice.', 'shortcode-arcade' ) );
                }
                return true;

            case self::PHASE_WAITING:
                // During waiting phase, only gate actions are allowed (handled above)
                return new WP_Error( 'waiting_phase', __( 'Please click the button to continue.', 'shortcode-arcade' ) );

            case self::PHASE_SCORING:
                // Auto-advance, no move needed
                return new WP_Error( 'scoring_phase', __( 'Please wait for scoring to complete.', 'shortcode-arcade' ) );

            case self::PHASE_GAME_OVER:
                return new WP_Error( 'game_over', __( 'The game has ended.', 'shortcode-arcade' ) );

            default:
                return new WP_Error( 'invalid_phase', __( 'Invalid game phase.', 'shortcode-arcade' ) );
        }
    }

    /**
     * Apply a validated move
     */
    public function apply_move( array $state, int $player_seat, array $move ): array {
        $action = $move['action'];

        // Handle gate actions
        if ( $action === 'begin_game' ) {
            return $this->apply_begin_game( $state );
        }

        if ( $action === 'continue' ) {
            return $this->apply_continue( $state );
        }

        switch ( $state['phase'] ) {
            case self::PHASE_ROLLOFF:
                return $this->apply_rolloff( $state, $player_seat );

            case self::PHASE_BIDDING:
                return $this->apply_bid( $state, $player_seat, (int) $move['bid'] );

            case self::PHASE_ROLLING:
                return $this->apply_roll( $state, $player_seat );

            default:
                return $state;
        }
    }

    /**
     * Apply rolloff move
     */
    private function apply_rolloff( array $state, int $player_seat ): array {
        // Roll dice for this player
        $dice = $this->roll_dice( self::ROLLOFF_DICE );
        $total = array_sum( $dice );

        $state['rolloff']['dice'][ $player_seat ] = $dice;
        $state['rolloff']['totals'][ $player_seat ] = $total;
        $state['rolloff']['waiting'][ $player_seat ] = false;

        // Check if both players have rolled
        if ( ! $state['rolloff']['waiting'][0] && ! $state['rolloff']['waiting'][1] ) {
            $total_0 = $state['rolloff']['totals'][0];
            $total_1 = $state['rolloff']['totals'][1];

            if ( $total_0 > $total_1 ) {
                $state['rolloff']['winner'] = 0;
                $state['starting_player'] = 0; // Track who starts for round counting
                $state['turn_step'] = 'gate_start_game';
                // Open gate - suspends turn ownership until Begin Game is pressed
                $this->open_gate( $state, 'start_game', [
                    'next_turn'  => 0,
                    'round'      => 1,
                    'next_round' => 1,
                ] );
                // round_number stays 0, game_started stays false
                // round_number will be set to 1 when begin_game is called
            } elseif ( $total_1 > $total_0 ) {
                $state['rolloff']['winner'] = 1;
                $state['starting_player'] = 1; // Track who starts for round counting
                $state['turn_step'] = 'gate_start_game';
                // Open gate - suspends turn ownership until Begin Game is pressed
                $this->open_gate( $state, 'start_game', [
                    'next_turn'  => 1,
                    'round'      => 1,
                    'next_round' => 1,
                ] );
                // round_number stays 0, game_started stays false
                // round_number will be set to 1 when begin_game is called
            } else {
                // Tie - reset for reroll
                $state['rolloff']['dice'] = [ 0 => [], 1 => [] ];
                $state['rolloff']['totals'] = [ 0 => 0, 1 => 0 ];
                $state['rolloff']['waiting'] = [ 0 => true, 1 => true ];
                $state['rolloff']['tie_count'] = ( $state['rolloff']['tie_count'] ?? 0 ) + 1;
            }
        }

        return $state;
    }

    /**
     * Apply begin_game gate action
     */
    private function apply_begin_game( array $state ): array {
        // Transition from rolloff to Round 1
        $state['game_started'] = true;
        $state['turn_step'] = 'bidding_wait';
        $state['phase'] = self::PHASE_BIDDING;
        $state['round_number'] = $this->get_gate_data(
            $state,
            'next_round',
            $this->get_gate_data( $state, 'round', 1 )
        );

        // RESTORE TURN OWNERSHIP - rolloff winner starts
        $state['current_turn'] = $this->get_gate_data( $state, 'next_turn', $state['starting_player'] );

        // Close the gate
        $this->close_gate( $state );

        return $state;
    }

    /**
     * Apply continue gate action (advance to next turn)
     */
    private function apply_continue( array $state ): array {
        // Check for winner first
        $end_check = $this->check_end_condition( $state );
        if ( $end_check['ended'] ) {
            $state['phase'] = self::PHASE_GAME_OVER;
            $state['game_over'] = true;
            $state['winner'] = $end_check['winners'][0] ?? null;
            $state['turn_step'] = 'game_over';
            $this->close_gate( $state );
            return $state;
        }

        // Restore turn from gate data
        $state['current_turn'] = $this->get_gate_data( $state, 'next_turn' );

        $next_round = $this->get_gate_data( $state, 'next_round', null );
        if ( $next_round !== null ) {
            $state['round_number'] = (int) $next_round;
        } elseif ( $state['current_turn'] === $state['starting_player'] ) {
            // Increment round ONLY when starting player gets control again
            // This is the ONLY place round_number changes (gate resolution, not precomputation)
            $state['round_number']++;
        }

        $state['current_bid'] = null;
        $state['current_roll'] = [];
        $state['phase'] = self::PHASE_BIDDING;
        $state['turn_step'] = 'bidding_wait';

        // Close the gate
        $this->close_gate( $state );

        return $state;
    }

    /**
     * Apply bid move
     */
    private function apply_bid( array $state, int $player_seat, int $bid ): array {
        $state['current_bid'] = $bid;
        $state['phase'] = self::PHASE_ROLLING;
        $state['turn_step'] = 'rolling_wait';

        // Record in history
        $state['move_history'][] = [
            'round'  => $state['round_number'],
            'player' => $player_seat,
            'action' => 'bid',
            'value'  => $bid,
        ];

        return $state;
    }

    /**
     * Apply roll move
     */
    private function apply_roll( array $state, int $player_seat ): array {
        $dice = $this->roll_dice( self::DICE_COUNT );
        $roll_total = array_sum( $dice );
        $bid = $state['current_bid'];

        $state['current_roll'] = $dice;

        // Calculate scores
        $player_score = 0;
        $opponent_score = 0;
        $result_type = '';

        if ( $roll_total > $bid ) {
            // Overcut: Player scores bid, opponent scores overflow
            $player_score = $bid;
            $opponent_score = $roll_total - $bid;
            $result_type = 'overcut';
        } elseif ( $roll_total < $bid ) {
            // Undercut: Player scores 0, opponent scores roll
            $player_score = 0;
            $opponent_score = $roll_total;
            $result_type = 'undercut';
        } else {
            // Exact hit: Player scores double, opponent scores 0
            $player_score = $bid * 2;
            $opponent_score = 0;
            $result_type = 'exact_hit';
        }

        $opponent_seat = $player_seat === 0 ? 1 : 0;

        // Store pending scores for null roll check
        $pending_scores = [
            $player_seat   => $state['scores'][ $player_seat ] + $player_score,
            $opponent_seat => $state['scores'][ $opponent_seat ] + $opponent_score,
        ];

        // Check for null roll (both would reach target)
        $target = $state['target_score'];
        $player_would_win = $pending_scores[ $player_seat ] >= $target;
        $opponent_would_win = $pending_scores[ $opponent_seat ] >= $target;

        $null_roll = false;
        if ( $player_would_win && $opponent_would_win ) {
            // Null roll - no points awarded
            $null_roll = true;
            $player_score = 0;
            $opponent_score = 0;
            $result_type = 'null_roll';
        }

        // Apply scores (unless null roll)
        if ( ! $null_roll ) {
            $state['scores'][ $player_seat ] += $player_score;
            $state['scores'][ $opponent_seat ] += $opponent_score;
        }

        // Store result for display
        $state['last_result'] = [
            'round'          => $state['round_number'],
            'player'         => $player_seat,
            'bid'            => $bid,
            'dice'           => $dice,
            'roll_total'     => $roll_total,
            'result_type'    => $result_type,
            'player_score'   => $player_score,
            'opponent_score' => $opponent_score,
        ];

        // Record in history
        $state['move_history'][] = [
            'round'       => $state['round_number'],
            'player'      => $player_seat,
            'action'      => 'roll',
            'dice'        => $dice,
            'total'       => $roll_total,
            'result_type' => $result_type,
            'scores'      => [ $player_score, $opponent_score ],
        ];

        // Precompute next turn ONLY (round increments on gate resolution, not before)
        $next_turn = $player_seat === 0 ? 1 : 0;
        $next_round = $next_turn === $state['starting_player']
            ? $state['round_number'] + 1
            : $state['round_number'];

        // Set gate for next turn - suspends turn ownership until Continue is pressed
        $state['phase'] = self::PHASE_SCORING;
        $state['turn_step'] = 'gate_next_turn';
        $this->open_gate( $state, 'next_turn', [
            'next_turn'  => $next_turn,
            'next_round' => $next_round,
        ] );

        return $state;
    }

    /**
     * Roll dice helper
     */
    private function roll_dice( int $count ): array {
        $dice = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $dice[] = wp_rand( 1, 6 );
        }
        return $dice;
    }

    /**
     * Advance to next turn
     *
     * Note: This is now handled through the gate system (apply_continue).
     * This method is kept for compatibility but should not auto-advance.
     */
    public function advance_turn( array $state ): array {
        // Turn advancement is now gated through the 'continue' action
        // which calls apply_continue(). This prevents auto-advancement
        // and ensures the UI can show proper gates between turns.
        return $state;
    }

    /**
     * Check end condition
     */
    public function check_end_condition( array $state ): array {
        $target = $state['target_score'];

        // Check if either player reached target
        $seat_0_won = $state['scores'][0] >= $target;
        $seat_1_won = $state['scores'][1] >= $target;

        if ( $seat_0_won && ! $seat_1_won ) {
            return [
                'ended'   => true,
                'reason'  => 'target_reached',
                'winners' => [ 0 ],
            ];
        }

        if ( $seat_1_won && ! $seat_0_won ) {
            return [
                'ended'   => true,
                'reason'  => 'target_reached',
                'winners' => [ 1 ],
            ];
        }

        // Both winning simultaneously handled by null roll rule
        // This shouldn't happen due to null roll, but handle edge case
        if ( $seat_0_won && $seat_1_won ) {
            // Whoever has higher score wins
            if ( $state['scores'][0] > $state['scores'][1] ) {
                return [ 'ended' => true, 'reason' => 'higher_score', 'winners' => [ 0 ] ];
            } elseif ( $state['scores'][1] > $state['scores'][0] ) {
                return [ 'ended' => true, 'reason' => 'higher_score', 'winners' => [ 1 ] ];
            }
            // True tie - current player wins (they reached first)
            return [ 'ended' => true, 'reason' => 'tie_breaker', 'winners' => [ $state['current_turn'] ] ];
        }

        return [
            'ended'   => false,
            'reason'  => null,
            'winners' => null,
        ];
    }

    /**
     * Score round (handled in apply_roll)
     */
    public function score_round( array $state ): array {
        return $state;
    }

    /**
     * Get AI move
     */
    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        // AI must respect gates - no action when a gate is open
        if ( $this->is_gate_open( $state ) ) {
            return [];
        }

        // AI must respect waiting phase - no action during gate pauses
        if ( $state['phase'] === self::PHASE_WAITING ) {
            return [];
        }

        // AI must respect null current_turn - no player owns turn during gates
        if ( $state['current_turn'] === null ) {
            return [];
        }

        // AI must not act before game has started (after rolloff, before Begin Game)
        if ( ! $state['game_started'] ) {
            // Allow rolloff only
            if ( $state['phase'] === self::PHASE_ROLLOFF ) {
                return [ 'action' => 'rolloff' ];
            }
            return [];
        }

        // AI may only act during active gameplay phases
        switch ( $state['phase'] ) {
            case self::PHASE_ROLLOFF:
                return [ 'action' => 'rolloff' ];

            case self::PHASE_BIDDING:
                return [ 'action' => 'bid', 'bid' => $this->ai_calculate_bid( $state, $player_seat, $difficulty ) ];

            case self::PHASE_ROLLING:
                return [ 'action' => 'roll' ];

            default:
                return [];
        }
    }

    /**
     * Calculate AI bid using probability-weighted selection
     *
     * Uses a bell-curve-like weighting centered around the expected roll value (21),
     * with the center shifting based on score differential to create risk adjustment.
     *
     * - When losing: center shifts up (riskier, aiming for higher rolls)
     * - When winning: center shifts down (safer, more conservative bids)
     * - Bids near center are most likely; extreme bids are rare but possible
     *
     * @param array  $state       Current game state.
     * @param int    $player_seat AI player's seat position.
     * @param string $difficulty  Difficulty level (reserved for future decay_factor tuning).
     * @return int Selected bid between MIN_BID and MAX_BID.
     */
    private function ai_calculate_bid( array $state, int $player_seat, string $difficulty ): int {
        $my_score  = $state['scores'][ $player_seat ];
        $opp_score = $state['scores'][ $player_seat === 0 ? 1 : 0 ];

        // Calculate dynamic center based on score differential
        $score_delta = $my_score - $opp_score;
        $center      = $this->ai_calculate_bid_center( $score_delta );

        // Generate weights for all valid bids
        $weights = $this->ai_generate_bid_weights( $center );

        // Select bid using weighted random choice
        return $this->ai_weighted_random_bid( $weights );
    }

    /**
     * Calculate the dynamic bid center based on score differential
     *
     * The baseline center is 21 (expected value of 6d6). When the AI is losing,
     * the center shifts upward to favor riskier bids. When winning, it shifts
     * downward for more conservative play.
     *
     * Shift rules:
     * - Threshold: ±25 points before adjustment begins
     * - Step size: 0.5 per 25-point threshold unit
     * - Max shift: 3 points (center range: 18 to 24)
     *
     * Examples:
     * - Losing by 25: center = 21.5
     * - Losing by 50: center = 22.0
     * - Winning by 25: center = 20.5
     * - Winning by 50: center = 20.0
     *
     * @param int $score_delta AI score minus opponent score.
     * @return float Dynamic center for bid weighting.
     */
    private function ai_calculate_bid_center( int $score_delta ): float {
        $baseline_center = 21.0;
        $threshold       = 25;    // Score difference before adjustment kicks in
        $step_size       = 0.5;   // Center shift per threshold unit
        $max_shift       = 3.0;   // Maximum center displacement

        if ( abs( $score_delta ) < $threshold ) {
            return $baseline_center;
        }

        // Calculate shift magnitude: 0.5 per 25 points of deficit/lead
        $steps_beyond = floor( abs( $score_delta ) / $threshold );
        $shift        = min( $steps_beyond * $step_size, $max_shift );

        // Losing (negative delta): shift center up (riskier bids)
        // Winning (positive delta): shift center down (safer bids)
        if ( $score_delta < 0 ) {
            return $baseline_center + $shift;
        } else {
            return $baseline_center - $shift;
        }
    }

    /**
     * Generate bid weights based on distance from center
     *
     * Creates a symmetric, monotonically decreasing weight distribution
     * where bids closer to center have higher probability of selection.
     *
     * Formula: weight = max(1, base_weight - (distance * decay_factor))
     *
     * With base_weight=100 and decay_factor=5:
     * - Distance 0 (at center): weight = 100
     * - Distance 5: weight = 75
     * - Distance 10: weight = 50
     * - Distance 15+: weight approaches minimum of 1
     *
     * @param float $center The dynamic center for weighting.
     * @return array Associative array of bid => weight.
     */
    private function ai_generate_bid_weights( float $center ): array {
        $weights      = [];
        $base_weight  = 100;
        $decay_factor = 5;  // Weight reduction per unit distance from center

        for ( $bid = self::MIN_BID; $bid <= self::MAX_BID; $bid++ ) {
            $distance        = abs( $bid - $center );
            $weight          = max( 1, $base_weight - ( $distance * $decay_factor ) );
            $weights[ $bid ] = (int) $weight;
        }

        return $weights;
    }

    /**
     * Select a bid using weighted random choice
     *
     * Performs a weighted random selection where each bid's probability
     * is proportional to its weight relative to the total.
     *
     * @param array $weights Associative array of bid => weight.
     * @return int Selected bid.
     */
    private function ai_weighted_random_bid( array $weights ): int {
        $total_weight = array_sum( $weights );
        $random_value = wp_rand( 1, $total_weight );

        $cumulative = 0;
        foreach ( $weights as $bid => $weight ) {
            $cumulative += $weight;
            if ( $random_value <= $cumulative ) {
                return $bid;
            }
        }

        // Fallback to expected value (should never reach here)
        return 21;
    }

    /**
     * Get valid moves for a player
     */
    public function get_valid_moves( array $state, int $player_seat ): array {
        // Handle gate actions - both players can trigger gates
        if ( $state['awaiting_gate'] === 'start_game' ) {
            return [ [ 'action' => 'begin_game' ] ];
        }

        if ( $state['awaiting_gate'] === 'next_turn' ) {
            return [ [ 'action' => 'continue' ] ];
        }

        switch ( $state['phase'] ) {
            case self::PHASE_ROLLOFF:
                if ( $state['rolloff']['waiting'][ $player_seat ] ) {
                    return [ [ 'action' => 'rolloff' ] ];
                }
                return [];

            case self::PHASE_BIDDING:
                if ( $state['current_turn'] !== $player_seat ) {
                    return [];
                }
                $moves = [];
                for ( $bid = self::MIN_BID; $bid <= self::MAX_BID; $bid++ ) {
                    $moves[] = [ 'action' => 'bid', 'bid' => $bid ];
                }
                return $moves;

            case self::PHASE_ROLLING:
                if ( $state['current_turn'] !== $player_seat ) {
                    return [];
                }
                return [ [ 'action' => 'roll' ] ];

            default:
                return [];
        }
    }

    /**
     * Get public state (dice games don't hide info)
     */
    public function get_public_state( array $state, int $player_seat ): array {
        return $state;
    }
}
