<?php
/**
 * Cribbage Game Module
 *
 * 2-player Cribbage - First to 121 wins
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SACGA_Game_Cribbage extends SACGA_Game_Contract {
	use SACGA_Card_Game_Trait;

	protected $id = 'cribbage';
	protected $name = 'Cribbage';
	protected $type = 'card';
	protected $min_players = 2;
	protected $max_players = 2;
	protected $has_teams = false;
	protected $ai_supported = true;

	const WIN_SCORE = 121;

	public function register_game(): array {
		return [
			'id'           => $this->id,
			'name'         => $this->name,
			'type'         => $this->type,
			'min_players'  => $this->min_players,
			'max_players'  => $this->max_players,
			'has_teams'    => $this->has_teams,
			'ai_supported' => $this->ai_supported,
			'description'  => __( 'Classic 2-player game with pegging and hand scoring. First to 121 wins!', 'shortcode-arcade' ),
			'rules'        => [
				'objective' => __( 'Be the first player to score 121 points on the cribbage board.', 'shortcode-arcade' ),
				'setup'     => __( "2 players, standard 52-card deck.\n6 cards dealt to each player.\nEach player discards 2 cards to the \"crib\" (extra hand for dealer).\nCut deck to reveal \"starter\" card.", 'shortcode-arcade' ),
				'gameplay'  => __( "Pegging: Alternate playing cards, announcing running total. Score 2 for hitting 15 or 31, 2 for pairs, and points for runs. \"Go\" when you can't play under 31.\nHand Scoring: After pegging, score your hand + starter card.\n15s = 2 pts each. Pairs = 2 pts. Runs = 1 pt per card. Flush = 4-5 pts. Nobs (J of starter suit) = 1 pt.", 'shortcode-arcade' ),
				'winning'   => __( 'First to 121 points wins. You can win during pegging or hand scoring.', 'shortcode-arcade' ),
				'notes'     => __( 'Dealer scores the crib as a bonus hand. Card values: A=1, 2-10=face, J/Q/K=10.', 'shortcode-arcade' ),
			],
		];
	}

	public function init_state( array $players, array $settings = [] ): array {
		return [
			'phase'        => 'discard',
			'current_turn' => 1,
			'dealer'       => 0,
			'players'      => $this->format_players( $players ),
			'hands'        => [],
			'crib'         => [],
			'starter'      => null,
			'peg_pile'     => [],
			'peg_count'    => 0,
			'peg_hands'    => [],
			'last_player'  => null,
			'go_called'    => [ false, false ],
			'scores'       => [ 0, 0 ],
			'prev_scores'  => [ 0, 0 ],
			'round_number' => 1,
			'game_over'    => false,
			'discards'     => [ [], [] ],
			'last_move_at' => time(),
		];
	}

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

	public function deal_or_setup( array $state ): array {
		$deck = $this->create_standard_deck();
		$deck = $this->shuffle_deck( $deck );

		$state['hands'] = [ [], [] ];
		for ( $i = 0; $i < 6; $i++ ) {
			$state['hands'][0][] = array_shift( $deck );
			$state['hands'][1][] = array_shift( $deck );
		}

		$state['hands'][0] = $this->sort_hand( $state['hands'][0] );
		$state['hands'][1] = $this->sort_hand( $state['hands'][1] );
		$state['deck'] = $deck;
		$state['phase'] = 'discard';
		$state['crib'] = [];
		$state['starter'] = null;
		$state['peg_pile'] = [];
		$state['peg_count'] = 0;
		$state['peg_hands'] = [];
		$state['last_player'] = null;
		$state['go_called'] = [ false, false ];
		$state['discards'] = [ [], [] ];
		$state['current_turn'] = ( $state['dealer'] + 1 ) % 2;
		$state['last_move_at'] = time();

		error_log( sprintf(
			'[Cribbage] Round %d setup complete. Dealer: %d, Current turn: %d, Hands dealt: [%d, %d]',
			$state['round_number'] ?? 1,
			$state['dealer'],
			$state['current_turn'],
			count( $state['hands'][0] ),
			count( $state['hands'][1] )
		) );

		return $state;
	}

	public function validate_move( array $state, int $player_seat, array $move ) {
		// Debug logging
		error_log( sprintf(
			'[Cribbage validate_move] player_seat: %d, phase: %s, move: %s',
			$player_seat,
			$state['phase'] ?? 'null',
			json_encode( $move )
		) );

		// Handle round continuation
		if ( $state['phase'] === 'round_end' ) {
			$action = isset( $move['action'] ) ? sanitize_text_field( $move['action'] ) : '';
			if ( $action === 'next_round' ) {
				return true;
			}
			return new WP_Error( 'invalid_action', __( 'Invalid action in round_end phase.', 'shortcode-arcade' ) );
		}

		if ( $state['phase'] === 'discard' ) {
			$cards = $move['cards'] ?? [];
			if ( ! is_array( $cards ) || count( $cards ) !== 2 ) {
				return new WP_Error( 'invalid_discard', __( 'Must discard exactly 2 cards.', 'shortcode-arcade' ) );
			}
			$hand = $state['hands'][ $player_seat ];
			$hand_ids = array_column( $hand, 'id' );
			foreach ( $cards as $card_id ) {
				$card_id = sanitize_text_field( $card_id );
				if ( ! in_array( $card_id, $hand_ids, true ) ) {
					return new WP_Error( 'invalid_card', __( 'Card not in hand.', 'shortcode-arcade' ) );
				}
			}
			return true;
		}

		if ( $state['phase'] === 'pegging' ) {
			if ( $state['current_turn'] !== $player_seat ) {
				return new WP_Error( 'not_your_turn', __( 'Not your turn.', 'shortcode-arcade' ) );
			}

			$action = isset( $move['action'] ) ? sanitize_text_field( $move['action'] ) : 'play';
			if ( $action === 'go' ) {
				$playable = $this->get_playable_cards( $state, $player_seat );
				if ( ! empty( $playable ) ) {
					return new WP_Error( 'must_play', __( 'You have playable cards.', 'shortcode-arcade' ) );
				}
				return true;
			}

			$card_id = isset( $move['card_id'] ) ? sanitize_text_field( $move['card_id'] ) : null;
			if ( ! $card_id ) {
				return new WP_Error( 'no_card', __( 'No card specified.', 'shortcode-arcade' ) );
			}

			$hand = $state['peg_hands'][ $player_seat ];
			$card = $this->find_card( $hand, $card_id );
			if ( ! $card ) {
				return new WP_Error( 'invalid_card', __( 'Card not in hand.', 'shortcode-arcade' ) );
			}

			if ( $state['peg_count'] + $this->get_peg_value( $card ) > 31 ) {
				return new WP_Error( 'over_31', __( 'Card would exceed 31.', 'shortcode-arcade' ) );
			}

			return true;
		}

		return new WP_Error( 'invalid_phase', __( 'Cannot make moves now.', 'shortcode-arcade' ) );
	}

	public function apply_move( array $state, int $player_seat, array $move ): array {
		$state['last_move_at'] = time();

		// Handle round continuation
		if ( $state['phase'] === 'round_end' ) {
			$state['dealer'] = ( $state['dealer'] + 1 ) % 2;
			$state['round_number']++;
			$state = $this->deal_or_setup( $state );
			return $state;
		}

		if ( $state['phase'] === 'discard' ) {
			$cards = array_map( 'sanitize_text_field', $move['cards'] );
			$state['discards'][ $player_seat ] = $cards;

			$hand = $state['hands'][ $player_seat ];
			foreach ( $cards as $card_id ) {
				$card = $this->find_card( $hand, $card_id );
				if ( $card ) {
					$state['crib'][] = $card;
				}
			}
			$state['hands'][ $player_seat ] = array_values( array_filter( $hand, fn( $c ) => ! in_array( $c['id'], $cards, true ) ) );

			// Fix: Add defensive checks and logging for discard phase completion
			$discards_0 = is_array( $state['discards'][0] ?? null ) ? count( $state['discards'][0] ) : 0;
			$discards_1 = is_array( $state['discards'][1] ?? null ) ? count( $state['discards'][1] ) : 0;

			error_log( sprintf(
				'[Cribbage] Discard phase: Player %d discarded. Discards status: [%d, %d]',
				$player_seat,
				$discards_0,
				$discards_1
			) );

			if ( $discards_0 === 2 && $discards_1 === 2 ) {
				error_log( '[Cribbage] Both players discarded. Advancing to pegging phase.' );
				$state['starter'] = array_shift( $state['deck'] );
				if ( $state['starter']['rank'] === 'J' ) {
					$state['prev_scores'] = $state['scores'];
					$state['scores'][ $state['dealer'] ] += 2;
				}
				$state['phase'] = 'pegging';
				$state['peg_hands'] = $state['hands'];
				$state['peg_pile'] = [];
				$state['peg_count'] = 0;
				$state['current_turn'] = ( $state['dealer'] + 1 ) % 2;
				$state['go_called'] = [ false, false ];
			}

			return $state;
		}

		if ( $state['phase'] === 'pegging' ) {
			$action = isset( $move['action'] ) ? sanitize_text_field( $move['action'] ) : 'play';

			if ( $action === 'go' ) {
				$state['go_called'][ $player_seat ] = true;
				$other_seat = ( $player_seat + 1 ) % 2;
				$other_has_cards = ! empty( $state['peg_hands'][ $other_seat ] );
				$other_playable = ! empty( $this->get_playable_cards( $state, $other_seat ) );

				// If the opponent has no cards, resolve the go immediately (no stall).
				if ( ! $other_has_cards ) {
					return $this->resolve_go_reset( $state );
				}

				if ( $other_playable ) {
					$state['current_turn'] = $other_seat;
					return $state;
				}

				if ( $state['go_called'][ $other_seat ] ) {
					return $this->resolve_go_reset( $state );
				}

				$state['current_turn'] = $other_seat;
				return $state;
			}

			$card_id = sanitize_text_field( $move['card_id'] );
			$hand = $state['peg_hands'][ $player_seat ];
			$card = $this->find_card( $hand, $card_id );

			$state['peg_hands'][ $player_seat ] = $this->remove_card( $hand, $card_id );
			$state['peg_pile'][] = [ 'seat' => $player_seat, 'card' => $card ];
			$state['peg_count'] += $this->get_peg_value( $card );
			$state['last_player'] = $player_seat;
			$state['go_called'] = [ false, false ];

			$state['prev_scores'] = $state['scores'];
			$state['scores'][ $player_seat ] += $this->score_peg( $state['peg_pile'], $state['peg_count'] );

			if ( $state['peg_count'] === 31 ) {
				$state['peg_pile'] = [];
				$state['peg_count'] = 0;
			}

			// Fix: Advance turn after card play
			$state = $this->advance_turn( $state );

			if ( empty( $state['peg_hands'][0] ) && empty( $state['peg_hands'][1] ) ) {
				if ( $state['peg_count'] > 0 && $state['peg_count'] < 31 ) {
					$state['prev_scores'] = $state['scores'];
					$state['scores'][ $state['last_player'] ] += 1;
				}
				$state = $this->score_hands( $state );
				$state['phase'] = 'round_end';
			}
		}

		return $state;
	}

	private function get_playable_cards( array $state, int $seat ): array {
		$hand = $state['peg_hands'][ $seat ];
		return array_filter( $hand, fn( $c ) => $state['peg_count'] + $this->get_peg_value( $c ) <= 31 );
	}

	private function get_peg_value( array $card ): int {
		$values = [ 'A' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9, '10' => 10, 'J' => 10, 'Q' => 10, 'K' => 10 ];
		return $values[ $card['rank'] ] ?? 0;
	}

	private function score_peg( array $pile, int $count ): int {
		$points = 0;
		if ( $count === 15 || $count === 31 ) {
			$points += 2;
		}

		if ( count( $pile ) >= 2 ) {
			$ranks = array_map( fn( $p ) => $p['card']['rank'], array_reverse( $pile ) );
			$pair_count = 1;
			for ( $i = 1; $i < count( $ranks ); $i++ ) {
				if ( $ranks[ $i ] === $ranks[0] ) {
					$pair_count++;
				} else {
					break;
				}
			}
			if ( $pair_count === 2 ) {
				$points += 2;
			}
			if ( $pair_count === 3 ) {
				$points += 6;
			}
			if ( $pair_count === 4 ) {
				$points += 12;
			}
		}

		return $points;
	}

	private function score_hands( array $state ): array {
		$state['prev_scores'] = $state['scores'];
		$non_dealer = ( $state['dealer'] + 1 ) % 2;
		$state['scores'][ $non_dealer ] += $this->score_hand( $state['hands'][ $non_dealer ], $state['starter'] );
		$state['scores'][ $state['dealer'] ] += $this->score_hand( $state['hands'][ $state['dealer'] ], $state['starter'] );
		$state['scores'][ $state['dealer'] ] += $this->score_hand( $state['crib'], $state['starter'], true );
		return $state;
	}

	private function score_hand( array $hand, array $starter, bool $is_crib = false ): int {
		$cards = array_merge( $hand, [ $starter ] );
		$points = 0;

		// 15s
		$n = count( $cards );
		for ( $mask = 1; $mask < ( 1 << $n ); $mask++ ) {
			$sum = 0;
			for ( $i = 0; $i < $n; $i++ ) {
				if ( $mask & ( 1 << $i ) ) {
					$sum += $this->get_peg_value( $cards[ $i ] );
				}
			}
			if ( $sum === 15 ) {
				$points += 2;
			}
		}

		// Pairs
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( $cards[ $i ]['rank'] === $cards[ $j ]['rank'] ) {
					$points += 2;
				}
			}
		}

		// Flush
		$hand_suits = array_unique( array_column( $hand, 'suit' ) );
		if ( count( $hand_suits ) === 1 ) {
			if ( $starter['suit'] === $hand_suits[0] ) {
				$points += 5;
			} elseif ( ! $is_crib ) {
				$points += 4;
			}
		}

		// Nobs
		foreach ( $hand as $card ) {
			if ( $card['rank'] === 'J' && $card['suit'] === $starter['suit'] ) {
				$points += 1;
				break;
			}
		}

		return $points;
	}

	private function resolve_go_reset( array $state ): array {
		if ( $state['last_player'] !== null ) {
			$state['prev_scores'] = $state['scores'];
			$state['scores'][ $state['last_player'] ] += 1;
			$state['current_turn'] = $state['last_player'];
		}

		$state['peg_pile'] = [];
		$state['peg_count'] = 0;
		$state['go_called'] = [ false, false ];

		return $this->normalize_pegging_turn( $state );
	}

	private function normalize_pegging_turn( array $state ): array {
		if ( $state['phase'] !== 'pegging' ) {
			return $state;
		}

		$current = $state['current_turn'];
		$other = ( $current + 1 ) % 2;
		$current_has_cards = ! empty( $state['peg_hands'][ $current ] );
		$other_has_cards = ! empty( $state['peg_hands'][ $other ] );

		if ( ! $current_has_cards && $other_has_cards ) {
			$state['current_turn'] = $other;
		}

		return $state;
	}

	public function advance_turn( array $state ): array {
		if ( $state['phase'] === 'pegging' ) {
			$current = $state['current_turn'];
			$other = ( $current + 1 ) % 2;

			$current_has_cards = ! empty( $state['peg_hands'][ $current ] );
			$other_has_cards = ! empty( $state['peg_hands'][ $other ] );

			if ( ! $current_has_cards && $other_has_cards ) {
				$state['current_turn'] = $other;
				return $state;
			}

			if ( ! $current_has_cards && ! $other_has_cards ) {
				return $state;
			}

			$state['current_turn'] = $other;

			if ( ! $other_has_cards && $current_has_cards ) {
				$state['current_turn'] = $current;
			}
		}
		return $state;
	}

	public function check_end_condition( array $state ): array {
		foreach ( $state['scores'] as $seat => $score ) {
			if ( $score >= self::WIN_SCORE ) {
				return [ 'ended' => true, 'reason' => 'win_score', 'winners' => [ $seat ] ];
			}
		}
		return [ 'ended' => false, 'reason' => null, 'winners' => null ];
	}

	public function score_round( array $state ): array {
		return $state;
	}

	public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
		if ( $state['phase'] === 'discard' ) {
			$hand = $state['hands'][ $player_seat ];
			$sorted = $this->sort_hand( $hand );
			return [ 'cards' => [ $sorted[0]['id'], $sorted[1]['id'] ] ];
		}

		if ( $state['phase'] === 'pegging' ) {
			$playable = $this->get_playable_cards( $state, $player_seat );
			if ( empty( $playable ) ) {
				return [ 'action' => 'go' ];
			}
			// Pick a random playable card
			$random_card = $playable[ array_rand( $playable ) ];
			return [ 'card_id' => $random_card['id'] ];
		}

		if ( $state['phase'] === 'round_end' ) {
			return [ 'action' => 'next_round' ];
		}

		return [];
	}

	public function get_valid_moves( array $state, int $player_seat ): array {
		if ( $state['phase'] === 'discard' ) {
			return array_map( fn( $c ) => [ 'card_id' => $c['id'] ], $state['hands'][ $player_seat ] );
		}

		if ( $state['phase'] === 'pegging' && $state['current_turn'] === $player_seat ) {
			$playable = $this->get_playable_cards( $state, $player_seat );
			if ( empty( $playable ) ) {
				return [ [ 'action' => 'go' ] ];
			}
			return array_map( fn( $c ) => [ 'card_id' => $c['id'] ], array_values( $playable ) );
		}

		if ( $state['phase'] === 'round_end' ) {
			return [ [ 'action' => 'next_round' ] ];
		}

		return [];
	}

	public function get_public_state( array $state, int $player_seat ): array {
		$public = $state;
		$public['hands'] = [];
		foreach ( $state['hands'] as $seat => $hand ) {
			$public['hands'][ $seat ] = $seat === $player_seat ? $hand : count( $hand );
		}
		$public['peg_hands'] = [];
		foreach ( $state['peg_hands'] as $seat => $hand ) {
			$public['peg_hands'][ $seat ] = $seat === $player_seat ? $hand : count( $hand );
		}
		$public['crib'] = count( $state['crib'] );
		unset( $public['deck'] );
		return $public;
	}
}
