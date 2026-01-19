<?php
/**
 * Rummy Game Implementation
 *
 * Classic Rummy card game for 2-4 players.
 *
 * Rules:
 * - 2-4 players, standard 52-card deck
 * - Deal: 10 cards (2 players) or 7 cards (3-4 players)
 * - Turn: Draw from deck or discard pile, then discard one card
 * - Goal: Form melds - Sets (3-4 of same rank) or Runs (3+ consecutive cards of same suit)
 * - Win: Meld all cards and go out
 * - Scoring: Remaining cards = penalty points (Face cards=10, Ace=1, numbers=face value)
 * - Game ends when a player reaches 100 penalty points
 *
 * @package ClassicGamesArcade
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rummy game class
 */
class SACGA_Game_Rummy extends SACGA_Game_Contract {
    use SACGA_Card_Game_Trait;

    /**
     * Game ID
     *
     * @var string
     */
    protected $id = 'rummy';

    /**
     * Game display name
     *
     * @var string
     */
    protected $name = 'Rummy';

    /**
     * Game type
     *
     * @var string
     */
    protected $type = 'card';

    /**
     * Minimum players
     *
     * @var int
     */
    protected $min_players = 2;

    /**
     * Maximum players
     *
     * @var int
     */
    protected $max_players = 4;

    /**
     * Has teams
     *
     * @var bool
     */
    protected $has_teams = false;

    /**
     * AI supported
     *
     * @var bool
     */
    protected $ai_supported = true;

    /**
     * Maximum penalty score before game ends
     */
    const MAX_SCORE = 100;

    /**
     * Register game metadata
     *
     * @return array
     */
    public function register_game(): array {
        return array(
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'min_players' => $this->min_players,
            'max_players' => $this->max_players,
            'has_teams' => $this->has_teams,
            'ai_supported' => $this->ai_supported,
            'description' => __( 'Classic Rummy card game. Form sets and runs to go out with the lowest score.', 'shortcode-arcade' ),
            'rules' => array(
                'objective' => __( 'Be the first to meld all your cards into valid sets and runs, or have the lowest penalty points when the game ends.', 'shortcode-arcade' ),
                'setup'     => __( "2-4 players using a standard 52-card deck.\n10 cards dealt to each player (2 players) or 7 cards (3-4 players).\nRemaining cards form the draw pile; top card starts the discard pile.", 'shortcode-arcade' ),
                'gameplay'  => __( "On your turn: Draw one card from either the draw pile or discard pile.\nForm melds in your hand: Sets (3-4 cards of the same rank) or Runs (3+ consecutive cards of the same suit).\nLay down completed melds on the table when ready.\nEnd your turn by discarding one card to the discard pile.", 'shortcode-arcade' ),
                'winning'   => __( 'Go out by melding all cards. Other players score penalty points for cards remaining in hand. First player to 100 penalty points loses.', 'shortcode-arcade' ),
                'notes'     => __( 'Card values: Face cards = 10 points, Aces = 1 point, Number cards = face value.', 'shortcode-arcade' ),
            ),
        );
    }

    /**
     * Initialize game state
     *
     * @param array $players Array of player data.
     * @param array $settings Optional game settings.
     * @return array Initial game state.
     */
    public function init_state(array $players, array $settings = array()): array {
        $num_players = count($players);

        $state = array(
            'phase' => 'dealing',
            'current_turn' => 0,
            'players' => array(),
            'hands' => array(),
            'melds' => array(), // Each player's laid down melds
            'deck' => array(),
            'discard_pile' => array(),
            'scores' => array(),
            'round_number' => 1,
            'game_over' => false,
            'winner' => null,
            'last_draw_source' => null, // 'deck' or 'discard'
            'must_discard' => false, // Track if player has drawn and must discard
        );

        // Initialize players
        foreach ($players as $seat => $player) {
            $state['players'][$seat] = array(
                'name' => $player['display_name'],
                'is_ai' => $player['is_ai'],
            );
            $state['hands'][$seat] = array();
            $state['melds'][$seat] = array();
            $state['scores'][$seat] = 0;
        }

        return $state;
    }

    /**
     * Deal cards or setup board
     *
     * @param array $state Current game state.
     * @return array Updated state.
     */
    public function deal_or_setup(array $state): array {
        $num_players = count($state['players']);

        // Create and shuffle deck
        $state['deck'] = $this->create_standard_deck();
        shuffle($state['deck']);

        // Deal cards: 10 for 2 players, 7 for 3-4 players
        $cards_per_player = ($num_players === 2) ? 10 : 7;

        for ($i = 0; $i < $cards_per_player; $i++) {
            foreach ($state['hands'] as $seat => &$hand) {
                $hand[] = array_shift($state['deck']);
            }
        }

        // Put first card face up in discard pile
        $state['discard_pile'] = array(array_shift($state['deck']));

        // First player starts
        $state['current_turn'] = 0;
        $state['phase'] = 'drawing';
        $state['must_discard'] = false;

        return $state;
    }

    /**
     * Validate a player move
     *
     * @param array $state Current game state.
     * @param int $player_seat Player's seat number.
     * @param array $move Move data.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate_move(array $state, int $player_seat, array $move) {
        // Check if it's the player's turn
        if ($state['current_turn'] !== $player_seat) {
            return new WP_Error('not_your_turn', __( 'It is not your turn.', 'shortcode-arcade' ));
        }

        $action = $move['action'] ?? '';

        if ($state['phase'] === 'drawing') {
            // Player can draw from deck or discard pile
            if ($action === 'draw_deck') {
                if (empty($state['deck'])) {
                    return new WP_Error('empty_deck', __( 'The deck is empty.', 'shortcode-arcade' ));
                }
                return true;
            } elseif ($action === 'draw_discard') {
                if (empty($state['discard_pile'])) {
                    return new WP_Error('empty_discard', __( 'The discard pile is empty.', 'shortcode-arcade' ));
                }
                return true;
            } else {
                return new WP_Error('invalid_action', __( 'You must draw a card.', 'shortcode-arcade' ));
            }
        } elseif ($state['phase'] === 'discarding') {
            // Player must discard a card
            if ($action === 'discard') {
                $card_id = $move['card_id'] ?? null;
                if (!$card_id) {
                    return new WP_Error('no_card', __( 'You must specify a card to discard.', 'shortcode-arcade' ));
                }

                // Check if player has the card
                if (!$this->has_card($state['hands'][$player_seat], $card_id)) {
                    return new WP_Error('invalid_card', __( 'You do not have that card.', 'shortcode-arcade' ));
                }

                return true;
            } elseif ($action === 'meld') {
                // Player wants to lay down melds
                $melds = $move['melds'] ?? array();
                if (empty($melds)) {
                    return new WP_Error('no_melds', __( 'You must specify melds.', 'shortcode-arcade' ));
                }

                // Validate each meld
                foreach ($melds as $meld) {
                    $validation = $this->validate_meld($meld, $state['hands'][$player_seat]);
                    if (is_wp_error($validation)) {
                        return $validation;
                    }
                }

                return true;
            } elseif ($action === 'go_out') {
                // Player wants to go out
                $melds = $move['melds'] ?? array();
                $final_discard = $move['final_discard'] ?? null;

                // Collect all cards in melds
                $melded_cards = array();
                foreach ($melds as $meld) {
                    $melded_cards = array_merge($melded_cards, $meld);
                }

                // Check if all cards (minus optional final discard) are melded
                $hand = $state['hands'][$player_seat];
                $hand_count = count($hand);
                $meld_count = count($melded_cards);

                if ($final_discard) {
                    // Going out with a discard
                    if ($meld_count !== $hand_count - 1) {
                        return new WP_Error('incomplete_meld', __( 'You must meld all cards except one to go out.', 'shortcode-arcade' ));
                    }
                    if (!$this->has_card($hand, $final_discard)) {
                        return new WP_Error('invalid_discard', __( 'Invalid discard card.', 'shortcode-arcade' ));
                    }
                } else {
                    // Going out without a discard (all cards melded)
                    if ($meld_count !== $hand_count) {
                        return new WP_Error('incomplete_meld', __( 'You must meld all cards to go out without discarding.', 'shortcode-arcade' ));
                    }
                }

                // Validate all melds
                foreach ($melds as $meld) {
                    $validation = $this->validate_meld($meld, $hand);
                    if (is_wp_error($validation)) {
                        return $validation;
                    }
                }

                return true;
            } else {
                return new WP_Error('invalid_action', __( 'Invalid action for discarding phase.', 'shortcode-arcade' ));
            }
        }

        return new WP_Error('invalid_phase', __( 'Invalid game phase.', 'shortcode-arcade' ));
    }

    /**
     * Check if hand contains a card with given ID
     *
     * @param array $hand Player's hand.
     * @param string $card_id Card ID to check.
     * @return bool
     */
    private function has_card($hand, $card_id) {
        foreach ($hand as $card) {
            if (isset($card['id']) && $card['id'] === $card_id) {
                return true;
            }
            // Also check constructed ID from suit and rank
            $constructed_id = $card['suit'] . '_' . $card['rank'];
            if ($constructed_id === $card_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate a meld (set or run)
     *
     * @param array $meld Array of card IDs.
     * @param array $hand Player's hand.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    private function validate_meld($meld, $hand) {
        if (count($meld) < 3) {
            return new WP_Error('meld_too_small', __( 'A meld must have at least 3 cards.', 'shortcode-arcade' ));
        }

        // Check if player has all cards
        foreach ($meld as $card_id) {
            if (!$this->has_card($hand, $card_id)) {
                return new WP_Error('missing_card', __( 'You do not have all cards in the meld.', 'shortcode-arcade' ));
            }
        }

        // Check if it's a valid set or run
        $is_set = $this->is_valid_set($meld);
        $is_run = $this->is_valid_run($meld);

        if (!$is_set && !$is_run) {
            return new WP_Error('invalid_meld', __( 'Meld must be a valid set or run.', 'shortcode-arcade' ));
        }

        return true;
    }

    /**
     * Check if cards form a valid set (same rank)
     *
     * @param array $cards Array of card IDs.
     * @return bool
     */
    private function is_valid_set($cards) {
        if (count($cards) < 3 || count($cards) > 4) {
            return false;
        }

        $ranks = array();
        foreach ($cards as $card_id) {
            $parts = explode('_', $card_id);
            $ranks[] = $parts[1];
        }

        // All ranks must be the same
        return count(array_unique($ranks)) === 1;
    }

    /**
     * Check if cards form a valid run (consecutive same suit)
     *
     * @param array $cards Array of card IDs.
     * @return bool
     */
    private function is_valid_run($cards) {
        if (count($cards) < 3) {
            return false;
        }

        $suits = array();
        $ranks = array();
        $rank_values = array(
            'A' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7,
            '8' => 8, '9' => 9, '10' => 10, 'J' => 11, 'Q' => 12, 'K' => 13,
        );

        foreach ($cards as $card_id) {
            $parts = explode('_', $card_id);
            $suits[] = $parts[0];
            $ranks[] = $rank_values[$parts[1]];
        }

        // All suits must be the same
        if (count(array_unique($suits)) !== 1) {
            return false;
        }

        // Sort ranks
        sort($ranks);

        // Check if consecutive
        for ($i = 1; $i < count($ranks); $i++) {
            if ($ranks[$i] !== $ranks[$i - 1] + 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply a validated move
     *
     * @param array $state Current game state.
     * @param int $player_seat Player's seat number.
     * @param array $move Move data.
     * @return array Updated state.
     */
    public function apply_move(array $state, int $player_seat, array $move): array {
        $action = $move['action'] ?? '';

        if ($state['phase'] === 'drawing') {
            if ($action === 'draw_deck') {
                $card = array_shift($state['deck']);
                $state['hands'][$player_seat][] = $card;
                $state['last_draw_source'] = 'deck';
                $state['phase'] = 'discarding';
                $state['must_discard'] = true;
            } elseif ($action === 'draw_discard') {
                $card = array_pop($state['discard_pile']);
                $state['hands'][$player_seat][] = $card;
                $state['last_draw_source'] = 'discard';
                $state['phase'] = 'discarding';
                $state['must_discard'] = true;
            }
        } elseif ($state['phase'] === 'discarding') {
            if ($action === 'discard') {
                $card_id = $move['card_id'];
                $state['hands'][$player_seat] = $this->remove_card($state['hands'][$player_seat], $card_id);
                $card = $this->find_card_by_id($card_id);
                $state['discard_pile'][] = $card;
                $state['must_discard'] = false;
                $state['phase'] = 'drawing';
            } elseif ($action === 'meld') {
                // Lay down melds (but don't go out)
                $melds = $move['melds'];
                foreach ($melds as $meld) {
                    $state['melds'][$player_seat][] = $meld;
                    foreach ($meld as $card_id) {
                        $state['hands'][$player_seat] = $this->remove_card($state['hands'][$player_seat], $card_id);
                    }
                }
                // Player still needs to discard
            } elseif ($action === 'go_out') {
                // Player is going out
                $melds = $move['melds'];
                $final_discard = $move['final_discard'] ?? null;

                // Lay down all melds
                foreach ($melds as $meld) {
                    $state['melds'][$player_seat][] = $meld;
                    foreach ($meld as $card_id) {
                        $state['hands'][$player_seat] = $this->remove_card($state['hands'][$player_seat], $card_id);
                    }
                }

                // Discard final card if provided
                if ($final_discard) {
                    $state['hands'][$player_seat] = $this->remove_card($state['hands'][$player_seat], $final_discard);
                    $card = $this->find_card_by_id($final_discard);
                    $state['discard_pile'][] = $card;
                }

                $state['phase'] = 'round_end';
                $state['winner'] = $player_seat;
            }
        }

        return $state;
    }

    /**
     * Find card by ID
     *
     * @param string $card_id Card ID (e.g., 'hearts_K').
     * @return array Card data.
     */
    private function find_card_by_id($card_id) {
        $parts = explode('_', $card_id);
        return array(
            'id' => $card_id,
            'suit' => $parts[0],
            'rank' => $parts[1],
        );
    }

    /**
     * Advance to next player's turn
     *
     * @param array $state Current game state.
     * @return array Updated state.
     */
    public function advance_turn(array $state): array {
        if ($state['phase'] === 'drawing') {
            // Move to next player
            $num_players = count($state['players']);
            $state['current_turn'] = ($state['current_turn'] + 1) % $num_players;
        }

        return $state;
    }

    /**
     * Check if game or round has ended
     *
     * @param array $state Current game state.
     * @return array End condition status.
     */
    public function check_end_condition(array $state): array {
        if ($state['phase'] !== 'round_end') {
            return array('ended' => false, 'reason' => null, 'winners' => null);
        }

        if ($state['game_over']) {
            return array(
                'ended' => true,
                'reason' => 'win_score',
                'winners' => array($state['winner']),
            );
        }

        // Round ended but game continues
        return array('ended' => true, 'reason' => 'round_end', 'winners' => array($state['winner']));
    }

    /**
     * Score the round
     *
     * @param array $state Current game state.
     * @return array Updated state with scores.
     */
    public function score_round(array $state): array {
        $card_values = array(
            'A' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7,
            '8' => 8, '9' => 9, '10' => 10, 'J' => 10, 'Q' => 10, 'K' => 10,
        );

        // Calculate penalty points for each player
        foreach ($state['players'] as $seat => $player) {
            $penalty = 0;
            foreach ($state['hands'][$seat] as $card) {
                $penalty += $card_values[$card['rank']];
            }
            $state['scores'][$seat] += $penalty;
        }

        // Check if game is over (someone reached max score)
        foreach ($state['scores'] as $seat => $score) {
            if ($score >= self::MAX_SCORE) {
                $state['game_over'] = true;
                // Winner is player with lowest score
                $min_score = min($state['scores']);
                foreach ($state['scores'] as $s => $sc) {
                    if ($sc === $min_score) {
                        $state['winner'] = $s;
                        break;
                    }
                }
                return $state;
            }
        }

        // Start new round
        $state['round_number']++;
        $state['phase'] = 'dealing';
        $state['current_turn'] = $state['round_number'] % count($state['players']);

        // Clear hands and melds
        foreach ($state['players'] as $seat => $player) {
            $state['hands'][$seat] = array();
            $state['melds'][$seat] = array();
        }

        $state = $this->deal_or_setup($state);

        return $state;
    }

    /**
     * Generate AI move
     *
     * @param array $state Current game state.
     * @param int $ai_seat AI player's seat.
     * @param string $difficulty Difficulty level.
     * @return array Move data.
     */
    public function ai_move(array $state, int $ai_seat, string $difficulty = 'beginner'): array {
        if ($state['phase'] === 'drawing') {
            // Simple AI: Draw from discard if top card can form a meld, otherwise draw from deck
            $discard_top = end($state['discard_pile']);
            $hand = $state['hands'][$ai_seat];

            // Check if discard card helps
            if ($discard_top && $this->card_helps_hand($discard_top, $hand)) {
                return array('action' => 'draw_discard');
            }

            return array('action' => 'draw_deck');
        } elseif ($state['phase'] === 'discarding') {
            $hand = $state['hands'][$ai_seat];

            // Try to find melds and go out
            $melds = $this->find_melds($hand);

            if (!empty($melds)) {
                // Check if we can go out
                $melded_cards = array();
                foreach ($melds as $meld) {
                    $melded_cards = array_merge($melded_cards, $meld);
                }

                $remaining_cards = array();
                foreach ($hand as $card) {
                    $card_id = $card['suit'] . '_' . $card['rank'];
                    if (!in_array($card_id, $melded_cards)) {
                        $remaining_cards[] = $card_id;
                    }
                }

                if (count($remaining_cards) <= 1) {
                    // Can go out!
                    return array(
                        'action' => 'go_out',
                        'melds' => $melds,
                        'final_discard' => $remaining_cards[0] ?? null,
                    );
                }
            }

            // Just discard highest value card
            $highest_card = $this->find_highest_card($hand);
            return array(
                'action' => 'discard',
                'card_id' => $highest_card['suit'] . '_' . $highest_card['rank'],
            );
        }

        return array('action' => 'pass');
    }

    /**
     * Check if a card helps the hand form melds
     *
     * @param array $card Card to check.
     * @param array $hand Current hand.
     * @return bool
     */
    private function card_helps_hand($card, $hand) {
        // Simple heuristic: check if card matches rank or suit of existing cards
        $card_rank = $card['rank'];
        $card_suit = $card['suit'];

        $rank_count = 0;
        foreach ($hand as $c) {
            if ($c['rank'] === $card_rank) {
                $rank_count++;
            }
        }

        // If we have 2+ of the same rank, this card completes a set
        if ($rank_count >= 2) {
            return true;
        }

        // Check for potential runs (simplified)
        foreach ($hand as $c) {
            if ($c['suit'] === $card_suit) {
                return true; // Might help form a run
            }
        }

        return false;
    }

    /**
     * Find all possible melds in a hand
     *
     * @param array $hand Player's hand.
     * @return array Array of melds.
     */
    private function find_melds($hand) {
        $rank_values = array(
            'A' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7,
            '8' => 8, '9' => 9, '10' => 10, 'J' => 11, 'Q' => 12, 'K' => 13,
        );

        // Find all possible sets
        $all_sets = array();
        $by_rank = array();
        foreach ($hand as $card) {
            $rank = $card['rank'];
            if (!isset($by_rank[$rank])) {
                $by_rank[$rank] = array();
            }
            $by_rank[$rank][] = $card['suit'] . '_' . $card['rank'];
        }

        foreach ($by_rank as $rank => $cards) {
            if (count($cards) >= 3) {
                $all_sets[] = $cards;
            }
        }

        // Find all possible runs
        $all_runs = array();
        $by_suit = array();
        foreach ($hand as $card) {
            $suit = $card['suit'];
            if (!isset($by_suit[$suit])) {
                $by_suit[$suit] = array();
            }
            $by_suit[$suit][] = $card;
        }

        foreach ($by_suit as $suit => $cards) {
            if (count($cards) >= 3) {
                // Sort by rank
                usort($cards, function($a, $b) use ($rank_values) {
                    return $rank_values[$a['rank']] - $rank_values[$b['rank']];
                });

                // Find consecutive runs
                $run = array($cards[0]['suit'] . '_' . $cards[0]['rank']);
                for ($i = 1; $i < count($cards); $i++) {
                    $prev_rank = $rank_values[$cards[$i - 1]['rank']];
                    $curr_rank = $rank_values[$cards[$i]['rank']];

                    if ($curr_rank === $prev_rank + 1) {
                        $run[] = $cards[$i]['suit'] . '_' . $cards[$i]['rank'];
                    } else {
                        if (count($run) >= 3) {
                            $all_runs[] = $run;
                        }
                        $run = array($cards[$i]['suit'] . '_' . $cards[$i]['rank']);
                    }
                }

                if (count($run) >= 3) {
                    $all_runs[] = $run;
                }
            }
        }

        // Combine all melds and sort by length (longest first)
        $all_melds = array_merge($all_sets, $all_runs);
        usort($all_melds, function($a, $b) {
            return count($b) - count($a);
        });

        // Greedy approach: select non-overlapping melds
        $used_cards = array();
        $final_melds = array();

        foreach ($all_melds as $meld) {
            // Check if any card in this meld is already used
            $has_overlap = false;
            foreach ($meld as $card_id) {
                if (in_array($card_id, $used_cards)) {
                    $has_overlap = true;
                    break;
                }
            }

            if (!$has_overlap) {
                // This meld doesn't overlap, add it
                $final_melds[] = $meld;
                foreach ($meld as $card_id) {
                    $used_cards[] = $card_id;
                }
            }
        }

        return $final_melds;
    }

    /**
     * Find highest value card in hand
     *
     * @param array $hand Player's hand.
     * @return array Card with highest value.
     */
    private function find_highest_card($hand) {
        $card_values = array(
            'A' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7,
            '8' => 8, '9' => 9, '10' => 10, 'J' => 10, 'Q' => 10, 'K' => 10,
        );

        $highest = $hand[0];
        $highest_value = $card_values[$highest['rank']];

        foreach ($hand as $card) {
            $value = $card_values[$card['rank']];
            if ($value > $highest_value) {
                $highest = $card;
                $highest_value = $value;
            }
        }

        return $highest;
    }

    /**
     * Get valid moves for current state
     *
     * @param array $state Current game state.
     * @param int $player_seat Player's seat.
     * @return array Array of valid moves.
     */
    public function get_valid_moves(array $state, int $player_seat): array {
        $valid_moves = array();

        if ($state['current_turn'] !== $player_seat) {
            return $valid_moves;
        }

        if ($state['phase'] === 'drawing') {
            if (!empty($state['deck'])) {
                $valid_moves[] = array('action' => 'draw_deck');
            }
            if (!empty($state['discard_pile'])) {
                $valid_moves[] = array('action' => 'draw_discard');
            }
        } elseif ($state['phase'] === 'discarding') {
            // Player can discard any card in hand
            foreach ($state['hands'][$player_seat] as $card) {
                $valid_moves[] = array(
                    'action' => 'discard',
                    'card_id' => $card['suit'] . '_' . $card['rank'],
                );
            }

            // Check if player can meld or go out
            $melds = $this->find_melds($state['hands'][$player_seat]);
            if (!empty($melds)) {
                $valid_moves[] = array('action' => 'meld', 'melds' => $melds);

                // Check if can go out
                $melded_cards = array();
                foreach ($melds as $meld) {
                    $melded_cards = array_merge($melded_cards, $meld);
                }

                $hand = $state['hands'][$player_seat];
                $remaining = array();
                foreach ($hand as $card) {
                    $card_id = $card['suit'] . '_' . $card['rank'];
                    if (!in_array($card_id, $melded_cards)) {
                        $remaining[] = $card_id;
                    }
                }

                if (count($remaining) <= 1) {
                    $valid_moves[] = array(
                        'action' => 'go_out',
                        'melds' => $melds,
                        'final_discard' => $remaining[0] ?? null,
                    );
                }
            }
        }

        return $valid_moves;
    }

    /**
     * Get public state for a specific player
     *
     * @param array $state Current game state.
     * @param int $player_seat Player's seat.
     * @return array Filtered state.
     */
    public function get_public_state(array $state, int $player_seat): array {
        $public_state = $state;

        // Hide other players' hands
        foreach ($state['hands'] as $seat => $hand) {
            if ($seat !== $player_seat) {
                $public_state['hands'][$seat] = array_fill(0, count($hand), array('hidden' => true));
            }
        }

        // Hide deck cards
        $public_state['deck'] = array_fill(0, count($state['deck']), array('hidden' => true));

        return $public_state;
    }
}
