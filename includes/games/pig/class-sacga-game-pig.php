<?php
/**
 * Pig Dice Game Module
 *
 * A push-your-luck dice game for 2+ players.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_Game_Pig extends SACGA_Game_Contract {

    protected $id = 'pig';
    protected $name = 'Pig';
    protected $type = 'dice';
    protected $min_players = 2;
    protected $max_players = 6;
    protected $has_teams = false;
    protected $ai_supported = true;

    const DEFAULT_TARGET_SCORE = 100;

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
            'description'  => __( 'A push-your-luck dice game. Roll to build your round total, or hold to bank points.', 'shortcode-arcade' ),
            'rules'        => [
                'objective' => __( 'Be the first player to reach the target score (default 100 points).', 'shortcode-arcade' ),
                'setup'     => __( "2-6 players with one standard die.\nPlayers take turns in order.", 'shortcode-arcade' ),
                'gameplay'  => __( "On your turn, roll the die repeatedly to accumulate points.\nRoll 2-6: Add that number to your turn total. Choose to Roll again or Hold.\nRoll 1: Lose all points accumulated this turn. Turn ends.\nHold: Bank your turn total to your score. Turn ends.", 'shortcode-arcade' ),
                'winning'   => __( 'First player to reach or exceed the target score wins.', 'shortcode-arcade' ),
                'notes'     => __( 'The key is knowing when to hold—greed can cost you!', 'shortcode-arcade' ),
            ],
        ];
    }

    /**
     * Initialize game state
     */
    public function init_state( array $players, array $settings = [] ): array {
        $target_score = (int) ( $settings['target_score'] ?? self::DEFAULT_TARGET_SCORE );
        if ( $target_score <= 0 ) {
            $target_score = self::DEFAULT_TARGET_SCORE;
        }

        return [
            'phase'         => 'playing',
            'current_turn'  => 0,
            'round_total'   => 0,
            'scores'        => $this->init_scores( $players ),
            'players'       => $this->format_players( $players ),
            'target_score'  => $target_score,
            'last_roll'     => null,
            'last_action'   => null,
            'last_player'   => null,
            'turn_complete' => false,
            'game_over'     => false,
            'move_history'  => [],
        ];
    }

    /**
     * Deal or setup
     */
    public function deal_or_setup( array $state ): array {
        return $state;
    }

    /**
     * Validate a move
     */
    public function validate_move( array $state, int $player_seat, array $move ) {
        if ( ! empty( $state['game_over'] ) ) {
            return new WP_Error( 'game_over', __( 'The game is already over.', 'shortcode-arcade' ) );
        }

        $action = $move['action'] ?? '';
        if ( ! in_array( $action, [ 'roll', 'hold' ], true ) ) {
            return new WP_Error( 'invalid_action', __( 'Invalid action.', 'shortcode-arcade' ) );
        }

        if ( $action === 'hold' && (int) ( $state['round_total'] ?? 0 ) <= 0 ) {
            return new WP_Error( 'invalid_hold', __( 'You must roll at least once before holding.', 'shortcode-arcade' ) );
        }

        if ( $state['current_turn'] !== $player_seat ) {
            return new WP_Error( 'not_your_turn', __( 'It is not your turn.', 'shortcode-arcade' ) );
        }

        return true;
    }

    /**
     * Apply a validated move
     */
    public function apply_move( array $state, int $player_seat, array $move ): array {
        $action = $move['action'] ?? '';

        if ( $action === 'roll' ) {
            $roll = wp_rand( 1, 6 );
            $state['last_roll'] = $roll;
            $state['last_action'] = 'roll';
            $state['last_player'] = $player_seat;

            if ( $roll === 1 ) {
                $state['round_total'] = 0;
                $state['turn_complete'] = true;
            } else {
                $state['round_total'] += $roll;
                $state['turn_complete'] = false;
            }

            $state['move_history'][] = [
                'player' => $player_seat,
                'action' => 'roll',
                'roll'   => $roll,
            ];

            return $state;
        }

        if ( $action === 'hold' ) {
            $state['scores'][ $player_seat ] += $state['round_total'];
            $state['round_total'] = 0;
            $state['last_roll'] = null;
            $state['last_action'] = 'hold';
            $state['last_player'] = $player_seat;
            $state['turn_complete'] = true;

            $state['move_history'][] = [
                'player' => $player_seat,
                'action' => 'hold',
            ];
        }

        return $state;
    }

    /**
     * Advance to next turn
     */
    public function advance_turn( array $state ): array {
        if ( ! empty( $state['game_over'] ) || ! empty( $state['winners'] ) ) {
            return $state;
        }

        if ( empty( $state['turn_complete'] ) ) {
            return $state;
        }

        $state['current_turn'] = $this->get_next_seat( $state, $state['current_turn'] );
        $state['turn_complete'] = false;

        return $state;
    }

    /**
     * Check end condition
     */
    public function check_end_condition( array $state ): array {
        $target = $state['target_score'];
        $winners = [];
        $highest = 0;

        foreach ( $state['scores'] as $seat => $score ) {
            if ( $score >= $target ) {
                if ( $score > $highest ) {
                    $highest = $score;
                    $winners = [ (int) $seat ];
                } elseif ( $score === $highest ) {
                    $winners[] = (int) $seat;
                }
            }
        }

        if ( ! empty( $winners ) ) {
            return [
                'ended'   => true,
                'reason'  => 'target_reached',
                'winners' => $winners,
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
     * Get AI move
     */
    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        if ( ! empty( $state['game_over'] ) ) {
            return [];
        }

        if ( $state['current_turn'] !== $player_seat ) {
            return [];
        }

        $round_total = $state['round_total'];
        $score = $state['scores'][ $player_seat ] ?? 0;
        $target = $state['target_score'];

        if ( $score + $round_total >= $target || $round_total >= 20 ) {
            return [ 'action' => 'hold' ];
        }

        return [ 'action' => 'roll' ];
    }

    /**
     * Get valid moves for a player
     */
    public function get_valid_moves( array $state, int $player_seat ): array {
        if ( ! empty( $state['game_over'] ) ) {
            return [];
        }

        if ( $state['current_turn'] !== $player_seat ) {
            return [];
        }

        return [
            [ 'action' => 'roll' ],
            [ 'action' => 'hold' ],
        ];
    }

    /**
     * Get public state
     */
    public function get_public_state( array $state, int $player_seat ): array {
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
            ];
        }
        return $formatted;
    }

    /**
     * Initialize scores
     */
    private function init_scores( array $players ): array {
        $scores = [];
        foreach ( $players as $player ) {
            $scores[ (int) $player['seat_position'] ] = 0;
        }
        return $scores;
    }

    /**
     * Get next seat position
     */
    private function get_next_seat( array $state, int $current ): int {
        $seats = array_keys( $state['players'] );
        sort( $seats, SORT_NUMERIC );

        $index = array_search( $current, $seats, true );
        if ( $index === false ) {
            return $seats[0] ?? 0;
        }

        $next_index = ( $index + 1 ) % count( $seats );

        return $seats[ $next_index ];
    }
}
