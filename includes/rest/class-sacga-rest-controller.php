<?php
/**
 * REST Controller - Handles all REST API endpoints
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_REST_Controller {

    const NAMESPACE = 'sacga/v1';

    /**
     * Register all REST routes
     */
    public function register_routes(): void {
        // Guest token (read-only)
        register_rest_route( self::NAMESPACE, '/guest-token', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_guest_token' ],
            'permission_callback' => '__return_true',
        ] );

        // Create room
        register_rest_route( self::NAMESPACE, '/room', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_room' ],
            'permission_callback' => [ $this, 'can_write_room_action' ],
            'args'                => [
                'game_id' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ] );

        // Get room
        register_rest_route( self::NAMESPACE, '/room/(?P<room_code>[A-Z0-9]{6})', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_room' ],
            'permission_callback' => '__return_true',
        ] );

        // Join room
        register_rest_route( self::NAMESPACE, '/room/(?P<room_code>[A-Z0-9]{6})/join', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'join_room' ],
            'permission_callback' => [ $this, 'can_write_room_action' ],
        ] );

        // Leave room
        register_rest_route( self::NAMESPACE, '/room/(?P<room_code>[A-Z0-9]{6})/leave', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'leave_room' ],
            'permission_callback' => [ $this, 'can_write_room_action' ],
        ] );

        // Add AI
        register_rest_route( self::NAMESPACE, '/room/(?P<room_code>[A-Z0-9]{6})/ai', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'add_ai' ],
            'permission_callback' => [ $this, 'can_write_room_action' ],
            'args'                => [
                'difficulty' => [
                    'type'    => 'string',
                    'default' => 'beginner',
                ],
            ],
        ] );

        // Start game
        register_rest_route( self::NAMESPACE, '/room/(?P<room_code>[A-Z0-9]{6})/start', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'start_game' ],
            'permission_callback' => [ $this, 'can_write_room_action' ],
        ] );

        // Get game state
        register_rest_route( self::NAMESPACE, '/game/state/(?P<room_code>[A-Z0-9]{6})', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_state' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'etag' => [
                    'type' => 'string',
                ],
            ],
        ] );

        // Make move
        register_rest_route( self::NAMESPACE, '/game/move/(?P<room_code>[A-Z0-9]{6})', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'make_move' ],
            'permission_callback' => [ $this, 'can_write_room_action' ],
            'args'                => [
                'move' => [
                    'required' => true,
                    'type'     => 'object',
                ],
            ],
        ] );

        // Forfeit game
        register_rest_route( self::NAMESPACE, '/game/forfeit/(?P<room_code>[A-Z0-9]{6})', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'forfeit_game' ],
            'permission_callback' => [ $this, 'can_write_room_action' ],
        ] );

        // List games
        register_rest_route( self::NAMESPACE, '/games', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_games' ],
            'permission_callback' => '__return_true',
        ] );

        // Rejoin check (for auto-rejoin after browser refresh/disconnect)
        register_rest_route( self::NAMESPACE, '/rejoin-check', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'rejoin_check' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'client_id' => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'game_id' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ] );
    }

    /**
     * Provide a signed guest token for unauthenticated users
     */
    public function get_guest_token( WP_REST_Request $request ) {
        if ( is_user_logged_in() ) {
            return $this->error_response( new WP_Error( 'guest_not_needed', __( 'Guest token not required for logged-in users.', 'shortcode-arcade' ), [ 'status' => 400 ] ) );
        }

        $data = SACGA()->get_guest_token_for_response();
        if ( ! $data ) {
            return $this->error_response( new WP_Error( 'guest_token_failed', __( 'Unable to issue guest token.', 'shortcode-arcade' ), [ 'status' => 500 ] ) );
        }

        return rest_ensure_response( [
            'token'    => $data['token'],
            'guest_id' => $data['guest_id'],
            'expires'  => $data['expires'],
        ] );
    }

    /**
     * Permission callback for state-changing endpoints
     */
    public function can_write_room_action( WP_REST_Request $request ) {
        if ( is_user_logged_in() ) {
            $nonce = $request->get_header( 'X-WP-Nonce' );
            if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                return new WP_Error( 'invalid_nonce', __( 'Invalid or missing nonce.', 'shortcode-arcade' ), [ 'status' => 403 ] );
            }

            return true;
        }

        $guest_token = $this->get_request_guest_token( $request );
        if ( ! $guest_token ) {
            return new WP_Error( 'guest_token_missing', __( 'Guest token is required.', 'shortcode-arcade' ), [ 'status' => 401 ] );
        }

        $room_code = $request->get_param( 'room_code' );
        $validated = SACGA()->validate_guest_token( $guest_token, $room_code ? (string) $room_code : null );

        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        return true;
    }

    /**
     * Create room
     */
    public function create_room( WP_REST_Request $request ) {
        $rate_limit = $this->enforce_rate_limit( 'create', $request );
        if ( is_wp_error( $rate_limit ) ) {
            return $this->error_response( $rate_limit );
        }

        $game_id = sanitize_text_field( $request->get_param( 'game_id' ) );

        $result = SACGA()->get_room_manager()->create_room( $game_id );

        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result );
        }

        // Auto-join creator
        $player_data = $this->get_current_player_data( $request );
        SACGA()->get_room_manager()->join_room( $result['room_code'], $player_data );

        return rest_ensure_response( [
            'success' => true,
            'room'    => $result,
        ] );
    }

    /**
     * Get room
     */
    public function get_room( WP_REST_Request $request ) {
        $room_code = $request->get_param( 'room_code' );
        $room = SACGA()->get_room_manager()->get_room( $room_code );

        if ( ! $room ) {
            return $this->error_response( new WP_Error( 'not_found', __( 'Room not found.', 'shortcode-arcade' ), [ 'status' => 404 ] ) );
        }

        // Add game metadata
        $game = SACGA()->get_game_registry()->get( $room['game_id'] );
        $room['game_meta'] = $game ? $game->register_game() : null;

        SACGA()->get_room_manager()->touch_room( (int) $room['id'] );

        // Update last_seen for the polling player (heartbeat)
        $player_id = $this->get_player_id_in_room( $room );
        if ( $player_id ) {
            SACGA()->get_room_manager()->touch_player( $player_id );
        }

        return rest_ensure_response( $this->sanitize_room_for_response( $room ) );
    }

    /**
     * Join room
     */
    public function join_room( WP_REST_Request $request ) {
        $rate_limit = $this->enforce_rate_limit( 'join', $request );
        if ( is_wp_error( $rate_limit ) ) {
            return $this->error_response( $rate_limit );
        }

        $room_code = $request->get_param( 'room_code' );
        $player_data = $this->get_current_player_data( $request );

        if ( $request->get_param( 'display_name' ) ) {
            $player_data['display_name'] = sanitize_text_field( $request->get_param( 'display_name' ) );
        }

        $result = SACGA()->get_room_manager()->join_room( $room_code, $player_data );

        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result );
        }

        return rest_ensure_response( [
            'success' => true,
            'player'  => $result,
        ] );
    }

    /**
     * Leave room
     */
    public function leave_room( WP_REST_Request $request ) {
        $room_code = $request->get_param( 'room_code' );
        $player_data = $this->get_current_player_data( $request );

        $result = SACGA()->get_room_manager()->leave_room( $room_code, $player_data );

        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result );
        }

        return rest_ensure_response( [ 'success' => true ] );
    }

    /**
     * Add AI player
     */
    public function add_ai( WP_REST_Request $request ) {
        $room_code = $request->get_param( 'room_code' );
        $difficulty = sanitize_text_field( $request->get_param( 'difficulty' ) );

        $room = SACGA()->get_room_manager()->get_room( $room_code );

        if ( ! $room ) {
            return $this->error_response( new WP_Error( 'not_found', __( 'Room not found.', 'shortcode-arcade' ) ) );
        }

        $result = SACGA()->get_room_manager()->add_ai_player( $room['id'], $difficulty );

        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result );
        }

        return rest_ensure_response( [
            'success' => true,
            'player'  => $result,
        ] );
    }

    /**
     * Start game
     */
    public function start_game( WP_REST_Request $request ) {
        $room_code = $request->get_param( 'room_code' );

        $result = SACGA()->get_room_manager()->start_game( $room_code );

        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result );
        }

        $room = SACGA()->get_room_manager()->get_room( $room_code );

        if ( ! $room ) {
            return $this->error_response( new WP_Error( 'room_lost', __( 'Room not found after starting.', 'shortcode-arcade' ) ) );
        }

        // Process AI turns if AI goes first
        $ai_engine = new SACGA_AI_Engine();
        if ( $ai_engine->is_ai_turn( (int) $room['id'] ) ) {
            $ai_engine->process_ai_turns( (int) $room['id'] );
        }

        $state_manager = new SACGA_Game_State();
        $player_seat = $this->get_player_seat( $room_code );
        $state = $state_manager->get_public_state( (int) $room['id'], (int) $player_seat );

        if ( ! $state ) {
            return $this->error_response( new WP_Error( 'no_state', __( 'Failed to get game state.', 'shortcode-arcade' ) ) );
        }

        return rest_ensure_response( [
            'success' => true,
            'state'   => $state,
        ] );
    }

    /**
     * Get game state
     */
    public function get_state( WP_REST_Request $request ) {
        $room_code = $request->get_param( 'room_code' );
        $etag = $request->get_param( 'etag' );

        $room = SACGA()->get_room_manager()->get_room( $room_code );

        if ( ! $room ) {
            return $this->error_response( new WP_Error( 'not_found', __( 'Room not found.', 'shortcode-arcade' ) ) );
        }

        $state_manager = new SACGA_Game_State();
        $player_seat = $this->get_player_seat( $room_code );

        // Update last_seen for the polling player (heartbeat)
        $player_id = $this->get_player_id_in_room( $room );
        if ( $player_id ) {
            SACGA()->get_room_manager()->touch_player( $player_id );
        }

        // Check for move timeout (3 minutes = 180 seconds)
        if ( $room['status'] === 'active' ) {
            $current_state = $state_manager->get( $room['id'] );

            if ( $current_state && ! empty( $current_state['state'] ) ) {
                $state_data = $current_state['state'];
                $last_move = $state_data['last_move_at'] ?? time();
                $elapsed = time() - $last_move;

                // Check if current player has timed out (3 minutes)
                if ( $elapsed > 180 && empty( $state_data['game_over'] ) ) {
                    $current_turn = $state_data['current_turn'];
                    $opponent_seat = $current_turn === 0 ? 1 : 0;

                    // Auto-forfeit the player who timed out
                    $state_data['game_over'] = true;
                    $state_data['end_reason'] = 'timeout';
                    $state_data['winners'] = [ $opponent_seat ];

                    $state_manager->update( $room['id'], $state_data );
                    SACGA()->get_room_manager()->update_status( $room['id'], 'completed' );
                }
            }
        }

        // Process AI turn BEFORE etag check (AI may update state)
        $ai_engine = new SACGA_AI_Engine();
        if ( $room['status'] === 'active' && $ai_engine->is_ai_turn( $room['id'] ) ) {
            $ai_engine->process_ai_turns( $room['id'] );
        }

        // Check etag for polling optimization (after AI had chance to play)
        if ( $etag ) {
            $current = $state_manager->get( $room['id'] );
            if ( $current && $current['etag'] === $etag ) {
                return rest_ensure_response( [
                    'changed' => false,
                    'etag'    => $etag,
                ] );
            }
        }

        $state = $state_manager->get_public_state( $room['id'], $player_seat );

        if ( ! $state ) {
            return rest_ensure_response( [
                'started' => false,
                'room'    => $this->sanitize_room_for_response( $room ),
            ] );
        }

        SACGA()->get_room_manager()->touch_room( $room['id'] );

        return rest_ensure_response( [
            'changed' => true,
            'state'   => $state,
            'room'    => [
                'status'  => $room['status'],
                'players' => $this->sanitize_players_for_response( $room['players'] ),
            ],
        ] );
    }

    /**
     * Make move
     */
    public function make_move( WP_REST_Request $request ) {
        $room_code = $request->get_param( 'room_code' );
        $move = $request->get_param( 'move' );
        $etag = $request->get_param( 'etag' );

        $room = SACGA()->get_room_manager()->get_room( $room_code );

        if ( ! $room ) {
            return $this->error_response( new WP_Error( 'not_found', __( 'Room not found.', 'shortcode-arcade' ) ) );
        }

        if ( $room['status'] !== 'active' ) {
            return $this->error_response( new WP_Error( 'not_active', __( 'Game is not active.', 'shortcode-arcade' ) ) );
        }

        $player_seat = $this->get_player_seat( $room_code );

        if ( $player_seat === null ) {
            return $this->error_response( new WP_Error( 'not_in_room', __( 'You are not in this room.', 'shortcode-arcade' ) ) );
        }

        // Check if it's the player's turn (skip for simultaneous phases like Hearts passing)
        $state_manager = new SACGA_Game_State();
        $current = $state_manager->get( $room['id'] );

        // Debug logging for cribbage discard issues
        if ( $room['game_id'] === 'cribbage' ) {
            error_log( sprintf(
                '[Make Move] Cribbage - player_seat: %d, phase: %s, current_turn: %s, discards: %s',
                $player_seat,
                $current['state']['phase'] ?? 'null',
                $current['state']['current_turn'] ?? 'null',
                json_encode( $current['state']['discards'] ?? [] )
            ) );
        }

        $action = is_array( $move ) ? ( $move['action'] ?? '' ) : '';
        $is_gate_action = in_array( $action, [ 'begin_game', 'continue' ], true );

        // Skip turn check for simultaneous move phases (e.g., Hearts passing, Cribbage discard, Overcut rolloff)
        $is_simultaneous_phase = isset( $current['state']['phase'] ) &&
            in_array( $current['state']['phase'], [ 'passing', 'discard', 'rolloff' ], true );

        // Check for explicit simultaneous flag (e.g., Even at Odds bidding)
        $is_simultaneous_action = ! empty( $current['state']['simultaneous'] );

        if ( ! $is_gate_action && ! $is_simultaneous_phase && ! $is_simultaneous_action && $current['state']['current_turn'] !== $player_seat ) {
            error_log( sprintf(
                '[REST] Turn check failed: phase=%s, is_simultaneous=%s, current_turn=%s, player_seat=%d',
                $current['state']['phase'] ?? 'null',
                $is_simultaneous_phase ? 'true' : 'false',
                $current['state']['current_turn'] ?? 'null',
                $player_seat
            ) );
            return $this->error_response( new WP_Error( 'not_your_turn', __( 'It is not your turn.', 'shortcode-arcade' ) ) );
        }

        // Apply move
        $result = $state_manager->apply_move( $room['id'], $player_seat, $move, $etag );

        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result );
        }

        // Process AI turns
        $ai_engine = new SACGA_AI_Engine();
        if ( $ai_engine->is_ai_turn( $room['id'] ) ) {
            $ai_engine->process_ai_turns( $room['id'] );
        }

        // Get updated state
        $state = $state_manager->get_public_state( $room['id'], $player_seat );

        SACGA()->get_room_manager()->touch_room( (int) $room['id'] );

        return rest_ensure_response( [
            'success' => true,
            'state'   => $state,
        ] );
    }

    /**
     * Forfeit game
     */
    public function forfeit_game( WP_REST_Request $request ) {
        $room_code = $request->get_param( 'room_code' );

        $room = SACGA()->get_room_manager()->get_room( $room_code );

        if ( ! $room ) {
            return $this->error_response( new WP_Error( 'not_found', __( 'Room not found.', 'shortcode-arcade' ) ) );
        }

        if ( $room['status'] !== 'active' ) {
            return $this->error_response( new WP_Error( 'not_active', __( 'Game is not active.', 'shortcode-arcade' ) ) );
        }

        $player_seat = $this->get_player_seat( $room_code );

        if ( $player_seat === null ) {
            return $this->error_response( new WP_Error( 'not_in_room', __( 'You are not in this room.', 'shortcode-arcade' ) ) );
        }

        // Get current state
        $state_manager = new SACGA_Game_State();
        $current = $state_manager->get( $room['id'] );

        if ( ! $current ) {
            return $this->error_response( new WP_Error( 'no_state', __( 'Game state not found.', 'shortcode-arcade' ) ) );
        }

        $state = $current['state'];

        // Determine winner (opponent of forfeiting player)
        $opponent_seat = $player_seat === 0 ? 1 : 0;

        // Mark game as over
        $state['game_over'] = true;
        $state['end_reason'] = 'forfeit';
        $state['winners'] = [ $opponent_seat ];

        // Update state and room status
        $state_manager->update( $room['id'], $state );
        SACGA()->get_room_manager()->update_status( $room['id'], 'completed' );

        // Get updated state
        $state = $state_manager->get_public_state( $room['id'], $player_seat );

        SACGA()->get_room_manager()->touch_room( (int) $room['id'] );

        return rest_ensure_response( [
            'success' => true,
            'state'   => $state,
        ] );
    }

    /**
     * List available games
     */
    public function list_games( WP_REST_Request $request ) {
        $games = SACGA()->get_game_registry()->get_all_metadata();

        return rest_ensure_response( [ 'games' => $games ] );
    }

    /**
     * Check if a client can rejoin a room (for auto-rejoin after disconnect)
     */
    public function rejoin_check( WP_REST_Request $request ) {
        $client_id = sanitize_text_field( $request->get_param( 'client_id' ) );
        $game_id = sanitize_text_field( $request->get_param( 'game_id' ) );

        if ( empty( $client_id ) || empty( $game_id ) ) {
            return rest_ensure_response( [ 'status' => 'none' ] );
        }

        // Find if this client has an active room
        $player = SACGA()->get_room_manager()->find_player_by_client_id( $client_id, $game_id );

        if ( ! $player ) {
            return rest_ensure_response( [ 'status' => 'none' ] );
        }

        // Get the full room data
        $room = SACGA()->get_room_manager()->get_room( $player['room_code'] );

        if ( ! $room ) {
            return rest_ensure_response( [ 'status' => 'none' ] );
        }

        // Rejoin the player (update connection status)
        $rejoin_result = SACGA()->get_room_manager()->rejoin_room(
            $player['room_code'],
            (int) $player['id'],
            $client_id
        );

        if ( is_wp_error( $rejoin_result ) ) {
            return rest_ensure_response( [ 'status' => 'none' ] );
        }

        // Get game state if game is active
        $game_state = null;
        if ( $room['status'] === 'active' ) {
            $state_manager = new SACGA_Game_State();
            $game_state = $state_manager->get_public_state( (int) $room['id'], (int) $player['seat_position'] );
        }

        return rest_ensure_response( [
            'status'      => 'found',
            'room_code'   => $player['room_code'],
            'seat'        => (int) $player['seat_position'],
            'room_status' => $room['status'],
            'room'        => $this->sanitize_room_for_response( $room ),
            'game_state'  => $game_state,
        ] );
    }

    /**
     * Get current player data
     */
    private function get_current_player_data( WP_REST_Request $request ): array {
        // Get client_id from request header (persistent across sessions)
        $client_id = $request->get_header( 'X-SACGA-Client-ID' );
        if ( $client_id ) {
            $client_id = sanitize_text_field( $client_id );
        }

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            return [
                'user_id'      => $user->ID,
                'display_name' => $user->display_name,
                'client_id'    => $client_id,
            ];
        }

        $guest_token = $this->get_request_guest_token( $request );
        $guest_id = null;
        if ( $guest_token ) {
            $validated = SACGA()->validate_guest_token( $guest_token );
            if ( ! is_wp_error( $validated ) ) {
                $guest_id = $validated['guest_id'];
            }
        }

        return [
            'guest_token'  => $guest_id,
            'display_name' => 'Guest',
            'client_id'    => $client_id,
        ];
    }

    /**
     * Get player's seat in room
     */
    private function get_player_seat( string $room_code ): ?int {
        $room = SACGA()->get_room_manager()->get_room( $room_code );

        if ( ! $room ) {
            return null;
        }

        $user_id = get_current_user_id();

        $guest_token = null;
        if ( isset( $_COOKIE['sacga_guest_token'] ) ) {
            $guest_token = sanitize_text_field( $_COOKIE['sacga_guest_token'] );
        } elseif ( isset( $_SERVER['HTTP_X_SACGA_GUEST_TOKEN'] ) ) {
            $guest_token = sanitize_text_field( $_SERVER['HTTP_X_SACGA_GUEST_TOKEN'] );
        }

        $guest_id = null;
        if ( $guest_token ) {
            $validated = SACGA()->validate_guest_token( $guest_token, $room_code );
            if ( ! is_wp_error( $validated ) ) {
                $guest_id = $validated['guest_id'];
            }
        }

        foreach ( $room['players'] as $player ) {
            if ( $user_id && (int) $player['user_id'] === $user_id ) {
                return (int) $player['seat_position'];
            }
            if ( $guest_id && $player['guest_token'] === $guest_id ) {
                return (int) $player['seat_position'];
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[SACGA] Player not found in room %s (guest_token: %s)', $room_code, $guest_token ? substr( $guest_token, 0, 8 ) . '...' : 'null' ) );
        }

        return null;
    }

    /**
     * Get player's database ID in a room
     */
    private function get_player_id_in_room( array $room ): ?int {
        if ( ! $room || empty( $room['players'] ) ) {
            return null;
        }

        $user_id = get_current_user_id();

        $guest_token = null;
        if ( isset( $_COOKIE['sacga_guest_token'] ) ) {
            $guest_token = sanitize_text_field( $_COOKIE['sacga_guest_token'] );
        } elseif ( isset( $_SERVER['HTTP_X_SACGA_GUEST_TOKEN'] ) ) {
            $guest_token = sanitize_text_field( $_SERVER['HTTP_X_SACGA_GUEST_TOKEN'] );
        }

        $guest_id = null;
        if ( $guest_token ) {
            $validated = SACGA()->validate_guest_token( $guest_token );
            if ( ! is_wp_error( $validated ) ) {
                $guest_id = $validated['guest_id'];
            }
        }

        foreach ( $room['players'] as $player ) {
            if ( $user_id && (int) $player['user_id'] === $user_id ) {
                return (int) $player['id'];
            }
            if ( $guest_id && $player['guest_token'] === $guest_id ) {
                return (int) $player['id'];
            }
        }

        return null;
    }

    private function get_request_guest_token( WP_REST_Request $request ): ?string {
        $guest_token = $request->get_header( 'X-SACGA-Guest-Token' );
        if ( $guest_token ) {
            return sanitize_text_field( $guest_token );
        }

        if ( isset( $_COOKIE['sacga_guest_token'] ) ) {
            return sanitize_text_field( $_COOKIE['sacga_guest_token'] );
        }

        return null;
    }

    private function sanitize_room_for_response( array $room ): array {
        if ( isset( $room['players'] ) ) {
            $room['players'] = $this->sanitize_players_for_response( $room['players'] );
        }

        return $room;
    }

    private function sanitize_players_for_response( array $players ): array {
        return array_map( function( $player ) {
            if ( isset( $player['guest_token'] ) ) {
                $player['guest_id'] = $player['guest_token'];
                unset( $player['guest_token'] );
            }
            return $player;
        }, $players );
    }

    private function enforce_rate_limit( string $action, WP_REST_Request $request ) {
        $limit = 10;
        $window = 5 * MINUTE_IN_SECONDS;

        $identifier = $this->get_rate_limit_identifier( $request );
        $key = 'sacga_rate_' . $action . '_' . md5( $identifier );

        $data = get_transient( $key );
        if ( ! is_array( $data ) ) {
            $data = [
                'count' => 0,
                'reset' => time() + $window,
            ];
        }

        if ( $data['count'] >= $limit ) {
            return new WP_Error(
                'rate_limited',
                __( 'Too many requests. Please slow down.', 'shortcode-arcade' ),
                [ 'status' => 429 ]
            );
        }

        $data['count']++;
        set_transient( $key, $data, $window );

        return true;
    }

    private function get_rate_limit_identifier( WP_REST_Request $request ): string {
        if ( is_user_logged_in() ) {
            return 'user_' . get_current_user_id();
        }

        $guest_token = $this->get_request_guest_token( $request );
        if ( $guest_token ) {
            $validated = SACGA()->validate_guest_token( $guest_token );
            if ( ! is_wp_error( $validated ) ) {
                return 'guest_' . $validated['guest_id'];
            }
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return 'ip_' . $ip;
    }

    /**
     * Format error response
     */
    private function error_response( WP_Error $error ): WP_REST_Response {
        $data = $error->get_error_data();
        $status = isset( $data['status'] ) ? $data['status'] : 400;

        return new WP_REST_Response( [
            'success' => false,
            'code'    => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ], $status );
    }
}
