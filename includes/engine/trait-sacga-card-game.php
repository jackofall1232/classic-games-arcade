<?php
/**
 * Card Game Trait - Shared utilities for card games
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait SACGA_Card_Game_Trait {

    /**
     * Create a standard 52-card deck
     */
    protected function create_standard_deck(): array {
        $suits = [ 'spades', 'hearts', 'diamonds', 'clubs' ];
        $ranks = [ '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A' ];
        $deck = [];

        foreach ( $suits as $suit ) {
            foreach ( $ranks as $rank ) {
                $deck[] = [
                    'id'   => $suit . '_' . $rank,
                    'suit' => $suit,
                    'rank' => $rank,
                ];
            }
        }

        return $deck;
    }

    /**
     * Shuffle a deck of cards
     */
    protected function shuffle_deck( array $deck ): array {
        $shuffled = $deck;
        shuffle( $shuffled );
        return $shuffled;
    }

    /**
     * Deal cards to players
     *
     * @param array $deck The deck to deal from
     * @param int $num_players Number of players
     * @param int $cards_per_player Cards to deal to each player
     * @return array Array with 'hands' and 'remaining' keys
     */
    protected function deal_cards( array $deck, int $num_players, int $cards_per_player ): array {
        $hands = array_fill( 0, $num_players, [] );
        $card_index = 0;

        for ( $i = 0; $i < $cards_per_player; $i++ ) {
            for ( $player = 0; $player < $num_players; $player++ ) {
                if ( $card_index < count( $deck ) ) {
                    $hands[ $player ][] = $deck[ $card_index ];
                    $card_index++;
                }
            }
        }

        return [
            'hands'     => $hands,
            'remaining' => array_slice( $deck, $card_index ),
        ];
    }

    /**
     * Get numeric value of a card rank
     */
    protected function get_card_value( array $card ): int {
        $values = [
            '2'  => 2,
            '3'  => 3,
            '4'  => 4,
            '5'  => 5,
            '6'  => 6,
            '7'  => 7,
            '8'  => 8,
            '9'  => 9,
            '10' => 10,
            'J'  => 11,
            'Q'  => 12,
            'K'  => 13,
            'A'  => 14,
        ];
        return $values[ $card['rank'] ] ?? 0;
    }

    /**
     * Find a card in a hand by ID
     */
    protected function find_card( array $hand, string $card_id ): ?array {
        foreach ( $hand as $card ) {
            if ( $card['id'] === $card_id ) {
                return $card;
            }
        }
        return null;
    }

    /**
     * Check if a hand contains a specific suit
     */
    protected function has_suit( array $hand, string $suit ): bool {
        foreach ( $hand as $card ) {
            if ( $card['suit'] === $suit ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove a card from a hand by ID
     */
    protected function remove_card( array $hand, string $card_id ): array {
        return array_values( array_filter( $hand, fn( $c ) => $c['id'] !== $card_id ) );
    }

    /**
     * Sort a hand by suit and rank
     */
    protected function sort_hand( array $hand, ?array $suit_order = null, ?array $rank_order = null ): array {
        $default_suit_order = [ 'spades' => 0, 'hearts' => 1, 'diamonds' => 2, 'clubs' => 3 ];
        $default_rank_order = [
            'A'  => 14,
            'K'  => 13,
            'Q'  => 12,
            'J'  => 11,
            '10' => 10,
            '9'  => 9,
            '8'  => 8,
            '7'  => 7,
            '6'  => 6,
            '5'  => 5,
            '4'  => 4,
            '3'  => 3,
            '2'  => 2,
        ];

        $suit_order = $suit_order ?? $default_suit_order;
        $rank_order = $rank_order ?? $default_rank_order;

        usort( $hand, function( $a, $b ) use ( $suit_order, $rank_order ) {
            $suit_diff = $suit_order[ $a['suit'] ] - $suit_order[ $b['suit'] ];
            if ( $suit_diff !== 0 ) {
                return $suit_diff;
            }
            return $rank_order[ $b['rank'] ] - $rank_order[ $a['rank'] ];
        } );

        return $hand;
    }
}
