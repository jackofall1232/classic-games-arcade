<?php
/**
 * Odd Man Out Game Module
 *
 * A 3-player probability game where the odd coin wins.
 *
 * Core Rules:
 * - Exactly 3 players flip one coin each simultaneously
 * - If 2 coins match and 1 differs, the odd coin player scores +1
 * - If all 3 coins match (HHH or TTT), no score for anyone
 * - No bids or decisions - pure luck
 * - First to target score wins
 *
 * @package ShortcodeArcade
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SACGA_Game_Odd_Man_Out extends SACGA_Game_Contract {
	use SACGA_Turn_Gate_Trait;

	protected $id          = 'odd-man-out';
	protected $name        = 'Odd Man Out';
	protected $type        = 'dice';
	protected $min_players = 3;
	protected $max_players = 3;
	protected $has_teams   = false;
	protected $ai_supported = true;

	/**
	 * Game constants
	 */
	const DEFAULT_TARGET_SCORE = 10;

	/**
	 * Game phases
	 */
	const PHASE_WAITING   = 'waiting';
	const PHASE_FLIPPING  = 'flipping';
	const PHASE_SCORING   = 'scoring';
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
			'description'  => __( 'A pure-luck 3-player game! Be the odd one out to score.', 'shortcode-arcade' ),
			'rules'        => [
				'objective' => __( 'Be the first player to reach the target score (default 10 points).', 'shortcode-arcade' ),
				'setup'     => __( "Exactly 3 players, each with one coin.\nNo decisions required - just flip!", 'shortcode-arcade' ),
				'gameplay'  => __( "Each round:\n1. All 3 players flip their coin at the same time.\n2. If 2 coins match and 1 differs, the \"odd\" player scores +1.\n3. If all 3 coins match, no one scores.", 'shortcode-arcade' ),
				'winning'   => __( 'First player to reach the target score wins.', 'shortcode-arcade' ),
				'notes'     => __( 'Pure luck - 75% chance someone scores each round!', 'shortcode-arcade' ),
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
			'current_turn'  => -1, // -1 during gate phases (DB column may not allow NULL)
			'round'         => 0,
			'players'       => $player_data,
			'scores'        => $scores,
			'coins'         => [], // seat => 'heads' | 'tails'
			'target_score'  => $target_score,
			'odd_player'    => null, // Seat of the odd player, or null if no odd
			'no_score'      => false, // True if all coins matched
			'last_result'   => null, // Stores last round result for display
			'game_over'     => false,
			'winners'       => null,
			'game_started'  => false,
			// Gate is open from the start - set directly to avoid reference issues
			'awaiting_gate' => 'start_game',
			'gate'          => [
				'type'       => 'start_game',
				'next_round' => 1,
			],
			'move_history'  => [],
		];
	}

	/**
	 * Deal or setup - gate is already open from init_state
	 */
	public function deal_or_setup( array $state ): array {
		// Gate is set directly in init_state to avoid reference issues with open_gate
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
				if ( $this->validate_gate_action( $state, 'continue', 'resolve_round' ) ) {
					return true;
				}
				if ( $this->validate_gate_action( $state, 'continue', 'next_round' ) ) {
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

		// No player moves in this game - only gate actions
		return new WP_Error( 'no_player_moves', __( 'Click the button to flip coins.', 'shortcode-arcade' ) );
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
		}

		return $state;
	}

	/**
	 * Apply begin_game gate action - flip coins for round 1
	 */
	private function apply_begin_game( array $state ): array {
		$state['game_started'] = true;
		$state['round']        = 1;

		// Flip coins immediately
		$state = $this->flip_all_coins( $state );

		$this->close_gate( $state );

		// Open resolve gate to show results
		$this->open_gate( $state, 'resolve_round', [
			'odd_player' => $state['odd_player'],
			'no_score'   => $state['no_score'],
		] );

		return $state;
	}

	/**
	 * Flip coins for all players and determine odd player
	 */
	private function flip_all_coins( array $state ): array {
		$state['phase'] = self::PHASE_FLIPPING;
		$state['coins'] = [];

		$seats = array_keys( $state['players'] );
		$flips = [];

		foreach ( $seats as $seat ) {
			$flip               = wp_rand( 0, 1 ) === 1 ? 'heads' : 'tails';
			$state['coins'][ $seat ] = $flip;
			$flips[ $seat ]     = $flip;
		}

		// Determine the odd player (if any)
		$state = $this->determine_odd_player( $state, $flips );

		return $state;
	}

	/**
	 * Determine which player is the odd one out
	 */
	private function determine_odd_player( array $state, array $flips ): array {
		$heads = [];
		$tails = [];

		foreach ( $flips as $seat => $flip ) {
			if ( $flip === 'heads' ) {
				$heads[] = $seat;
			} else {
				$tails[] = $seat;
			}
		}

		// Check for 2-1 split
		if ( count( $heads ) === 1 && count( $tails ) === 2 ) {
			// One heads, two tails - heads is odd
			$state['odd_player'] = $heads[0];
			$state['no_score']   = false;
		} elseif ( count( $tails ) === 1 && count( $heads ) === 2 ) {
			// One tails, two heads - tails is odd
			$state['odd_player'] = $tails[0];
			$state['no_score']   = false;
		} else {
			// All match (HHH or TTT) - no odd player
			$state['odd_player'] = null;
			$state['no_score']   = true;
		}

		return $state;
	}

	/**
	 * Apply resolve_round gate action - score and check for game end
	 */
	private function apply_resolve_round( array $state ): array {
		$state['phase'] = self::PHASE_SCORING;

		// Award point to odd player if applicable
		if ( $state['odd_player'] !== null ) {
			$state['scores'][ $state['odd_player'] ]++;
		}

		// Store result for display
		$state['last_result'] = [
			'round'      => $state['round'],
			'coins'      => $state['coins'],
			'odd_player' => $state['odd_player'],
			'no_score'   => $state['no_score'],
		];

		// Record in history
		$state['move_history'][] = [
			'round'      => $state['round'],
			'action'     => 'resolve',
			'coins'      => $state['coins'],
			'odd_player' => $state['odd_player'],
			'no_score'   => $state['no_score'],
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
	 * Apply next_round gate action - flip coins for new round
	 */
	private function apply_next_round( array $state ): array {
		$state['round'] = $this->get_gate_data( $state, 'next_round', $state['round'] + 1 );

		// Reset for new round
		$state['coins']      = [];
		$state['odd_player'] = null;
		$state['no_score']   = false;

		// Flip coins
		$state = $this->flip_all_coins( $state );

		$this->close_gate( $state );

		// Open resolve gate
		$this->open_gate( $state, 'resolve_round', [
			'odd_player' => $state['odd_player'],
			'no_score'   => $state['no_score'],
		] );

		return $state;
	}

	/**
	 * Advance to next turn - not used in gate-based flow
	 */
	public function advance_turn( array $state ): array {
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
	 * Get AI move - no decisions in this game
	 */
	public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
		// AI has no decisions in this game - all actions are gate-triggered
		// The game proceeds via gate actions which any player can trigger
		return [];
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

		return [];
	}

	/**
	 * Get public state - no hidden information
	 */
	public function get_public_state( array $state, int $player_seat ): array {
		return $state;
	}
}
