<?php
/**
 * War Game Module
 *
 * Classic 2-player War card game.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_Game_War extends SACGA_Game_Contract {
    use SACGA_Card_Game_Trait;

    protected $id = 'war';
    protected $name = 'War';
    protected $type = 'card';
    protected $min_players = 2;
    protected $max_players = 2;
    protected $has_teams = false;
    protected $ai_supported = true;

    private const DEFAULT_MERCY_TURN_LIMIT = 500;
    private const DEFAULT_WAR_FACE_DOWN = 3;

    public function register_game(): array {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'type'         => $this->type,
            'min_players'  => $this->min_players,
            'max_players'  => $this->max_players,
            'has_teams'    => $this->has_teams,
            'ai_supported' => $this->ai_supported,
            'description'  => __( 'Battle for the full deck in the classic card game War.', 'shortcode-arcade' ),
            'rules'        => [
                __( 'Players: 2', 'shortcode-arcade' ),
                __( 'Deck: Standard 52-card deck', 'shortcode-arcade' ),
                __( 'Each turn both players flip the top card; higher rank wins the pot.', 'shortcode-arcade' ),
                __( 'Ties trigger War: place 3 face-down and one face-up card until resolved.', 'shortcode-arcade' ),
                __( 'Win by collecting all cards or by having more cards when the mercy rule triggers.', 'shortcode-arcade' ),
            ],
        ];
    }

    public function init_state( array $players, array $settings = [] ): array {
        $state = [
            'phase'        => 'ready',
            'current_turn' => 0,
            'turn_count'   => 0,
            'players'      => [],
            'battle'       => [
                'pot'      => [],
                'face_up'  => [ 0 => null, 1 => null ],
                'war_depth'=> 0,
            ],
            'last_result'  => [
                'message'        => '',
                'winner_seat'    => null,
                'collected_count'=> 0,
                'was_war'        => false,
                'turn_summary'   => '',
                'war_depth'      => 0,
            ],
            'settings'     => [
                'mercy_rule_enabled' => isset( $settings['mercy_rule_enabled'] ) ? (bool) $settings['mercy_rule_enabled'] : true,
                'mercy_turn_limit'   => isset( $settings['mercy_turn_limit'] ) ? (int) $settings['mercy_turn_limit'] : self::DEFAULT_MERCY_TURN_LIMIT,
                'war_face_down_count'=> isset( $settings['war_face_down_count'] ) ? (int) $settings['war_face_down_count'] : self::DEFAULT_WAR_FACE_DOWN,
            ],
            'game_over'    => false,
            'end_reason'   => null,
            'winners'      => [],
            'last_move_at' => time(),
        ];

        foreach ( $players as $player ) {
            $seat = (int) $player['seat_position'];
            $state['players'][ $seat ] = [
                'name'        => $player['display_name'],
                'is_ai'       => (bool) $player['is_ai'],
                'draw_pile'   => [],
                'won_pile'    => [],
                'last_face_up'=> null,
            ];
        }

        return $state;
    }

    public function deal_or_setup( array $state ): array {
        $deck = $this->shuffle_deck( $this->create_standard_deck() );
        $deal = $this->deal_cards( $deck, 2, 26 );

        foreach ( $deal['hands'] as $seat => $hand ) {
            $state['players'][ $seat ]['draw_pile'] = $hand;
            $state['players'][ $seat ]['won_pile'] = [];
            $state['players'][ $seat ]['last_face_up'] = null;
        }

        $state['phase'] = 'ready';
        $state['current_turn'] = 0;
        $state['turn_count'] = 0;
        $state['battle'] = [
            'pot'       => [],
            'face_up'   => [ 0 => null, 1 => null ],
            'war_depth' => 0,
        ];
        $state['last_result'] = [
            'message'         => __( 'The battle begins!', 'shortcode-arcade' ),
            'winner_seat'     => null,
            'collected_count' => 0,
            'was_war'         => false,
            'turn_summary'    => '',
            'war_depth'       => 0,
        ];

        return $state;
    }

    public function validate_move( array $state, int $player_seat, array $move ) {
        if ( ! empty( $state['game_over'] ) ) {
            return new WP_Error( 'game_over', __( 'Game is already over.', 'shortcode-arcade' ) );
        }

        $action = $move['action'] ?? '';
        if ( $action !== 'flip' ) {
            return new WP_Error( 'invalid_action', __( 'Invalid action.', 'shortcode-arcade' ) );
        }

        if ( $state['current_turn'] !== $player_seat ) {
            return new WP_Error( 'not_your_turn', __( 'It is not your turn.', 'shortcode-arcade' ) );
        }

        $totals = [
            $this->get_total_cards( $state, 0 ),
            $this->get_total_cards( $state, 1 ),
        ];

        if ( $totals[0] === 0 || $totals[1] === 0 ) {
            return new WP_Error( 'game_over', __( 'A player has no cards remaining.', 'shortcode-arcade' ) );
        }

        return true;
    }

    public function apply_move( array $state, int $player_seat, array $move ): array {
        $action = $move['action'] ?? '';

        if ( $action !== 'flip' ) {
            return $state;
        }

        $state['phase'] = 'battle';
        $state['battle']['pot'] = [];
        $state['battle']['face_up'] = [ 0 => null, 1 => null ];
        $state['battle']['war_depth'] = 0;

        $state = $this->resolve_battle( $state );
        $state['turn_count']++;
        $state['phase'] = 'ready';

        return $state;
    }

    public function advance_turn( array $state ): array {
        if ( ! empty( $state['game_over'] ) ) {
            return $state;
        }

        $state['current_turn'] = $state['current_turn'] === 0 ? 1 : 0;

        return $state;
    }

    public function check_end_condition( array $state ): array {
        $seat0_cards = $this->get_total_cards( $state, 0 );
        $seat1_cards = $this->get_total_cards( $state, 1 );

        if ( $seat0_cards === 0 || $seat1_cards === 0 ) {
            $winner = $seat0_cards === 0 ? 1 : 0;
            return [
                'ended'   => true,
                'reason'  => 'out_of_cards',
                'winners' => [ $winner ],
            ];
        }

        $mercy_enabled = ! empty( $state['settings']['mercy_rule_enabled'] );
        $turn_limit = (int) ( $state['settings']['mercy_turn_limit'] ?? self::DEFAULT_MERCY_TURN_LIMIT );

        if ( $mercy_enabled && $turn_limit > 0 && $state['turn_count'] >= $turn_limit ) {
            if ( $seat0_cards === $seat1_cards ) {
                return [
                    'ended'   => true,
                    'reason'  => 'mercy_rule',
                    'winners' => [],
                ];
            }

            $winner = $seat0_cards > $seat1_cards ? 0 : 1;

            return [
                'ended'   => true,
                'reason'  => 'mercy_rule',
                'winners' => [ $winner ],
            ];
        }

        return [ 'ended' => false, 'reason' => null, 'winners' => null ];
    }

    public function score_round( array $state ): array {
        return $state;
    }

    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        return [ 'action' => 'flip' ];
    }

    public function get_valid_moves( array $state, int $player_seat ): array {
        if ( ! empty( $state['game_over'] ) ) {
            return [];
        }

        if ( $state['current_turn'] !== $player_seat ) {
            return [];
        }

        return [ [ 'action' => 'flip' ] ];
    }

    public function get_public_state( array $state, int $player_seat ): array {
        $public_state = $state;

        foreach ( $state['players'] as $seat => $player ) {
            $draw_count = count( $player['draw_pile'] );
            $won_count = count( $player['won_pile'] );

            $public_state['players'][ $seat ]['draw_pile'] = array_fill( 0, $draw_count, [ 'hidden' => true ] );
            $public_state['players'][ $seat ]['won_pile'] = array_fill( 0, $won_count, [ 'hidden' => true ] );
            $public_state['players'][ $seat ]['total_cards'] = $draw_count + $won_count;
        }

        $public_state['battle']['pot'] = array_fill( 0, count( $state['battle']['pot'] ), [ 'hidden' => true ] );

        return $public_state;
    }

    private function resolve_battle( array $state ): array {
        $war_face_down = max( 0, (int) ( $state['settings']['war_face_down_count'] ?? self::DEFAULT_WAR_FACE_DOWN ) );
        $was_war = false;

        while ( true ) {
            if ( $state['battle']['war_depth'] > 0 ) {
                $was_war = true;
                for ( $seat = 0; $seat < 2; $seat++ ) {
                    for ( $i = 0; $i < $war_face_down; $i++ ) {
                        $card = $this->draw_card( $state, $seat );
                        if ( ! $card ) {
                            break;
                        }
                        $state['battle']['pot'][] = $card;
                    }
                }
            }

            $face_up_cards = [];
            for ( $seat = 0; $seat < 2; $seat++ ) {
                $card = $this->draw_card( $state, $seat );
                if ( ! $card ) {
                    $winner = $seat === 0 ? 1 : 0;
                    $message = __( 'A player could not continue the war.', 'shortcode-arcade' );
                    return $this->award_pot( $state, $winner, $was_war, $message );
                }
                $state['battle']['face_up'][ $seat ] = $card;
                $state['players'][ $seat ]['last_face_up'] = $card;
                $state['battle']['pot'][] = $card;
                $face_up_cards[ $seat ] = $card;
            }

            $comparison = $this->compare_cards( $face_up_cards[0], $face_up_cards[1] );
            if ( $comparison === 0 ) {
                $state['battle']['war_depth']++;
                continue;
            }

            $winner = $comparison > 0 ? 0 : 1;
            return $this->award_pot( $state, $winner, $was_war, '' );
        }
    }

    private function award_pot( array $state, int $winner, bool $was_war, string $message_override ): array {
        $pot_count = count( $state['battle']['pot'] );
        $state['players'][ $winner ]['won_pile'] = array_merge( $state['players'][ $winner ]['won_pile'], $state['battle']['pot'] );

        $winner_name = $state['players'][ $winner ]['name'] ?? sprintf( __( 'Player %d', 'shortcode-arcade' ), $winner + 1 );
        $card_summary = $this->format_battle_summary( $state );

        $message = $message_override;
        if ( $message === '' ) {
            if ( $was_war ) {
                $message = sprintf( __( '%s wins the war and takes %d cards.', 'shortcode-arcade' ), $winner_name, $pot_count );
            } else {
                $message = sprintf( __( '%s wins the battle and takes %d cards.', 'shortcode-arcade' ), $winner_name, $pot_count );
            }
        }

        $state['last_result'] = [
            'message'         => $message,
            'winner_seat'     => $winner,
            'collected_count' => $pot_count,
            'was_war'         => $was_war,
            'turn_summary'    => $card_summary,
            'war_depth'       => $state['battle']['war_depth'],
        ];

        $state['battle']['pot'] = [];
        $state['battle']['war_depth'] = 0;

        return $state;
    }

    private function format_battle_summary( array $state ): string {
        $card0 = $state['battle']['face_up'][0] ?? null;
        $card1 = $state['battle']['face_up'][1] ?? null;

        if ( ! $card0 || ! $card1 ) {
            return '';
        }

        $label0 = $this->format_card_label( $card0 );
        $label1 = $this->format_card_label( $card1 );

        return sprintf( __( '%s vs %s', 'shortcode-arcade' ), $label0, $label1 );
    }

    private function format_card_label( array $card ): string {
        $rank = $card['rank'] ?? '';
        $suit = $card['suit'] ?? '';

        if ( $rank === '' || $suit === '' ) {
            return __( 'Unknown card', 'shortcode-arcade' );
        }

        return sprintf( __( '%s of %s', 'shortcode-arcade' ), $rank, ucfirst( $suit ) );
    }

    private function compare_cards( array $card_a, array $card_b ): int {
        $value_a = $this->get_card_value( $card_a );
        $value_b = $this->get_card_value( $card_b );

        if ( $value_a === $value_b ) {
            return 0;
        }

        return $value_a > $value_b ? 1 : -1;
    }

    private function get_total_cards( array $state, int $seat ): int {
        $player = $state['players'][ $seat ] ?? [ 'draw_pile' => [], 'won_pile' => [] ];
        return count( $player['draw_pile'] ) + count( $player['won_pile'] );
    }

    private function draw_card( array &$state, int $seat ): ?array {
        $this->replenish_draw_pile( $state, $seat );

        if ( empty( $state['players'][ $seat ]['draw_pile'] ) ) {
            return null;
        }

        return array_shift( $state['players'][ $seat ]['draw_pile'] );
    }

    private function replenish_draw_pile( array &$state, int $seat ): void {
        if ( ! empty( $state['players'][ $seat ]['draw_pile'] ) ) {
            return;
        }

        if ( empty( $state['players'][ $seat ]['won_pile'] ) ) {
            return;
        }

        $state['players'][ $seat ]['draw_pile'] = $this->shuffle_deck( $state['players'][ $seat ]['won_pile'] );
        $state['players'][ $seat ]['won_pile'] = [];
    }
}
