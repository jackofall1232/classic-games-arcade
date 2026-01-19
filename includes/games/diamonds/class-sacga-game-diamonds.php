<?php
/**
 * Diamonds Game Module
 *
 * Partnership Diamonds (4 players, 2 teams)
 * - Diamonds always trump (but carry -1 penalty each)
 * - 4 Jokers added (56 cards, 14 per player)
 * - Jokers are lowest rank, carry -1 penalty each
 * - Bidding phase
 * - First to 500 wins
 * - Soft Moon: 10+ Diamonds = +50 or remove Diamond penalties
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_Game_Diamonds extends SACGA_Game_Contract {
    use SACGA_Card_Game_Trait;

    protected $id = 'diamonds';
    protected $name = 'Diamonds';
    protected $type = 'card';
    protected $min_players = 4;
    protected $max_players = 4;
    protected $has_teams = true;
    protected $ai_supported = true;

    const WIN_SCORE = 500;
    const LOSE_SCORE = -200;
    const BAG_PENALTY_THRESHOLD = 10;
    const BAG_PENALTY = 100;
    const NIL_BONUS = 100;
    const NIL_PENALTY = 100;
    const SOFT_MOON_THRESHOLD = 10;
    const SOFT_MOON_BONUS = 50;

    public function register_game(): array {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'type'         => $this->type,
            'min_players'  => $this->min_players,
            'max_players'  => $this->max_players,
            'has_teams'    => $this->has_teams,
            'ai_supported' => $this->ai_supported,
            'description'  => __( 'Diamonds are always trump—but they hurt you. Avoid capturing Diamonds and Jokers!', 'shortcode-arcade' ),
            'rules'        => [
                'objective' => __( 'Be the first team to reach 500 points while avoiding diamond penalties.', 'shortcode-arcade' ),
                'setup'     => __( "4 players in 2 partnerships.\n56-card deck (standard 52 + 4 Jokers).\n14 cards dealt to each player. Diamonds are always trump.", 'shortcode-arcade' ),
                'gameplay'  => __( "Bidding: Each player bids tricks they expect to win (0-14). Team bids combine.\nPlay: Follow suit if possible. Diamonds beat other suits. Jokers are lowest rank.\nDiamonds cannot be led until broken.", 'shortcode-arcade' ),
                'winning'   => __( 'Make your bid: 10 pts × tricks bid + 1 per overtrick. Each Diamond captured = -1 pt. Each Joker = -1 pt. First to 500 wins.', 'shortcode-arcade' ),
                'notes'     => __( 'Soft Moon: Capture 10+ Diamonds to earn +50 bonus or cancel Diamond penalties. Nil bid: +100/-100.', 'shortcode-arcade' ),
            ],
        ];
    }

    public function init_state( array $players, array $settings = [] ): array {
        return [
            'phase'             => 'bidding',
            'current_turn'      => 0,
            'dealer'            => 0,
            'players'           => $this->format_players( $players ),
            'teams'             => [ [ 0, 2 ], [ 1, 3 ] ],
            'hands'             => [],
            'bids'              => [ null, null, null, null ],
            'nil_bids'          => [ null, null, null, null ],
            'tricks_won'        => [ 0, 0, 0, 0 ],
            'diamonds_captured' => [ 0, 0 ], // Per team
            'jokers_captured'   => [ 0, 0 ], // Per team
            'trick'             => [],
            'trick_leader'      => 1,
            'team_scores'       => [ 0, 0 ],
            'team_bags'         => [ 0, 0 ],
            'round_number'      => 1,
            'game_over'         => false,
            'last_move_at'      => time(),
        ];
    }

    private function format_players( array $players ): array {
        $formatted = [];
        foreach ( $players as $player ) {
            $seat = (int) $player['seat_position'];
            $formatted[ $seat ] = [
                'name'  => $player['display_name'],
                'is_ai' => (bool) $player['is_ai'],
                'team'  => $seat % 2,
            ];
        }
        return $formatted;
    }

    public function deal_or_setup( array $state ): array {
        $deck = $this->create_deck_with_jokers();
        $deck = $this->shuffle_deck( $deck );
        $deal = $this->deal_cards( $deck, 4, 14 ); // 56 cards / 4 players = 14 each

        foreach ( $deal['hands'] as $seat => $hand ) {
            $state['hands'][ $seat ] = $this->sort_hand_with_jokers( $hand );
        }

        $state['phase'] = 'bidding';
        $state['bids'] = [ null, null, null, null ];
        $state['nil_bids'] = [ null, null, null, null ];
        $state['tricks_won'] = [ 0, 0, 0, 0 ];
        $state['diamonds_captured'] = [ 0, 0 ];
        $state['jokers_captured'] = [ 0, 0 ];
        $state['trick'] = [];
        $state['current_turn'] = ( $state['dealer'] + 1 ) % 4;
        $state['trick_leader'] = $state['current_turn'];
        $state['last_move_at'] = time();

        return $state;
    }

    /**
     * Create a 56-card deck (standard 52 + 4 jokers)
     */
    private function create_deck_with_jokers(): array {
        $deck = $this->create_standard_deck();

        // Add 4 jokers
        for ( $i = 1; $i <= 4; $i++ ) {
            $deck[] = [
                'id'   => 'joker_' . $i,
                'suit' => 'joker',
                'rank' => 'joker',
            ];
        }

        return $deck;
    }

    /**
     * Sort hand with jokers at the end
     */
    private function sort_hand_with_jokers( array $hand ): array {
        // Separate jokers from regular cards
        $jokers = array_filter( $hand, fn( $c ) => $c['suit'] === 'joker' );
        $regular = array_filter( $hand, fn( $c ) => $c['suit'] !== 'joker' );

        // Sort regular cards - Diamonds first (trump) for visibility
        $suit_order = [ 'diamonds' => 0, 'spades' => 1, 'hearts' => 2, 'clubs' => 3 ];
        $regular = $this->sort_hand( array_values( $regular ), $suit_order );

        // Jokers go at the end
        return array_merge( $regular, array_values( $jokers ) );
    }

    public function validate_move( array $state, int $player_seat, array $move ) {
        // Handle round continuation
        if ( $state['phase'] === 'round_end' && isset( $move['action'] ) && $move['action'] === 'next_round' ) {
            return true;
        }

        if ( $state['current_turn'] !== $player_seat ) {
            return new WP_Error( 'not_your_turn', __( 'It is not your turn.', 'shortcode-arcade' ) );
        }

        if ( $state['phase'] === 'bidding' ) {
            $bid = $move['bid'] ?? null;
            if ( $bid === null ) {
                return new WP_Error( 'no_bid', __( 'No bid specified.', 'shortcode-arcade' ) );
            }
            // Accept 'nil' or numeric 1-14 (14 tricks possible with jokers)
            if ( $bid === 'nil' || ( is_numeric( $bid ) && $bid >= 1 && $bid <= 14 ) ) {
                return true;
            }
            return new WP_Error( 'invalid_bid', __( 'Bid must be nil or 1-14.', 'shortcode-arcade' ) );
        }

        if ( $state['phase'] === 'playing' ) {
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

            $is_joker = $card['suit'] === 'joker';
            $is_leading = empty( $state['trick'] );

            // Joker leading restriction: can only lead joker if you have ONLY jokers
            if ( $is_leading && $is_joker ) {
                $non_jokers = array_filter( $hand, fn( $c ) => $c['suit'] !== 'joker' );
                if ( ! empty( $non_jokers ) ) {
                    return new WP_Error( 'cannot_lead_joker', __( 'You cannot lead with a Joker unless you only have Jokers.', 'shortcode-arcade' ) );
                }
            }

            // Must follow suit if possible (jokers don't satisfy follow-suit)
            if ( ! empty( $state['trick'] ) ) {
                $lead_suit = $state['trick'][0]['card']['suit'];
                // Jokers don't count as following suit
                if ( $lead_suit !== 'joker' && $this->has_suit( $hand, $lead_suit ) && $card['suit'] !== $lead_suit ) {
                    return new WP_Error( 'must_follow', __( 'You must follow suit.', 'shortcode-arcade' ) );
                }
            }

            return true;
        }

        return new WP_Error( 'invalid_phase', __( 'Cannot make moves in this phase.', 'shortcode-arcade' ) );
    }

    public function apply_move( array $state, int $player_seat, array $move ): array {
        // Handle round continuation
        if ( $state['phase'] === 'round_end' && isset( $move['action'] ) && $move['action'] === 'next_round' ) {
            $state['dealer'] = ( $state['dealer'] + 1 ) % 4;
            $state['round_number']++;
            return $this->deal_or_setup( $state );
        }

        if ( $state['phase'] === 'bidding' ) {
            $bid = $move['bid'];
            if ( $bid === 'nil' ) {
                $state['bids'][ $player_seat ] = 0;
                $state['nil_bids'][ $player_seat ] = true;
            } else {
                $state['bids'][ $player_seat ] = absint( $bid );
                $state['nil_bids'][ $player_seat ] = false;
            }

            // Check if all bids are in
            if ( ! in_array( null, $state['bids'], true ) ) {
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
            $state['trick'][] = [
                'seat' => $player_seat,
                'card' => $card,
            ];

            // Resolve trick when complete
            if ( count( $state['trick'] ) === 4 ) {
                $state = $this->resolve_trick( $state );
            }
        }

        return $state;
    }

    private function resolve_trick( array $state ): array {
        $trick = $state['trick'];

        // Check if ALL cards are jokers
        $all_jokers = true;
        foreach ( $trick as $play ) {
            if ( $play['card']['suit'] !== 'joker' ) {
                $all_jokers = false;
                break;
            }
        }

        if ( $all_jokers ) {
            // First joker played wins
            $winner_seat = $trick[0]['seat'];
        } else {
            // Normal trick resolution
            $winner_seat = $this->find_trick_winner_seat( $trick );
        }

        $winner_team = $winner_seat % 2;
        $state['tricks_won'][ $winner_seat ]++;

        // Count diamonds and jokers captured by winning team
        $diamonds_in_trick = 0;
        $jokers_in_trick = 0;
        foreach ( $trick as $play ) {
            if ( $play['card']['suit'] === 'diamonds' ) {
                $diamonds_in_trick++;
            } elseif ( $play['card']['suit'] === 'joker' ) {
                $jokers_in_trick++;
            }
        }

        // Add penalties to winning team
        $state['diamonds_captured'][ $winner_team ] += $diamonds_in_trick;
        $state['jokers_captured'][ $winner_team ] += $jokers_in_trick;

        $state['trick'] = [];
        $state['trick_leader'] = $winner_seat;

        if ( $this->is_round_over( $state ) ) {
            $state['phase'] = 'round_end';
            $state = $this->score_round( $state );
        }

        return $state;
    }

    /**
     * Find the winner of a trick (excluding all-joker case)
     * Jokers always lose to any non-joker card
     */
    private function find_trick_winner_seat( array $trick ): int {
        // Find the lead suit (first non-joker, or joker if leader played joker)
        $lead_suit = null;
        foreach ( $trick as $play ) {
            if ( $play['card']['suit'] !== 'joker' ) {
                $lead_suit = $play['card']['suit'];
                break;
            }
        }

        // If no non-joker found (shouldn't happen in non-all-joker case), use first card's suit
        if ( $lead_suit === null ) {
            $lead_suit = $trick[0]['card']['suit'];
        }

        $winner_seat = null;
        $winner_value = -1;
        $winner_is_trump = false;

        foreach ( $trick as $play ) {
            $card = $play['card'];

            // Jokers always lose - skip them
            if ( $card['suit'] === 'joker' ) {
                continue;
            }

            $is_trump = $card['suit'] === 'diamonds';
            $value = $this->get_card_value( $card );

            if ( $winner_seat === null ) {
                // First non-joker card
                $winner_seat = $play['seat'];
                $winner_value = $value;
                $winner_is_trump = $is_trump;
            } elseif ( $is_trump && ! $winner_is_trump ) {
                // Trump beats non-trump
                $winner_seat = $play['seat'];
                $winner_value = $value;
                $winner_is_trump = true;
            } elseif ( $is_trump === $winner_is_trump ) {
                // Same type - compare values
                if ( $is_trump || $card['suit'] === $lead_suit ) {
                    if ( $value > $winner_value ) {
                        $winner_seat = $play['seat'];
                        $winner_value = $value;
                    }
                }
            }
        }

        return $winner_seat;
    }

    private function is_round_over( array $state ): bool {
        foreach ( $state['hands'] as $hand ) {
            if ( ! empty( $hand ) ) {
                return false;
            }
        }
        return true;
    }

    public function advance_turn( array $state ): array {
        if ( $state['phase'] === 'bidding' ) {
            $state['current_turn'] = ( $state['current_turn'] + 1 ) % 4;
        } elseif ( $state['phase'] === 'playing' ) {
            if ( empty( $state['trick'] ) ) {
                $state['current_turn'] = $state['trick_leader'];
            } else {
                $state['current_turn'] = ( $state['current_turn'] + 1 ) % 4;
            }
        }
        return $state;
    }

    public function check_end_condition( array $state ): array {
        if ( $state['phase'] !== 'round_end' ) {
            return [ 'ended' => false, 'reason' => null, 'winners' => null ];
        }

        foreach ( $state['team_scores'] as $team => $score ) {
            if ( $score >= self::WIN_SCORE ) {
                return [ 'ended' => true, 'reason' => 'win_score', 'winners' => $state['teams'][ $team ] ];
            }
            if ( $score <= self::LOSE_SCORE ) {
                return [ 'ended' => true, 'reason' => 'lose_score', 'winners' => $state['teams'][ $team === 0 ? 1 : 0 ] ];
            }
        }

        return [ 'ended' => false, 'reason' => null, 'winners' => null ];
    }

    public function score_round( array $state ): array {
        $round_details = [ [], [] ]; // Track scoring details for each team

        foreach ( [ 0, 1 ] as $team ) {
            $seats = $state['teams'][ $team ];
            $team_bid = 0;
            $team_tricks = 0;
            $nil_results = [];

            foreach ( $seats as $seat ) {
                $bid = $state['bids'][ $seat ];
                $tricks = $state['tricks_won'][ $seat ];
                $is_nil = $state['nil_bids'][ $seat ] === true;

                if ( $is_nil ) {
                    // Nil bid
                    $nil_results[] = $tricks === 0 ? self::NIL_BONUS : -self::NIL_PENALTY;
                } else {
                    $team_bid += $bid;
                    $team_tricks += $tricks;
                }
            }

            $round_score = 0;
            $diamonds_captured = $state['diamonds_captured'][ $team ];
            $diamonds_penalty = $diamonds_captured;
            $jokers_penalty = $state['jokers_captured'][ $team ];

            // Check for Soft Moon (10+ diamonds captured)
            $soft_moon = $diamonds_penalty >= self::SOFT_MOON_THRESHOLD;
            if ( $soft_moon ) {
                // Option: Remove all diamond penalties for the hand OR +50 points
                // Let's give +50 bonus and remove penalties (best of both interpretations)
                $diamonds_penalty = 0;
                $round_score += self::SOFT_MOON_BONUS;
            }

            // Score team bid
            if ( $team_tricks >= $team_bid && $team_bid > 0 ) {
                $round_score += $team_bid * 10;
                $bags = $team_tricks - $team_bid;
                $round_score += $bags;
                $previous_bags = $state['team_bags'][ $team ];
                $new_bags = $previous_bags + $bags;
                $bag_penalty_applied = false;

                // Bag penalty
                if ( $new_bags >= self::BAG_PENALTY_THRESHOLD ) {
                    $round_score -= self::BAG_PENALTY;
                    $new_bags -= self::BAG_PENALTY_THRESHOLD;
                    $bag_penalty_applied = true;
                }
                $state['team_bags'][ $team ] = $new_bags;
            } elseif ( $team_bid > 0 ) {
                // Failed to make bid
                $round_score -= $team_bid * 10;
                $bags = 0;
                $bag_penalty_applied = false;
            } else {
                $bags = 0;
                $bag_penalty_applied = false;
            }

            // Add nil bonuses/penalties
            foreach ( $nil_results as $nil_score ) {
                $round_score += $nil_score;
            }

            // Apply diamond penalties
            $round_score -= $diamonds_penalty;

            // Apply joker penalties
            $round_score -= $jokers_penalty;

            // Store round details
            $round_details[ $team ] = [
                'bid_score'        => ( $team_tricks >= $team_bid && $team_bid > 0 ) ? $team_bid * 10 : ( $team_bid > 0 ? -$team_bid * 10 : 0 ),
                'bags'             => $bags,
                'nil_bonus'        => array_sum( array_filter( $nil_results, fn( $n ) => $n > 0 ) ),
                'nil_penalty'      => abs( array_sum( array_filter( $nil_results, fn( $n ) => $n < 0 ) ) ),
                'diamonds_penalty' => $diamonds_penalty,
                'diamonds_captured' => $diamonds_captured,
                'jokers_penalty'   => $state['jokers_captured'][ $team ],
                'soft_moon'        => $soft_moon,
                'soft_moon_bonus'  => $soft_moon ? self::SOFT_MOON_BONUS : 0,
                'bag_penalty'      => $bag_penalty_applied ? self::BAG_PENALTY : 0,
                'round_total'      => $round_score,
            ];

            $state['team_scores'][ $team ] += $round_score;
        }

        $state['round_details'] = $round_details;

        return $state;
    }

    public function ai_move( array $state, int $player_seat, string $difficulty = 'normal' ): array {
        if ( $state['phase'] === 'round_end' ) {
            // Don't auto-advance - let human players see the scores
            return [];
        }

        if ( $state['phase'] === 'bidding' ) {
            return $this->ai_bid( $state, $player_seat, $difficulty );
        }

        $valid = $this->get_valid_moves( $state, $player_seat );
        if ( empty( $valid ) ) {
            return [];
        }

        return $this->ai_play_card( $state, $player_seat, $valid, $difficulty );
    }

    private function ai_bid( array $state, int $player_seat, string $difficulty ): array {
        $hand = $state['hands'][ $player_seat ];
        $tricks = 0;

        // Exclude jokers from trick calculation (they never win)
        $non_jokers = array_filter( $hand, fn( $c ) => $c['suit'] !== 'joker' );

        // Count non-diamond high cards
        foreach ( $non_jokers as $card ) {
            if ( $card['suit'] !== 'diamonds' ) {
                if ( $card['rank'] === 'A' ) {
                    $tricks++;
                } elseif ( $card['rank'] === 'K' ) {
                    $tricks += 0.7;
                } elseif ( $card['rank'] === 'Q' ) {
                    $tricks += 0.3;
                }
            }
        }

        // Diamonds are trump but carry penalties - count cautiously
        $diamonds = array_filter( $hand, fn( $c ) => $c['suit'] === 'diamonds' );
        $diamond_count = count( $diamonds );

        foreach ( $diamonds as $diamond ) {
            $value = $this->get_card_value( $diamond );
            if ( $value >= 14 ) { // Ace
                $tricks += 0.8; // Reduced because of penalty
            } elseif ( $value >= 13 ) { // King
                $tricks += 0.5;
            }
        }

        // Reduce bid if many diamonds (high penalty risk)
        if ( $diamond_count >= 5 ) {
            $tricks *= 0.8;
        }

        // Check for void suits (can trump)
        $suits = [ 'spades', 'hearts', 'clubs' ];
        foreach ( $suits as $suit ) {
            if ( ! $this->has_suit( $non_jokers, $suit ) && $diamond_count > 0 ) {
                $tricks += 0.5;
            }
        }

        $high_cards = array_filter( $non_jokers, fn( $c ) => in_array( $c['rank'], [ 'A', 'K', 'Q', 'J' ], true ) );

        if ( $tricks < 0.8 && $diamond_count <= 1 && count( $high_cards ) <= 1 ) {
            return [ 'bid' => 'nil' ];
        }

        $bid = max( 1, (int) round( $tricks ) );

        // Add randomness for easier difficulties
        if ( $difficulty === 'easy' ) {
            $bid = max( 1, min( 14, $bid + wp_rand( -2, 1 ) ) );
        } elseif ( $difficulty === 'normal' ) {
            $bid = max( 1, min( 14, $bid + wp_rand( -1, 1 ) ) );
        }

        return [ 'bid' => $bid ];
    }

    private function ai_play_card( array $state, int $player_seat, array $valid, string $difficulty ): array {
        $hand = $state['hands'][ $player_seat ];
        $trick = $state['trick'];

        // Random play for easy difficulty sometimes
        if ( $difficulty === 'easy' && wp_rand( 1, 100 ) <= 30 ) {
            $move = $valid[ array_rand( $valid ) ];
            return [ 'card_id' => $move['card_id'] ];
        }

        // Leading
        if ( empty( $trick ) ) {
            return $this->ai_lead( $hand, $difficulty );
        }

        // Following
        return $this->ai_follow( $state, $hand, $player_seat, $difficulty );
    }

    private function ai_lead( array $hand, string $difficulty ): array {
        // Cannot lead with jokers unless only have jokers
        $non_jokers = array_filter( $hand, fn( $c ) => $c['suit'] !== 'joker' );

        if ( empty( $non_jokers ) ) {
            // Only have jokers - must lead with one
            return [ 'card_id' => $hand[0]['id'] ];
        }

        // Prefer to lead with non-diamonds, non-jokers to avoid penalty
        $safe_cards = array_filter( $non_jokers, fn( $c ) => $c['suit'] !== 'diamonds' );

        if ( ! empty( $safe_cards ) ) {
            // Lead with middle-low cards to avoid giving away tricks
            $safe_cards = array_values( $safe_cards );
            usort( $safe_cards, fn( $a, $b ) => $this->get_card_value( $a ) - $this->get_card_value( $b ) );

            // For hard difficulty, consider leading high to pull out trumps
            if ( $difficulty === 'hard' && wp_rand( 1, 100 ) <= 40 ) {
                return [ 'card_id' => end( $safe_cards )['id'] ];
            }

            // Lead with lower-middle card
            $index = min( count( $safe_cards ) - 1, 2 );
            return [ 'card_id' => $safe_cards[ $index ]['id'] ];
        }

        // Only have diamonds (and maybe jokers) - lead lowest diamond
        $diamonds = array_values( array_filter( $non_jokers, fn( $c ) => $c['suit'] === 'diamonds' ) );
        usort( $diamonds, fn( $a, $b ) => $this->get_card_value( $a ) - $this->get_card_value( $b ) );
        return [ 'card_id' => $diamonds[0]['id'] ];
    }

    private function ai_follow( array $state, array $hand, int $player_seat, string $difficulty ): array {
        $trick = $state['trick'];
        $lead_suit = $trick[0]['card']['suit'];

        // If lead suit is joker (rare - only when leader had only jokers), anyone can play anything
        // Jokers don't satisfy follow-suit, so check for non-joker lead suit cards
        $suit_cards = ( $lead_suit !== 'joker' )
            ? array_filter( $hand, fn( $c ) => $c['suit'] === $lead_suit )
            : [];

        if ( ! empty( $suit_cards ) ) {
            // Must follow suit
            $suit_cards = array_values( $suit_cards );
            usort( $suit_cards, fn( $a, $b ) => $this->get_card_value( $a ) - $this->get_card_value( $b ) );

            // If we're winning the trick, play low
            // If we need to win, play high enough to win
            $current_winner = $this->find_trick_winner( $trick );
            $winning_value = $this->get_card_value( $current_winner['card'] );
            $is_trump_winning = $current_winner['card']['suit'] === 'diamonds';

            if ( $lead_suit === 'diamonds' ) {
                // Following with diamonds (penalties)
                // Try to play just high enough to win if partner isn't winning
                $partner_seat = ( $player_seat + 2 ) % 4;
                $partner_winning = $current_winner['seat'] === $partner_seat;

                if ( $partner_winning ) {
                    // Play lowest diamond
                    return [ 'card_id' => $suit_cards[0]['id'] ];
                }

                // Try to win with minimum diamond
                foreach ( $suit_cards as $card ) {
                    if ( $this->get_card_value( $card ) > $winning_value ) {
                        return [ 'card_id' => $card['id'] ];
                    }
                }

                // Can't win, play lowest
                return [ 'card_id' => $suit_cards[0]['id'] ];
            } else {
                // Following with non-diamond suit
                if ( ! $is_trump_winning ) {
                    // Check if partner is winning
                    $partner_seat = ( $player_seat + 2 ) % 4;
                    $partner_winning = $current_winner['seat'] === $partner_seat;

                    if ( $partner_winning ) {
                        // Play lowest
                        return [ 'card_id' => $suit_cards[0]['id'] ];
                    }

                    // Try to win with minimum card
                    foreach ( $suit_cards as $card ) {
                        if ( $this->get_card_value( $card ) > $winning_value ) {
                            return [ 'card_id' => $card['id'] ];
                        }
                    }
                }

                // Trump is winning or can't beat, play lowest
                return [ 'card_id' => $suit_cards[0]['id'] ];
            }
        }

        // Can't follow suit - can play anything (jokers, diamonds, or off-suit)
        // Priority: dump jokers first (they're penalty cards that can't win)
        $jokers = array_filter( $hand, fn( $c ) => $c['suit'] === 'joker' );
        if ( ! empty( $jokers ) ) {
            // Dump a joker - they always lose and carry penalties
            $jokers = array_values( $jokers );
            return [ 'card_id' => $jokers[0]['id'] ];
        }

        // No jokers - try to play non-diamonds (avoid trump penalty)
        $non_diamonds = array_filter( $hand, fn( $c ) => $c['suit'] !== 'diamonds' && $c['suit'] !== 'joker' );

        if ( ! empty( $non_diamonds ) ) {
            // Dump lowest non-diamond
            $non_diamonds = array_values( $non_diamonds );
            usort( $non_diamonds, fn( $a, $b ) => $this->get_card_value( $a ) - $this->get_card_value( $b ) );
            return [ 'card_id' => $non_diamonds[0]['id'] ];
        }

        // Only have diamonds - check if we need to win
        $diamonds = array_values( array_filter( $hand, fn( $c ) => $c['suit'] === 'diamonds' ) );
        usort( $diamonds, fn( $a, $b ) => $this->get_card_value( $a ) - $this->get_card_value( $b ) );

        $current_winner = $this->find_trick_winner( $trick );
        $partner_seat = ( $player_seat + 2 ) % 4;
        $partner_winning = $current_winner['seat'] === $partner_seat;

        if ( $partner_winning ) {
            // Play lowest diamond
            return [ 'card_id' => $diamonds[0]['id'] ];
        }

        // Trump to win - play lowest winning diamond
        $is_trump_winning = $current_winner['card']['suit'] === 'diamonds';

        if ( $is_trump_winning ) {
            $winning_value = $this->get_card_value( $current_winner['card'] );
            foreach ( $diamonds as $card ) {
                if ( $this->get_card_value( $card ) > $winning_value ) {
                    return [ 'card_id' => $card['id'] ];
                }
            }
            // Can't beat their trump, play lowest
            return [ 'card_id' => $diamonds[0]['id'] ];
        }

        // No trump yet, play lowest diamond to win
        return [ 'card_id' => $diamonds[0]['id'] ];
    }

    /**
     * Find current trick winner (for AI decision making during partial tricks)
     * Handles jokers - they always lose to non-jokers
     */
    private function find_trick_winner( array $trick ): array {
        // Check if all cards are jokers
        $all_jokers = true;
        foreach ( $trick as $play ) {
            if ( $play['card']['suit'] !== 'joker' ) {
                $all_jokers = false;
                break;
            }
        }

        if ( $all_jokers ) {
            // First joker wins
            return $trick[0];
        }

        // Find lead suit (first non-joker)
        $lead_suit = null;
        foreach ( $trick as $play ) {
            if ( $play['card']['suit'] !== 'joker' ) {
                $lead_suit = $play['card']['suit'];
                break;
            }
        }

        $winner = null;
        $winner_is_trump = false;

        foreach ( $trick as $play ) {
            // Skip jokers - they always lose
            if ( $play['card']['suit'] === 'joker' ) {
                continue;
            }

            $is_trump = $play['card']['suit'] === 'diamonds';
            $value = $this->get_card_value( $play['card'] );

            if ( $winner === null ) {
                $winner = $play;
                $winner_is_trump = $is_trump;
            } elseif ( $is_trump && ! $winner_is_trump ) {
                $winner = $play;
                $winner_is_trump = true;
            } elseif ( $is_trump === $winner_is_trump ) {
                if ( $is_trump || $play['card']['suit'] === $lead_suit ) {
                    if ( $value > $this->get_card_value( $winner['card'] ) ) {
                        $winner = $play;
                    }
                }
            }
        }

        return $winner ?? $trick[0];
    }

    public function get_valid_moves( array $state, int $player_seat ): array {
        if ( $state['phase'] === 'round_end' ) {
            return [ [ 'action' => 'next_round' ] ];
        }

        if ( $state['phase'] === 'bidding' && $state['current_turn'] === $player_seat ) {
            $bids = [ [ 'bid' => 'nil' ] ];
            for ( $i = 1; $i <= 14; $i++ ) { // 14 tricks possible with 56 cards
                $bids[] = [ 'bid' => $i ];
            }
            return $bids;
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

        return $public;
    }
}
