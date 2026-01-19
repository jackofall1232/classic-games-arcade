<?php
/**
 * Hearts Game Module
 *
 * Classic Hearts (4 players)
 * - Pass 3 cards each round (left, right, across, none pattern)
 * - Avoid taking hearts (1 pt each) and Queen of Spades (13 pts)
 * - Shoot the moon: Take all 26 points to give everyone else 26
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SACGA_Game_Hearts extends SACGA_Game_Contract {
	use SACGA_Card_Game_Trait;

	protected $id = 'hearts';
	protected $name = 'Hearts';
	protected $type = 'card';
	protected $min_players = 4;
	protected $max_players = 4;
	protected $has_teams = false;
	protected $ai_supported = true;

	const MAX_SCORE = 100;
	const PASS_DIRECTIONS = [ 'left', 'right', 'across', 'none' ];

	public function register_game(): array {
		return [
			'id'           => $this->id,
			'name'         => $this->name,
			'type'         => $this->type,
			'min_players'  => $this->min_players,
			'max_players'  => $this->max_players,
			'has_teams'    => $this->has_teams,
			'ai_supported' => $this->ai_supported,
			'description'  => __( 'Classic trick-taking game. Avoid hearts and the Queen of Spades!', 'shortcode-arcade' ),
			'rules'        => [
				'objective' => __( 'Have the lowest score when someone reaches 100 points.', 'shortcode-arcade' ),
				'setup'     => __( "4 players, standard 52-card deck.\nEach player receives 13 cards.\nBefore each hand, pass 3 cards (left, right, across, then no pass—repeating).", 'shortcode-arcade' ),
				'gameplay'  => __( "Player with 2 of Clubs leads the first trick.\nFollow suit if possible; otherwise play any card.\nHighest card of the led suit wins the trick.\nHearts cannot be led until \"broken\" (played on another suit).", 'shortcode-arcade' ),
				'winning'   => __( 'Each Heart captured = 1 point. Queen of Spades = 13 points. Lowest score wins when someone hits 100.', 'shortcode-arcade' ),
				'notes'     => __( 'Shoot the Moon: Capture all 26 points to give 26 to everyone else instead!', 'shortcode-arcade' ),
			],
		];
	}

	public function init_state( array $players, array $settings = [] ): array {
		return [
			'phase'           => 'passing',
			'current_turn'    => 0,
			'players'         => $this->format_players( $players ),
			'hands'           => [],
			'scores'          => [ 0, 0, 0, 0 ],
			'round_scores'    => [ 0, 0, 0, 0 ],
			'pass_direction'  => 'left',
			'passed_cards'    => [ [], [], [], [] ],
			'received_cards'  => [ [], [], [], [] ],
			'trick'           => [],
			'trick_leader'    => 0,
			'hearts_broken'   => false,
			'tricks_won'      => [ [], [], [], [] ],
			'round_number'    => 1,
			'game_over'       => false,
			'last_move_at'    => time(),
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
		$deal = $this->deal_cards( $deck, 4, 13 );

		foreach ( $deal['hands'] as $seat => $hand ) {
			$state['hands'][ $seat ] = $this->sort_hand( $hand );
		}

		// Determine pass direction
		$pass_idx = ( $state['round_number'] - 1 ) % 4;
		$state['pass_direction'] = self::PASS_DIRECTIONS[ $pass_idx ];

		// Find who has 2 of clubs
		for ( $seat = 0; $seat < 4; $seat++ ) {
			if ( $this->find_card( $state['hands'][ $seat ], 'clubs_2' ) ) {
				$state['trick_leader'] = $seat;
				$state['current_turn'] = $seat;
				break;
			}
		}

		$state['phase'] = $state['pass_direction'] === 'none' ? 'playing' : 'passing';
		$state['passed_cards'] = [ [], [], [], [] ];
		$state['received_cards'] = [ [], [], [], [] ];
		$state['trick'] = [];
		$state['hearts_broken'] = false;
		$state['tricks_won'] = [ [], [], [], [] ];
		$state['round_scores'] = [ 0, 0, 0, 0 ];
		$state['last_move_at'] = time();

		return $state;
	}

	public function validate_move( array $state, int $player_seat, array $move ) {
		// Handle round continuation
		if ( $state['phase'] === 'round_end' && isset( $move['action'] ) && $move['action'] === 'next_round' ) {
			return true;
		}

		if ( $state['phase'] === 'passing' ) {
			$cards = $move['cards'] ?? [];
			if ( count( $cards ) !== 3 ) {
				return new WP_Error( 'invalid_pass', __( 'You must pass exactly 3 cards.', 'shortcode-arcade' ) );
			}

			$hand = $state['hands'][ $player_seat ];
			foreach ( $cards as $card_id ) {
				if ( ! $this->find_card( $hand, sanitize_text_field( $card_id ) ) ) {
					return new WP_Error( 'invalid_card', __( 'You do not have one of those cards.', 'shortcode-arcade' ) );
				}
			}

			return true;
		}

		if ( $state['phase'] === 'playing' ) {
			if ( $state['current_turn'] !== $player_seat ) {
				return new WP_Error( 'not_your_turn', __( 'It is not your turn.', 'shortcode-arcade' ) );
			}

			$card_id = $move['card_id'] ?? null;
			if ( ! $card_id ) {
				return new WP_Error( 'no_card', __( 'No card specified.', 'shortcode-arcade' ) );
			}

			$card_id = sanitize_text_field( $card_id );
			$hand = $state['hands'][ $player_seat ];
			$card = $this->find_card( $hand, $card_id );

			if ( ! $card ) {
				return new WP_Error( 'invalid_card', __( 'You do not have that card.', 'shortcode-arcade' ) );
			}

			// First trick: must play 2 of clubs
			if ( $this->is_first_trick( $state ) && empty( $state['trick'] ) ) {
				if ( $card_id !== 'clubs_2' ) {
					return new WP_Error( 'must_lead_2c', __( 'You must lead with the 2 of clubs.', 'shortcode-arcade' ) );
				}
				return true;
			}

			// First trick: can't play hearts or Queen of Spades
			if ( $this->is_first_trick( $state ) ) {
				if ( $card['suit'] === 'hearts' || $card_id === 'spades_Q' ) {
					return new WP_Error( 'no_points_first', __( 'You cannot play hearts or Queen of Spades on the first trick.', 'shortcode-arcade' ) );
				}
			}

			// Must follow suit if possible
			if ( ! empty( $state['trick'] ) ) {
				$lead_suit = $state['trick'][0]['card']['suit'];
				if ( $this->has_suit( $hand, $lead_suit ) && $card['suit'] !== $lead_suit ) {
					return new WP_Error( 'must_follow', __( 'You must follow suit.', 'shortcode-arcade' ) );
				}
				return true;
			}

			// Leading: can't lead hearts unless broken or only have hearts
			if ( $card['suit'] === 'hearts' && ! $state['hearts_broken'] ) {
				if ( ! $this->only_has_hearts( $hand ) ) {
					return new WP_Error( 'hearts_not_broken', __( 'Hearts have not been broken yet.', 'shortcode-arcade' ) );
				}
			}

			return true;
		}

		return new WP_Error( 'invalid_phase', __( 'Cannot make moves in this phase.', 'shortcode-arcade' ) );
	}

	public function apply_move( array $state, int $player_seat, array $move ): array {
		// Handle round continuation
		if ( $state['phase'] === 'round_end' && isset( $move['action'] ) && $move['action'] === 'next_round' ) {
			$state['round_number']++;
			return $this->deal_or_setup( $state );
		}

		if ( $state['phase'] === 'passing' ) {
			$cards = array_map( 'sanitize_text_field', $move['cards'] );
			$state['passed_cards'][ $player_seat ] = $cards;

			// Check if all players have passed
			$all_passed = true;
			foreach ( $state['passed_cards'] as $passed ) {
				if ( empty( $passed ) ) {
					$all_passed = false;
					break;
				}
			}

			if ( $all_passed ) {
				$state = $this->exchange_cards( $state );

				// Re-find who has 2 of clubs after the exchange
				for ( $seat = 0; $seat < 4; $seat++ ) {
					if ( $this->find_card( $state['hands'][ $seat ], 'clubs_2' ) ) {
						$state['trick_leader'] = $seat;
						$state['current_turn'] = $seat;
						break;
					}
				}

				$state['phase'] = 'playing';
			}

			return $state;
		}

		if ( $state['phase'] === 'playing' ) {
			$card_id = sanitize_text_field( $move['card_id'] );
			$hand = $state['hands'][ $player_seat ];
			$card = $this->find_card( $hand, $card_id );

			// Remove card from hand
			$state['hands'][ $player_seat ] = $this->remove_card( $hand, $card_id );

			// Add to trick
			$state['trick'][] = [ 'seat' => $player_seat, 'card' => $card ];

			// Mark hearts as broken if hearts played
			if ( $card['suit'] === 'hearts' && ! $state['hearts_broken'] ) {
				$state['hearts_broken'] = true;
			}

			// Mark trick as complete when 4 cards played (don't resolve yet)
			if ( count( $state['trick'] ) === 4 ) {
				$state['trick_complete'] = true;
				// Set current_turn to null so AI doesn't immediately play next card
				$state['current_turn'] = null;
			}
		}

		return $state;
	}

	private function exchange_cards( array $state ): array {
		$direction = $state['pass_direction'];

		for ( $seat = 0; $seat < 4; $seat++ ) {
			$passed = $state['passed_cards'][ $seat ];
			$target_seat = $this->get_pass_target( $seat, $direction );

			// Remove cards from sender
			foreach ( $passed as $card_id ) {
				$state['hands'][ $seat ] = $this->remove_card( $state['hands'][ $seat ], $card_id );
			}

			// Track received cards
			$state['received_cards'][ $target_seat ] = array_merge(
				$state['received_cards'][ $target_seat ],
				$passed
			);
		}

		// Add received cards to hands
		for ( $seat = 0; $seat < 4; $seat++ ) {
			foreach ( $state['received_cards'][ $seat ] as $card_id ) {
				$card = $this->find_card_in_all_passed( $state, $card_id );
				if ( $card ) {
					$state['hands'][ $seat ][] = $card;
				}
			}
			$state['hands'][ $seat ] = $this->sort_hand( $state['hands'][ $seat ] );
		}

		return $state;
	}

	private function find_card_in_all_passed( array $state, string $card_id ): ?array {
		// Recreate deck to find card
		$deck = $this->create_standard_deck();
		foreach ( $deck as $card ) {
			if ( $card['id'] === $card_id ) {
				return $card;
			}
		}
		return null;
	}

	private function get_pass_target( int $seat, string $direction ): int {
		switch ( $direction ) {
			case 'left':
				return ( $seat + 1 ) % 4;
			case 'right':
				return ( $seat + 3 ) % 4;
			case 'across':
				return ( $seat + 2 ) % 4;
			default:
				return $seat;
		}
	}

	private function resolve_trick( array $state ): array {
		$lead_suit = $state['trick'][0]['card']['suit'];
		$winner_seat = $state['trick'][0]['seat'];
		$winner_value = $this->get_card_value( $state['trick'][0]['card'] );

		foreach ( $state['trick'] as $play ) {
			if ( $play['card']['suit'] === $lead_suit ) {
				$value = $this->get_card_value( $play['card'] );
				if ( $value > $winner_value ) {
					$winner_seat = $play['seat'];
					$winner_value = $value;
				}
			}
		}

		// Add trick to winner's tricks
		$state['tricks_won'][ $winner_seat ][] = $state['trick'];

		// Clear trick and set new leader
		$state['trick'] = [];
		$state['trick_leader'] = $winner_seat;
		$state['current_turn'] = $winner_seat;

		// Check if round is over
		if ( $this->is_round_over( $state ) ) {
			$state['phase'] = 'round_end';
			$state = $this->score_round( $state );
		}

		return $state;
	}

	private function is_round_over( array $state ): bool {
		foreach ( $state['hands'] as $hand ) {
			if ( ! empty( $hand ) ) {
				return false;
			}
		}
		return true;
	}

	private function is_first_trick( array $state ): bool {
		$total_tricks = 0;
		foreach ( $state['tricks_won'] as $tricks ) {
			$total_tricks += count( $tricks );
		}
		return $total_tricks === 0;
	}

	public function advance_turn( array $state ): array {
		// Don't advance turn when trick is complete and waiting for client to see it
		if ( ! empty( $state['trick_complete'] ) ) {
			return $state; // Leave as-is for client to see
		}

		if ( $state['phase'] === 'playing' && ! empty( $state['trick'] ) ) {
			$state['current_turn'] = ( $state['current_turn'] + 1 ) % 4;
		}
		return $state;
	}

	/**
	 * Public method to resolve a completed trick
	 */
	public function resolve_completed_trick( array $state ): array {
		if ( ! empty( $state['trick_complete'] ) && count( $state['trick'] ) === 4 ) {
			$state = $this->resolve_trick( $state );
			$state['trick_complete'] = false;
		}
		return $state;
	}

	public function check_end_condition( array $state ): array {
		if ( $state['phase'] !== 'round_end' ) {
			return [ 'ended' => false, 'reason' => null, 'winners' => null ];
		}

		foreach ( $state['scores'] as $seat => $score ) {
			if ( $score >= self::MAX_SCORE ) {
				// Find winner (lowest score)
				$min_score = min( $state['scores'] );
				$winners = [];
				foreach ( $state['scores'] as $s => $sc ) {
					if ( $sc === $min_score ) {
						$winners[] = $s;
					}
				}
				return [ 'ended' => true, 'reason' => 'max_score', 'winners' => $winners ];
			}
		}

		return [ 'ended' => false, 'reason' => null, 'winners' => null ];
	}

	public function score_round( array $state ): array {
		$round_scores = [ 0, 0, 0, 0 ];

		// Count points in each player's tricks
		for ( $seat = 0; $seat < 4; $seat++ ) {
			foreach ( $state['tricks_won'][ $seat ] as $trick ) {
				foreach ( $trick as $play ) {
					$card = $play['card'];
					if ( $card['suit'] === 'hearts' ) {
						$round_scores[ $seat ]++;
					} elseif ( $card['id'] === 'spades_Q' ) {
						$round_scores[ $seat ] += 13;
					}
				}
			}
		}

		// Check for shoot the moon
		foreach ( $round_scores as $seat => $points ) {
			if ( $points === 26 ) {
				// Shooter gets 0, everyone else gets 26
				$round_scores = [ 26, 26, 26, 26 ];
				$round_scores[ $seat ] = 0;
				break;
			}
		}

		$state['round_scores'] = $round_scores;

		// Add to total scores
		for ( $seat = 0; $seat < 4; $seat++ ) {
			$state['scores'][ $seat ] += $round_scores[ $seat ];
		}

		return $state;
	}

	public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
		if ( $state['phase'] === 'round_end' ) {
			return [ 'action' => 'next_round' ];
		}

		if ( $state['phase'] === 'passing' ) {
			$hand = $state['hands'][ $player_seat ];
			// Pass high hearts and Queen of Spades
			$to_pass = [];

			// Look for Queen of Spades
			$queen = $this->find_card( $hand, 'spades_Q' );
			if ( $queen ) {
				$to_pass[] = 'spades_Q';
			}

			// Look for high hearts
			$hearts = array_filter( $hand, fn( $c ) => $c['suit'] === 'hearts' );
			usort( $hearts, fn( $a, $b ) => $this->get_card_value( $b ) - $this->get_card_value( $a ) );

			foreach ( $hearts as $heart ) {
				if ( count( $to_pass ) < 3 ) {
					$to_pass[] = $heart['id'];
				}
			}

			// If still need more, pass highest cards
			if ( count( $to_pass ) < 3 ) {
				$sorted = $hand;
				usort( $sorted, fn( $a, $b ) => $this->get_card_value( $b ) - $this->get_card_value( $a ) );
				foreach ( $sorted as $card ) {
					if ( count( $to_pass ) < 3 && ! in_array( $card['id'], $to_pass, true ) ) {
						$to_pass[] = $card['id'];
					}
				}
			}

			return [ 'cards' => array_slice( $to_pass, 0, 3 ) ];
		}

		$valid = $this->get_valid_moves( $state, $player_seat );
		if ( empty( $valid ) ) {
			return [];
		}

		// Simple AI: play lowest valid card
		$hand = $state['hands'][ $player_seat ];
		$valid_cards = [];
		foreach ( $valid as $move ) {
			$card = $this->find_card( $hand, $move['card_id'] );
			if ( $card ) {
				$valid_cards[] = $card;
			}
		}

		if ( ! empty( $valid_cards ) ) {
			usort( $valid_cards, fn( $a, $b ) => $this->get_card_value( $a ) - $this->get_card_value( $b ) );
			return [ 'card_id' => $valid_cards[0]['id'] ];
		}

		return [ 'card_id' => $valid[0]['card_id'] ];
	}

	public function get_valid_moves( array $state, int $player_seat ): array {
		if ( $state['phase'] === 'round_end' ) {
			return [ [ 'action' => 'next_round' ] ];
		}

		if ( $state['phase'] === 'passing' ) {
			return [ [ 'action' => 'pass_cards' ] ];
		}

		if ( $state['phase'] !== 'playing' || $state['current_turn'] !== $player_seat ) {
			return [];
		}

		$hand = $state['hands'][ $player_seat ];
		$valid = [];

		foreach ( $hand as $card ) {
			$move = [ 'card_id' => $card['id'] ];
			if ( $this->validate_move( $state, $player_seat, $move ) === true ) {
				$valid[] = $move;
			}
		}

		return $valid;
	}

	public function get_public_state( array $state, int $player_seat ): array {
		$public = $state;
		$public['hands'] = [];

		foreach ( $state['hands'] as $seat => $hand ) {
			$public['hands'][ $seat ] = $seat === $player_seat ? $hand : count( $hand );
		}

		// Hide other players' passed cards during passing phase
		if ( $state['phase'] === 'passing' ) {
			$temp_passed = [];
			foreach ( $state['passed_cards'] as $seat => $passed ) {
				$temp_passed[ $seat ] = $seat === $player_seat ? $passed : ( empty( $passed ) ? [] : 'waiting' );
			}
			$public['passed_cards'] = $temp_passed;
		}

		return $public;
	}

	private function only_has_hearts( array $hand ): bool {
		foreach ( $hand as $card ) {
			if ( $card['suit'] !== 'hearts' ) {
				return false;
			}
		}
		return true;
	}
}
