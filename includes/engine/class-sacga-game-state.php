<?php
/**
 * Game State - Manages game state persistence, versioning, and retrieval
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_Game_State {

    /**
     * Create initial game state
     *
     * @param int   $room_id The room ID
     * @param array $state Initial game state
     * @return array|WP_Error State data or error
     */
    public function create( int $room_id, array $state ) {
        global $wpdb;

        $etag = $this->generate_etag( $state );

        // Handle current_turn: preserve NULL during gates, use 0 as fallback for non-gate states
        $current_turn = array_key_exists( 'current_turn', $state ) ? $state['current_turn'] : 0;
        $current_turn_format = is_null( $current_turn ) ? '%s' : '%d';

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'sacga_game_state',
            [
                'room_id'       => $room_id,
                'state_version' => 1,
                'current_turn'  => $current_turn,
                'game_data'     => wp_json_encode( $state ),
                'etag'          => $etag,
            ],
            [ '%d', '%d', $current_turn_format, '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return new WP_Error( 'db_error', __( 'Failed to save game state.', 'shortcode-arcade' ) );
        }

        return [
            'id'            => $wpdb->insert_id,
            'room_id'       => $room_id,
            'state_version' => 1,
            'current_turn'  => $current_turn,
            'state'         => $state,
            'etag'          => $etag,
        ];
    }

    /**
     * Get game state for a room
     *
     * @param int $room_id The room ID
     * @return array|null State data or null
     */
    public function get( int $room_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sacga_game_state WHERE room_id = %d",
                $room_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        // Preserve NULL for current_turn during gates (don't cast to int if NULL)
        $current_turn = is_null( $row['current_turn'] ) ? null : (int) $row['current_turn'];

        return [
            'id'            => (int) $row['id'],
            'room_id'       => (int) $row['room_id'],
            'state_version' => (int) $row['state_version'],
            'current_turn'  => $current_turn,
            'state'         => json_decode( $row['game_data'], true ),
            'etag'          => $row['etag'],
            'updated_at'    => $row['updated_at'],
        ];
    }

    /**
     * Update game state
     *
     * @param int   $room_id The room ID
     * @param array $state New game state
     * @param string|null $expected_etag Optional etag for optimistic locking
     * @return array|WP_Error Updated state data or error
     */
    public function update( int $room_id, array $state, ?string $expected_etag = null ) {
        global $wpdb;

        // Get current state for version check
        $current = $this->get( $room_id );

        if ( ! $current ) {
            return new WP_Error( 'not_found', __( 'Game state not found.', 'shortcode-arcade' ) );
        }

        // Optimistic locking check
        if ( $expected_etag !== null && $current['etag'] !== $expected_etag ) {
            return new WP_Error(
                'stale_state',
                __( 'Game state has changed. Please refresh.', 'shortcode-arcade' ),
                [ 'current_etag' => $current['etag'] ]
            );
        }

        $new_version = $current['state_version'] + 1;
        $new_etag = $this->generate_etag( $state );

        // Handle current_turn: preserve NULL during gates, use 0 as fallback for non-gate states
        $current_turn = array_key_exists( 'current_turn', $state ) ? $state['current_turn'] : 0;
        $current_turn_format = is_null( $current_turn ) ? '%s' : '%d';

        $updated = $wpdb->update(
            $wpdb->prefix . 'sacga_game_state',
            [
                'state_version' => $new_version,
                'current_turn'  => $current_turn,
                'game_data'     => wp_json_encode( $state ),
                'etag'          => $new_etag,
            ],
            [ 'room_id' => $room_id ],
            [ '%d', $current_turn_format, '%s', '%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            return new WP_Error( 'db_error', __( 'Failed to update game state.', 'shortcode-arcade' ) );
        }

        return [
            'room_id'       => $room_id,
            'state_version' => $new_version,
            'current_turn'  => $current_turn,
            'state'         => $state,
            'etag'          => $new_etag,
        ];
    }

    /**
     * Apply a move and update state
     *
     * @param int    $room_id The room ID
     * @param int    $player_seat The player's seat position
     * @param array  $move The move to apply
     * @param string|null $expected_etag Optional etag for optimistic locking
     * @return array|WP_Error Updated state or error
     */
    public function apply_move( int $room_id, int $player_seat, array $move, ?string $expected_etag = null ) {
        $current = $this->get( $room_id );

        if ( ! $current ) {
            return new WP_Error( 'not_found', __( 'Game state not found.', 'shortcode-arcade' ) );
        }

        // Get room to find game
        $room = SACGA()->get_room_manager()->get_room_by_id( $room_id );

        if ( ! $room ) {
            return new WP_Error( 'room_not_found', __( 'Room not found.', 'shortcode-arcade' ) );
        }

        $game = SACGA()->get_game_registry()->get( $room['game_id'] );

        if ( ! $game ) {
            return new WP_Error( 'game_not_found', __( 'Game not found.', 'shortcode-arcade' ) );
        }

        $state = $current['state'];

        // Validate move
        $valid = $game->validate_move( $state, $player_seat, $move );

        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( ! $valid ) {
            return new WP_Error( 'invalid_move', __( 'Invalid move.', 'shortcode-arcade' ) );
        }

        // Apply move
        $state = $game->apply_move( $state, $player_seat, $move );

        // Update last move timestamp
        $state['last_move_at'] = time();

        // Check end condition
        $end_check = $game->check_end_condition( $state );

        if ( $end_check['ended'] ) {
            $state = $game->score_round( $state );
            $state['game_over'] = true;
            $state['end_reason'] = $end_check['reason'];
            $state['winners'] = $end_check['winners'];

            // Update room status
            SACGA()->get_room_manager()->update_status( $room_id, 'completed' );
        } else {
            // Advance turn
            $state = $game->advance_turn( $state );
        }

        // Save updated state
        return $this->update( $room_id, $state, $expected_etag );
    }

    /**
     * Get state if changed (for polling)
     *
     * @param int    $room_id The room ID
     * @param string $known_etag The client's current etag
     * @return array|null State if changed, null if unchanged
     */
    public function get_if_changed( int $room_id, string $known_etag ): ?array {
        $current = $this->get( $room_id );

        if ( ! $current ) {
            return null;
        }

        if ( $current['etag'] === $known_etag ) {
            return null; // No change
        }

        return $current;
    }

    /**
     * Get public state (filtered for a specific player)
     *
     * @param int $room_id The room ID
     * @param int $player_seat The player's seat position
     * @return array|null Filtered state or null
     */
    public function get_public_state( int $room_id, int $player_seat ): ?array {
        $state_data = $this->get( $room_id );

        if ( ! $state_data ) {
            return null;
        }

        $room = SACGA()->get_room_manager()->get_room_by_id( $room_id );

        if ( ! $room ) {
            return null;
        }

        $game = SACGA()->get_game_registry()->get( $room['game_id'] );

        if ( ! $game ) {
            return null;
        }

        $public_state = $game->get_public_state( $state_data['state'], $player_seat );

        return [
            'room_id'       => $room_id,
            'state_version' => $state_data['state_version'],
            'current_turn'  => $state_data['current_turn'],
            'state'         => $public_state,
            'etag'          => $state_data['etag'],
        ];
    }

    /**
     * Generate etag for state
     *
     * @param array $state The game state
     * @return string
     */
    private function generate_etag( array $state ): string {
        return md5( wp_json_encode( $state ) . microtime( true ) );
    }

    /**
     * Delete state for a room
     *
     * @param int $room_id The room ID
     * @return bool
     */
    public function delete( int $room_id ): bool {
        global $wpdb;

        return (bool) $wpdb->delete(
            $wpdb->prefix . 'sacga_game_state',
            [ 'room_id' => $room_id ],
            [ '%d' ]
        );
    }
}
