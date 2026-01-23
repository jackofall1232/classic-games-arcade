<?php
/**
 * Even at Odds Game Module
 *
 * A multiplayer probability game where players bid on coin flip parity.
 *
 * Core Rules:
 * - All players bid EVEN or ODD each round
 * - All players flip one coin simultaneously (server-side RNG)
 * - Count total HEADS across all players
 * - Even heads count = EVEN wins, odd heads count = ODD wins
 * - Players with correct bids gain +1 point
 * - First to target score wins
 *
 * @package ShortcodeArcade
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SACGA_Game_EvenAtOdds extends SACGA_Game_Contract {
	use SACGA_Turn_Gate_Trait;

	protected $id          = 'even-at-odds';
	protected $name        = 'Even at Odds';
	protected $type        = 'dice';
	protected $min_players = 2;
	protected $max_players = 8;
	protected $has_teams   = false;
	protected $ai_supported = true;

	/**
	 * Game constants
	 */
	const DEFAULT_TARGET_SCORE = 10;

	/**
	 * Game phases
	 */
	const PHASE_WAITING  = 'waiting';
	const PHASE_BIDDING  = 'bidding';
	const PHASE_FLIPPING = 'flipping';
	const PHASE_SCORING  = 'scoring';
	const PHASE_GAME_OVER = 'game_over';

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
			'description'  => __( 'A party probability game! Bid on whether the total coin flips will be even or odd.', 'shortcode-arcade' ),
			'rules'        => [
				'objective' => __( 'Be the first player to reach the target score (default 10 points).', 'shortcode-arcade' ),
				'setup'     => __( "2-8 players, each with one coin.\nAll players participate simultaneously each round.", 'shortcode-arcade' ),
				'gameplay'  => __( "Each round:\n1. All players secretly bid EVEN or ODD.\n2. Everyone flips their coin at the same time.\n3. Count total HEADS across all players.\n4. If the count is even, EVEN bids win. If odd, ODD bids win.\n5. Winning bidders score +1 point.", 'shortcode-arcade' ),
				'winning'   => __( 'First player to reach the target score wins. If multiple reach it on the same round, the highest score wins.', 'shortcode-arcade' ),
				'notes'     => __( 'With an even number of players, EVEN and ODD are equally likely. With an odd number, one slightly favors!', 'shortcode-arcade' ),
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

		$player_data = $this->format_players( $players );
		$scores      = $this->init_scores( $players );

		return [
			'phase'         => self::PHASE_WAITING,
			'current_turn'  => null, // Always null - gate-based flow
			'round'         => 0,
			'players'       => $player_data,
			'scores'        => $scores,
			'bids'          => [], // seat => 'even' | 'odd' | null
			'coins'         => [], // seat => 'heads' | 'tails' | null
			'target_score'  => $target_score,
			'result'        => [
				'heads'  => null,
				'parity' => null,
			],
			'last_result'   => null, // Stores last round result for display
			'game_over'     => false,
			'winners'       => null,
			'game_started'  => false,
			'awaiting_gate' => null,
			'gate'          => null,
			'move_history'  => [],
		];
	}

	/**
	 * Deal or setup - open start gate
	 */
	public function deal_or_setup( array $state ): array {
		// Open start game gate
		$this->open_gate( $state, 'start_game', [
			'next_round' => 1,
		] );
		return $state;
	}

	/**
	 * Format players for state
	 */
	private function format_players( array $players ): array {
		$formatted = [];
		foreach ( $players as $player ) {
			$seat              = (int) $player['seat_position'];
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
	 * Validate a move
	 */
	public function validate_move( array $state, int $player_seat, array $move ) {
		$action = $move['action'] ?? '';

		// GATE WHITELIST: Gate actions bypass normal validation
		if ( $this->is_gate_action( $move ) ) {
			if ( $action === 'begin_game' && $this->validate_gate_action( $state, 'begin_game', 'start_game' ) ) {
				return true;
			}
			if ( $action === 'continue' ) {
				if ( $this->validate_gate_action( $state, 'continue', 'next_round' ) ) {
					return true;
				}
				if ( $this->validate_gate_action( $state, 'continue', 'resolve_round' ) ) {
					return true;
				}
			}
			return new WP_Error( 'invalid_gate_action', __( 'This action is not available right now.', 'shortcode-arcade' ) );
		}

		// Global gate enforcement
		if ( $this->is_gate_open( $state ) ) {
			return new WP_Error( 'awaiting_gate', __( 'Please click the button to continue.', 'shortcode-arcade' ) );
		}

		// Game over check
		if ( ! empty( $state['game_over'] ) ) {
			return new WP_Error( 'game_over', __( 'The game has ended.', 'shortcode-arcade' ) );
		}

		// Phase-specific validation
		switch ( $state['phase'] ) {
			case self::PHASE_BIDDING:
				if ( $action !== 'bid' ) {
					return new WP_Error( 'invalid_action', __( 'You must place a bid.', 'shortcode-arcade' ) );
				}
				$value = $move['value'] ?? '';
				if ( ! in_array( $value, [ 'even', 'odd' ], true ) ) {
					return new WP_Error( 'invalid_bid', __( 'Bid must be "even" or "odd".', 'shortcode-arcade' ) );
				}
				if ( isset( $state['bids'][ $player_seat ] ) && $state['bids'][ $player_seat ] !== null ) {
					return new WP_Error( 'already_bid', __( 'You have already placed your bid.', 'shortcode-arcade' ) );
				}
				return true;

			case self::PHASE_WAITING:
				return new WP_Error( 'waiting_phase', __( 'Please click the button to continue.', 'shortcode-arcade' ) );

			case self::PHASE_FLIPPING:
			case self::PHASE_SCORING:
				return new WP_Error( 'processing_phase', __( 'Please wait for the round to complete.', 'shortcode-arcade' ) );

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
			if ( $state['awaiting_gate'] === 'resolve_round' ) {
				return $this->apply_resolve_round( $state );
			}
			if ( $state['awaiting_gate'] === 'next_round' ) {
				return $this->apply_next_round( $state );
			}
			return $state;
		}

		// Handle bid action
		if ( $action === 'bid' ) {
			return $this->apply_bid( $state, $player_seat, $move['value'] );
		}

		return $state;
	}

	/**
	 * Apply begin_game gate action
	 */
	private function apply_begin_game( array $state ): array {
		$state['game_started'] = true;
		$state['round']        = 1;
		$state['phase']        = self::PHASE_BIDDING;

		// Reset bids for new round
		$state['bids'] = [];
		foreach ( array_keys( $state['players'] ) as $seat ) {
			$state['bids'][ $seat ] = null;
		}

		$this->close_gate( $state );
		return $state;
	}

	/**
	 * Apply bid move
	 */
	private function apply_bid( array $state, int $player_seat, string $value ): array {
		$state['bids'][ $player_seat ] = $value;

		// Record in history
		$state['move_history'][] = [
			'round'  => $state['round'],
			'player' => $player_seat,
			'action' => 'bid',
			'value'  => $value,
		];

		// Check if all players have bid
		$all_bid = true;
		foreach ( array_keys( $state['players'] ) as $seat ) {
			if ( ! isset( $state['bids'][ $seat ] ) || $state['bids'][ $seat ] === null ) {
				$all_bid = false;
				break;
			}
		}

		if ( $all_bid ) {
			// All bids collected - flip coins and open resolve gate
			$state = $this->flip_all_coins( $state );

			// Open gate for players to see result before continuing
			$this->open_gate( $state, 'resolve_round', [
				'heads'  => $state['result']['heads'],
				'parity' => $state['result']['parity'],
			] );
		}

		return $state;
	}

	/**
	 * Flip coins for all players
	 */
	private function flip_all_coins( array $state ): array {
		$state['phase'] = self::PHASE_FLIPPING;
		$state['coins'] = [];
		$heads_count    = 0;

		foreach ( array_keys( $state['players'] ) as $seat ) {
			$flip = wp_rand( 0, 1 ) === 1 ? 'heads' : 'tails';
			$state['coins'][ $seat ] = $flip;
			if ( $flip === 'heads' ) {
				$heads_count++;
			}
		}

		$parity = ( $heads_count % 2 === 0 ) ? 'even' : 'odd';

		$state['result'] = [
			'heads'  => $heads_count,
			'parity' => $parity,
		];

		return $state;
	}

	/**
	 * Apply resolve_round gate action - score the round
	 */
	private function apply_resolve_round( array $state ): array {
		$state['phase'] = self::PHASE_SCORING;
		$parity         = $state['result']['parity'];
		$round_winners  = [];

		// Award points to correct bidders
		foreach ( array_keys( $state['players'] ) as $seat ) {
			if ( isset( $state['bids'][ $seat ] ) && $state['bids'][ $seat ] === $parity ) {
				$state['scores'][ $seat ]++;
				$round_winners[] = $seat;
			}
		}

		// Store result for display
		$state['last_result'] = [
			'round'         => $state['round'],
			'coins'         => $state['coins'],
			'bids'          => $state['bids'],
			'heads'         => $state['result']['heads'],
			'parity'        => $parity,
			'round_winners' => $round_winners,
		];

		// Record in history
		$state['move_history'][] = [
			'round'         => $state['round'],
			'action'        => 'resolve',
			'coins'         => $state['coins'],
			'heads'         => $state['result']['heads'],
			'parity'        => $parity,
			'round_winners' => $round_winners,
		];

		$this->close_gate( $state );

		// Check for game end
		$end_check = $this->check_end_condition( $state );
		if ( $end_check['ended'] ) {
			$state['phase']     = self::PHASE_GAME_OVER;
			$state['game_over'] = true;
			$state['winners']   = $end_check['winners'];
			return $state;
		}

		// Open gate for next round
		$this->open_gate( $state, 'next_round', [
			'next_round' => $state['round'] + 1,
		] );

		return $state;
	}

	/**
	 * Apply next_round gate action
	 */
	private function apply_next_round( array $state ): array {
		$state['round'] = $this->get_gate_data( $state, 'next_round', $state['round'] + 1 );
		$state['phase'] = self::PHASE_BIDDING;

		// Reset for new round
		$state['bids']   = [];
		$state['coins']  = [];
		$state['result'] = [
			'heads'  => null,
			'parity' => null,
		];

		foreach ( array_keys( $state['players'] ) as $seat ) {
			$state['bids'][ $seat ] = null;
		}

		$this->close_gate( $state );
		return $state;
	}

	/**
	 * Advance to next turn - not used in gate-based flow
	 */
	public function advance_turn( array $state ): array {
		// Gate-based flow - no turn advancement
		return $state;
	}

	/**
	 * Check end condition
	 */
	public function check_end_condition( array $state ): array {
		$target  = $state['target_score'];
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
	 * Score round - handled in apply_resolve_round
	 */
	public function score_round( array $state ): array {
		return $state;
	}

	/**
	 * Get AI move - simple 50/50 random for bids
	 */
	public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
		// AI must respect gates
		if ( $this->is_gate_open( $state ) ) {
			return [];
		}

		if ( ! empty( $state['game_over'] ) ) {
			return [];
		}

		// AI may only bid during bidding phase
		if ( $state['phase'] !== self::PHASE_BIDDING ) {
			return [];
		}

		// Check if AI has already bid
		if ( isset( $state['bids'][ $player_seat ] ) && $state['bids'][ $player_seat ] !== null ) {
			return [];
		}

		// 50/50 random bid
		$bid = wp_rand( 0, 1 ) === 0 ? 'even' : 'odd';

		return [
			'action' => 'bid',
			'value'  => $bid,
		];
	}

	/**
	 * Get valid moves for a player
	 */
	public function get_valid_moves( array $state, int $player_seat ): array {
		// Handle gate actions
		if ( $state['awaiting_gate'] === 'start_game' ) {
			return [ [ 'action' => 'begin_game' ] ];
		}

		if ( $state['awaiting_gate'] === 'resolve_round' ) {
			return [ [ 'action' => 'continue' ] ];
		}

		if ( $state['awaiting_gate'] === 'next_round' ) {
			return [ [ 'action' => 'continue' ] ];
		}

		if ( ! empty( $state['game_over'] ) ) {
			return [];
		}

		if ( $state['phase'] === self::PHASE_BIDDING ) {
			// Check if player has already bid
			if ( isset( $state['bids'][ $player_seat ] ) && $state['bids'][ $player_seat ] !== null ) {
				return [];
			}
			return [
				[ 'action' => 'bid', 'value' => 'even' ],
				[ 'action' => 'bid', 'value' => 'odd' ],
			];
		}

		return [];
	}

	/**
	 * Get public state - hide other players' bids until all have bid
	 */
	public function get_public_state( array $state, int $player_seat ): array {
		$public = $state;

		// During bidding phase, hide other players' bids
		if ( $state['phase'] === self::PHASE_BIDDING ) {
			$public['bids'] = [];
			foreach ( array_keys( $state['players'] ) as $seat ) {
				if ( $seat === $player_seat ) {
					$public['bids'][ $seat ] = $state['bids'][ $seat ] ?? null;
				} else {
					// Show whether they've bid, but not what
					$public['bids'][ $seat ] = isset( $state['bids'][ $seat ] ) && $state['bids'][ $seat ] !== null
						? 'hidden'
						: null;
				}
			}
		}

		return $public;
	}
}
