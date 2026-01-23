<?php
/**
 * Room Manager - Handles game rooms and players
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_Room_Manager {

    const EXPIRATION_MINUTES = 120; // Default fallback
    const INACTIVITY_TIMEOUT_SECONDS = 480;
    const COMPLETED_CLEANUP_GRACE_SECONDS = 120;
    const HARD_CAP_SECONDS = 10800;
    const DISCONNECT_GRACE_PERIOD_SECONDS = 90; // Time before disconnected player is removed

    /**
     * Ensure database tables exist before operations
     * This implements the recommended pattern: runtime verification with automatic creation
     */
    private function ensure_tables(): bool {
        static $checked = false;

        // Only check once per request for performance
        if ( $checked ) {
            return true;
        }

        $checked = true;

        // Call the main plugin's ensure_tables_exist method
        if ( method_exists( SACGA(), 'ensure_tables_exist' ) ) {
            return SACGA()->ensure_tables_exist();
        }

        return true;
    }

    /**
     * Get room expiration in minutes from settings
     */
    private function get_expiration_minutes(): int {
        return (int) get_option( 'sacga_room_expiration', self::EXPIRATION_MINUTES );
    }

    private function get_inactivity_timeout_seconds(): int {
        return (int) apply_filters( 'sacga_room_inactive_timeout_seconds', self::INACTIVITY_TIMEOUT_SECONDS );
    }

    private function get_hard_cap_seconds(): int {
        return (int) apply_filters( 'sacga_room_hard_cap_seconds', self::HARD_CAP_SECONDS );
    }

    private function get_disconnect_grace_period(): int {
        return (int) apply_filters( 'sacga_disconnect_grace_period_seconds', self::DISCONNECT_GRACE_PERIOD_SECONDS );
    }

    /**
     * Create a new room
     */
    public function create_room( string $game_id, array $settings = [] ) {
        global $wpdb;

        // Ensure tables exist before attempting to create room
        if ( ! $this->ensure_tables() ) {
            error_log( '[SACGA] Cannot create room - database tables are missing and could not be created' );
            return new WP_Error( 'db_error', __( 'Database tables are missing. Please contact the site administrator.', 'shortcode-arcade' ) );
        }

        $registry = SACGA()->get_game_registry();

        if ( ! $registry->exists( $game_id ) ) {
            return new WP_Error( 'invalid_game', __( 'Game not found.', 'shortcode-arcade' ) );
        }

        $room_code = $this->generate_room_code();
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + $this->get_inactivity_timeout_seconds() );

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'sacga_rooms',
            [
                'room_code'  => $room_code,
                'game_id'    => $game_id,
                'status'     => 'lobby',
                'settings'   => wp_json_encode( $settings ),
                'expires_at' => $expires_at,
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return new WP_Error( 'db_error', __( 'Failed to create room.', 'shortcode-arcade' ) );
        }

        return [
            'id'        => $wpdb->insert_id,
            'room_code' => $room_code,
            'game_id'   => $game_id,
            'status'    => 'lobby',
        ];
    }

    /**
     * Generate unique 6-character room code
     */
    private function generate_room_code(): string {
        global $wpdb;

        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ( $i = 0; $i < 6; $i++ ) {
                $code .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
            }

            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sacga_rooms WHERE room_code = %s AND status IN ('lobby', 'active')",
                $code
            ) );
        } while ( $exists > 0 );

        return $code;
    }

    /**
     * Get room by code
     */
    public function get_room( string $room_code ): ?array {
        global $wpdb;

        // Ensure tables exist
        $this->ensure_tables();

        $room = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sacga_rooms WHERE room_code = %s",
            $room_code
        ), ARRAY_A );

        if ( ! $room ) {
            return null;
        }

        $room['players'] = $this->get_room_players( $room['id'] );
        $room['settings'] = json_decode( $room['settings'], true ) ?: [];

        return $room;
    }

    /**
     * Get room by ID
     */
    public function get_room_by_id( int $room_id ): ?array {
        global $wpdb;

        $room = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sacga_rooms WHERE id = %d",
            $room_id
        ), ARRAY_A );

        if ( ! $room ) {
            return null;
        }

        $room['players'] = $this->get_room_players( $room['id'] );
        $room['settings'] = json_decode( $room['settings'], true ) ?: [];

        return $room;
    }

    /**
     * Get players in a room
     */
    private function get_room_players( int $room_id ): array {
        global $wpdb;

        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sacga_room_players WHERE room_id = %d ORDER BY seat_position",
            $room_id
        ), ARRAY_A );

        // Cast types for proper handling
        return array_map( function( $player ) {
            $player['is_ai'] = (bool) $player['is_ai'];
            $player['seat_position'] = (int) $player['seat_position'];
            $player['connected'] = isset( $player['connected'] ) ? (bool) $player['connected'] : true;
            $player['last_seen'] = isset( $player['last_seen'] ) ? (int) $player['last_seen'] : null;
            return $player;
        }, $players );
    }

    /**
     * Join a room
     */
    public function join_room( string $room_code, array $player_data ) {
        global $wpdb;

        $room = $this->get_room( $room_code );

        if ( ! $room ) {
            return new WP_Error( 'not_found', __( 'Room not found.', 'shortcode-arcade' ) );
        }

        if ( $room['status'] !== 'lobby' ) {
            return new WP_Error( 'game_started', __( 'Game has already started.', 'shortcode-arcade' ) );
        }

        // Check if player already in room
        $existing = $this->find_player_in_room( $room, $player_data );
        if ( $existing ) {
            // Update client_id and connection status for existing player
            $this->update_player_connection( (int) $existing['id'], $player_data['client_id'] ?? null, true );
            return $existing;
        }

        // Get game to check max players
        $game = SACGA()->get_game_registry()->get( $room['game_id'] );
        $meta = $game->register_game();
        $max_players = $meta['max_players'];

        if ( count( $room['players'] ) >= $max_players ) {
            return new WP_Error( 'room_full', __( 'Room is full.', 'shortcode-arcade' ) );
        }

        // Find next available seat - cast to integers to avoid PHP type coercion bugs
        $taken_seats = array_map( 'intval', array_column( $room['players'], 'seat_position' ) );
        $seat = 0;
        while ( in_array( $seat, $taken_seats, true ) ) {
            $seat++;
        }

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'sacga_room_players',
            [
                'room_id'       => $room['id'],
                'user_id'       => $player_data['user_id'] ?? null,
                'guest_token'   => $player_data['guest_token'] ?? null,
                'client_id'     => $player_data['client_id'] ?? null,
                'connected'     => 1,
                'last_seen'     => time(),
                'display_name'  => $player_data['display_name'] ?? 'Player',
                'seat_position' => $seat,
                'is_ai'         => 0,
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%d' ]
        );

        if ( ! $inserted ) {
            return new WP_Error( 'db_error', __( 'Failed to join room.', 'shortcode-arcade' ) );
        }

        $this->touch_room( $room['id'] );

        return [
            'id'            => $wpdb->insert_id,
            'seat_position' => $seat,
            'display_name'  => $player_data['display_name'] ?? 'Player',
        ];
    }

    /**
     * Find if player is already in room
     */
    private function find_player_in_room( array $room, array $player_data ): ?array {
        foreach ( $room['players'] as $player ) {
            if ( ! empty( $player_data['user_id'] ) && (int) $player['user_id'] === (int) $player_data['user_id'] ) {
                return $player;
            }
            if ( ! empty( $player_data['guest_token'] ) && $player['guest_token'] === $player_data['guest_token'] ) {
                return $player;
            }
        }
        return null;
    }

    /**
     * Find player by client_id across all active rooms for a specific game
     */
    public function find_player_by_client_id( string $client_id, string $game_id ): ?array {
        global $wpdb;

        $result = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.*, r.room_code, r.status as room_status, r.game_id
             FROM {$wpdb->prefix}sacga_room_players p
             INNER JOIN {$wpdb->prefix}sacga_rooms r ON p.room_id = r.id
             WHERE p.client_id = %s
               AND r.game_id = %s
               AND r.status IN ('lobby', 'active')
             ORDER BY p.joined_at DESC
             LIMIT 1",
            $client_id,
            $game_id
        ), ARRAY_A );

        if ( ! $result ) {
            return null;
        }

        $result['seat_position'] = (int) $result['seat_position'];
        $result['is_ai'] = (bool) $result['is_ai'];
        $result['connected'] = (bool) $result['connected'];
        $result['last_seen'] = (int) $result['last_seen'];

        return $result;
    }

    /**
     * Update player's connection status and client_id
     */
    public function update_player_connection( int $player_id, ?string $client_id, bool $connected ): void {
        global $wpdb;

        $data = [
            'connected' => $connected ? 1 : 0,
            'last_seen' => time(),
        ];
        $format = [ '%d', '%d' ];

        if ( $client_id !== null ) {
            $data['client_id'] = $client_id;
            $format[] = '%s';
        }

        $wpdb->update(
            $wpdb->prefix . 'sacga_room_players',
            $data,
            [ 'id' => $player_id ],
            $format,
            [ '%d' ]
        );
    }

    /**
     * Update last_seen timestamp for a player
     */
    public function touch_player( int $player_id ): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'sacga_room_players',
            [
                'connected' => 1,
                'last_seen' => time(),
            ],
            [ 'id' => $player_id ],
            [ '%d', '%d' ],
            [ '%d' ]
        );
    }

    /**
     * Mark player as disconnected (for grace period handling)
     */
    public function mark_player_disconnected( int $player_id ): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'sacga_room_players',
            [
                'connected' => 0,
                'last_seen' => time(),
            ],
            [ 'id' => $player_id ],
            [ '%d', '%d' ],
            [ '%d' ]
        );
    }

    /**
     * Rejoin a room (for auto-rejoin after disconnect)
     * Returns player data if successfully rejoined, or WP_Error
     */
    public function rejoin_room( string $room_code, int $player_id, ?string $client_id = null ) {
        global $wpdb;

        $room = $this->get_room( $room_code );

        if ( ! $room ) {
            return new WP_Error( 'not_found', __( 'Room not found.', 'shortcode-arcade' ) );
        }

        // Find the player in this room
        $player = null;
        foreach ( $room['players'] as $p ) {
            if ( (int) $p['id'] === $player_id ) {
                $player = $p;
                break;
            }
        }

        if ( ! $player ) {
            return new WP_Error( 'not_in_room', __( 'Player not found in room.', 'shortcode-arcade' ) );
        }

        // Update connection status
        $this->update_player_connection( $player_id, $client_id, true );

        $this->touch_room( (int) $room['id'] );

        return [
            'id'            => $player['id'],
            'seat_position' => (int) $player['seat_position'],
            'display_name'  => $player['display_name'],
            'room_code'     => $room_code,
            'room_status'   => $room['status'],
        ];
    }

    /**
     * Cleanup disconnected players whose grace period has expired
     */
    public function cleanup_disconnected_players(): int {
        global $wpdb;

        $grace_period = $this->get_disconnect_grace_period();
        $cutoff_time = time() - $grace_period;

        // Find disconnected players past grace period in non-completed rooms
        $expired_players = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.room_id
             FROM {$wpdb->prefix}sacga_room_players p
             INNER JOIN {$wpdb->prefix}sacga_rooms r ON p.room_id = r.id
             WHERE p.connected = 0
               AND p.last_seen < %d
               AND p.is_ai = 0
               AND r.status IN ('lobby', 'active')",
            $cutoff_time
        ), ARRAY_A );

        $count = 0;
        foreach ( $expired_players as $player ) {
            $wpdb->delete(
                $wpdb->prefix . 'sacga_room_players',
                [ 'id' => $player['id'] ],
                [ '%d' ]
            );
            $count++;

            // Check if room is now empty
            $remaining = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sacga_room_players WHERE room_id = %d",
                $player['room_id']
            ) );

            if ( $remaining == 0 ) {
                $this->delete_room( (int) $player['room_id'] );
            }
        }

        if ( $count > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[SACGA] Cleaned up %d disconnected player(s) past grace period', $count ) );
        }

        return $count;
    }

    /**
     * Add AI player
     */
    public function add_ai_player( int $room_id, string $difficulty = 'beginner' ) {
        global $wpdb;

        $room = $this->get_room_by_id( $room_id );

        if ( ! $room ) {
            return new WP_Error( 'not_found', __( 'Room not found.', 'shortcode-arcade' ) );
        }

        if ( $room['status'] !== 'lobby' ) {
            return new WP_Error( 'game_started', __( 'Game has already started.', 'shortcode-arcade' ) );
        }

        $game = SACGA()->get_game_registry()->get( $room['game_id'] );

        if ( ! $game ) {
            return new WP_Error( 'game_not_found', __( 'Game not found.', 'shortcode-arcade' ) );
        }

        $meta = $game->register_game();

        if ( count( $room['players'] ) >= $meta['max_players'] ) {
            return new WP_Error( 'room_full', __( 'Room is full.', 'shortcode-arcade' ) );
        }

        if ( ! $game->supports_ai() ) {
            return new WP_Error( 'no_ai', __( 'This game does not support AI players.', 'shortcode-arcade' ) );
        }

        // Find next seat - cast to integers to avoid PHP type coercion bugs
        $taken_seats = array_map( 'intval', array_column( $room['players'], 'seat_position' ) );
        $seat = 0;
        while ( in_array( $seat, $taken_seats, true ) ) {
            $seat++;
        }

        // Count existing AI for naming
        $ai_count = count( array_filter( $room['players'], fn( $p ) => $p['is_ai'] ) );
        $ai_names = [ 'Bot Alpha', 'Bot Beta', 'Bot Gamma', 'Bot Delta' ];
        $ai_name = $ai_names[ $ai_count ] ?? 'Bot ' . ( $ai_count + 1 );

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'sacga_room_players',
            [
                'room_id'       => $room_id,
                'display_name'  => $ai_name,
                'seat_position' => $seat,
                'is_ai'         => 1,
                'ai_difficulty' => $difficulty,
            ],
            [ '%d', '%s', '%d', '%d', '%s' ]
        );

        if ( ! $inserted ) {
            return new WP_Error( 'db_error', __( 'Failed to add AI player.', 'shortcode-arcade' ) );
        }

        $this->touch_room( $room_id );

        return [
            'id'            => $wpdb->insert_id,
            'seat_position' => $seat,
            'display_name'  => $ai_name,
            'is_ai'         => true,
        ];
    }

    /**
     * Leave room
     */
    public function leave_room( string $room_code, array $player_data ) {
        global $wpdb;

        $room = $this->get_room( $room_code );

        if ( ! $room ) {
            return new WP_Error( 'not_found', __( 'Room not found.', 'shortcode-arcade' ) );
        }

        $player = $this->find_player_in_room( $room, $player_data );

        if ( ! $player ) {
            return new WP_Error( 'not_in_room', __( 'You are not in this room.', 'shortcode-arcade' ) );
        }

        $wpdb->delete(
            $wpdb->prefix . 'sacga_room_players',
            [ 'id' => $player['id'] ],
            [ '%d' ]
        );

        // If room is empty, delete it
        $remaining = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sacga_room_players WHERE room_id = %d",
            $room['id']
        ) );

        if ( $remaining == 0 ) {
            $this->delete_room( $room['id'] );
        } else {
            $this->touch_room( $room['id'] );
        }

        return true;
    }

    /**
     * Start game
     */
    public function start_game( string $room_code ) {
        global $wpdb;

        $room = $this->get_room( $room_code );

        if ( ! $room ) {
            return new WP_Error( 'not_found', __( 'Room not found.', 'shortcode-arcade' ) );
        }

        if ( $room['status'] !== 'lobby' ) {
            return new WP_Error( 'already_started', __( 'Game has already started.', 'shortcode-arcade' ) );
        }

        $game = SACGA()->get_game_registry()->get( $room['game_id'] );

        if ( ! $game ) {
            return new WP_Error( 'game_not_found', __( 'Game type not found.', 'shortcode-arcade' ) );
        }

        $meta = $game->register_game();

        if ( count( $room['players'] ) < $meta['min_players'] ) {
            return new WP_Error( 'not_enough_players', __( 'Not enough players to start.', 'shortcode-arcade' ) );
        }

        // Initialize game state
        $state = $game->init_state( $room['players'], $room['settings'] );
        $state = $game->deal_or_setup( $state );

        // Save state
        $state_manager = new SACGA_Game_State();
        $state_manager->create( $room['id'], $state );

        // Update room status
        $wpdb->update(
            $wpdb->prefix . 'sacga_rooms',
            [ 'status' => 'active' ],
            [ 'id' => $room['id'] ],
            [ '%s' ],
            [ '%d' ]
        );
        $this->touch_room( $room['id'] );

        return true;
    }

    /**
     * Update room status
     */
    public function update_status( int $room_id, string $status ): void {
        global $wpdb;

        $data = [ 'status' => $status ];
        $format = [ '%s' ];

        if ( $status === 'completed' ) {
            $data['expires_at'] = gmdate( 'Y-m-d H:i:s', time() + self::COMPLETED_CLEANUP_GRACE_SECONDS );
            $format[] = '%s';
        }

        $wpdb->update(
            $wpdb->prefix . 'sacga_rooms',
            $data,
            [ 'id' => $room_id ],
            $format,
            [ '%d' ]
        );
    }

    /**
     * Touch room to extend expiration
     */
    public function touch_room( int $room_id ): void {
        global $wpdb;

        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}sacga_rooms WHERE id = %d",
            $room_id
        ) );

        if ( $status === 'completed' ) {
            return;
        }

        $expires_at = gmdate( 'Y-m-d H:i:s', time() + $this->get_inactivity_timeout_seconds() );

        $wpdb->update(
            $wpdb->prefix . 'sacga_rooms',
            [ 'expires_at' => $expires_at ],
            [ 'id' => $room_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Cleanup expired rooms
     */
    public function cleanup_expired_rooms(): int {
        global $wpdb;

        // First, cleanup disconnected players past grace period
        $this->cleanup_disconnected_players();

        $now = gmdate( 'Y-m-d H:i:s' );
        $hard_cap = gmdate( 'Y-m-d H:i:s', time() - $this->get_hard_cap_seconds() );
        $expired_rooms = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}sacga_rooms WHERE (status = 'completed' AND expires_at < %s) OR (status != 'completed' AND created_at < %s)",
                $now,
                $hard_cap
            )
        );

        foreach ( $expired_rooms as $room_id ) {
            $this->delete_room( (int) $room_id );
        }

        return count( $expired_rooms );
    }

    /**
     * Delete room and related data
     */
    public function delete_room( int $room_id ): void {
        global $wpdb;

        $state_deleted = $wpdb->delete( $wpdb->prefix . 'sacga_game_state', [ 'room_id' => $room_id ], [ '%d' ] );
        $players_deleted = $wpdb->delete( $wpdb->prefix . 'sacga_room_players', [ 'room_id' => $room_id ], [ '%d' ] );
        $room_deleted = $wpdb->delete( $wpdb->prefix . 'sacga_rooms', [ 'id' => $room_id ], [ '%d' ] );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SACGA] Room cleanup %d (state=%d, players=%d, room=%d)',
                $room_id,
                $state_deleted ? (int) $state_deleted : 0,
                $players_deleted ? (int) $players_deleted : 0,
                $room_deleted ? (int) $room_deleted : 0
            ) );
        }
    }
}
