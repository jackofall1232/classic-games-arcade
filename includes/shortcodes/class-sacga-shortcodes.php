<?php
/**
 * Shortcodes - Renders game interfaces
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_Shortcodes {

    /**
     * Register shortcodes
     */
    public static function register(): void {
        add_shortcode( 'sacga_game', [ __CLASS__, 'render_game' ] );
        add_shortcode( 'classic_games_arcade', [ __CLASS__, 'render_arcade' ] );
        add_shortcode( 'sacga_available_rooms', [ __CLASS__, 'render_available_rooms' ] );
        add_shortcode( 'sacga_rules', [ __CLASS__, 'render_rules' ] );
    }

    /**
     * Render single game
     * Usage: [sacga_game game="checkers"]
     */
    public static function render_game( array $atts ): string {
        $atts = shortcode_atts( [
            'game' => 'checkers',
        ], $atts );

        $game_id = sanitize_text_field( $atts['game'] );
        $registry = SACGA()->get_game_registry();
        $game = $registry->get( $game_id );

        if ( ! $game ) {
            /* translators: %s: game identifier */
            return '<div class="sacga-error">' . sprintf( esc_html__( 'Game not found: %s', 'shortcode-arcade' ), esc_html( $game_id ) ) . '</div>';
        }

        $meta = $game->register_game();
        $room_code = isset( $_GET['room'] ) ? strtoupper( sanitize_text_field( $_GET['room'] ) ) : '';

        $game_type = $meta['type'] ?? '';
        $type_class = '';
        if ( $game_type ) {
            $type_slug = $game_type === 'card' ? 'cards' : ( $game_type === 'dice' ? 'dice' : $game_type );
            $type_class = 'sacga-game-type-' . $type_slug;
        }

        // Enqueue game-specific assets
        self::enqueue_game_assets( $game_id, $game_type );

        // Enqueue rules assets if game has rules
        if ( ! empty( $meta['rules'] ) ) {
            self::enqueue_rules_assets();
        }

        ob_start();
        ?>
        <div id="sacga-game-container" class="sacga-container sacga-game-<?php echo esc_attr( $game_id ); ?> <?php echo esc_attr( $type_class ); ?>" data-game-id="<?php echo esc_attr( $game_id ); ?>" data-room-code="<?php echo esc_attr( $room_code ); ?>">

            <!-- Loading Overlay -->
            <div id="sacga-loading" class="sacga-loading" style="display: none;">
                <div class="sacga-spinner"></div>
                <span><?php echo esc_html__( 'Loading...', 'shortcode-arcade' ); ?></span>
            </div>

            <!-- Lobby View -->
            <div id="sacga-lobby" class="sacga-view sacga-view-active">
                <div class="sacga-game-header">
                    <?php if ( ! empty( $meta['rules'] ) ) : ?>
                        <div class="sacga-rules-btn-container">
                            <button type="button" class="sacga-rules-btn sacga-rules-btn-light" data-game-id="<?php echo esc_attr( $game_id ); ?>" data-game-name="<?php echo esc_attr( $meta['name'] ); ?>">
                                <span class="dashicons dashicons-book"></span>
                                <?php echo esc_html__( 'Rules', 'shortcode-arcade' ); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                    <h2><?php echo esc_html( $meta['name'] ); ?></h2>
                    <p class="sacga-game-desc"><?php echo esc_html( $meta['description'] ); ?></p>
                    <p class="sacga-player-info">
                        <span class="dashicons dashicons-groups"></span>
                        <?php
                        /* translators: %s: player count range (e.g., "2-4") */
                        echo sprintf( esc_html__( '%s players', 'shortcode-arcade' ), esc_html( $meta['min_players'] . '-' . $meta['max_players'] ) );
                        ?>
                        <?php if ( $meta['ai_supported'] ) : ?>
                            <span class="sacga-ai-badge"><span class="dashicons dashicons-admin-generic"></span> <?php echo esc_html__( 'AI Available', 'shortcode-arcade' ); ?></span>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="sacga-lobby-actions">
                    <button id="sacga-create-room" class="sacga-btn sacga-btn-primary">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <?php echo esc_html__( 'Create Room', 'shortcode-arcade' ); ?>
                    </button>

                    <div class="sacga-divider"><span><?php echo esc_html__( 'or', 'shortcode-arcade' ); ?></span></div>

                    <div class="sacga-join-form">
                        <input type="text" id="sacga-room-code-input" class="sacga-input" placeholder="<?php echo esc_attr__( 'Enter room code', 'shortcode-arcade' ); ?>" maxlength="6" pattern="[A-Za-z0-9]{6}">
                        <button id="sacga-join-room" class="sacga-btn sacga-btn-secondary">
                            <?php echo esc_html__( 'Join Room', 'shortcode-arcade' ); ?>
                        </button>
                    </div>
                </div>

                <button id="sacga-back-to-arcade" class="sacga-btn sacga-btn-text">
                    <span class="dashicons dashicons-arrow-left-alt"></span>
                    <?php echo esc_html__( 'Back to Arcade', 'shortcode-arcade' ); ?>
                </button>
            </div>

            <!-- Room View (Waiting for Players) -->
            <div id="sacga-room" class="sacga-view">
                <?php if ( ! empty( $meta['rules'] ) ) : ?>
                    <div class="sacga-rules-btn-container">
                        <button type="button" class="sacga-rules-btn" data-game-id="<?php echo esc_attr( $game_id ); ?>" data-game-name="<?php echo esc_attr( $meta['name'] ); ?>">
                            <span class="dashicons dashicons-book"></span>
                            <?php echo esc_html__( 'Rules', 'shortcode-arcade' ); ?>
                        </button>
                    </div>
                <?php endif; ?>
                <div class="sacga-room-header">
                    <h3><?php echo esc_html__( 'Room Code', 'shortcode-arcade' ); ?></h3>
                    <div class="sacga-room-code-display">
                        <span id="sacga-room-code-display"></span>
                        <button id="sacga-copy-code" class="sacga-btn-icon" title="<?php echo esc_attr__( 'Copy code', 'shortcode-arcade' ); ?>">
                            <span class="dashicons dashicons-clipboard"></span>
                        </button>
                    </div>
                    <p class="sacga-room-tip"><?php echo esc_html__( 'Share this code with friends to join!', 'shortcode-arcade' ); ?></p>
                </div>

                <div class="sacga-players-section">
                    <h4><?php echo esc_html__( 'Players', 'shortcode-arcade' ); ?></h4>
                    <ul id="sacga-players" class="sacga-players-list"></ul>
                </div>

                <div class="sacga-room-actions">
                    <button id="sacga-add-ai" class="sacga-btn sacga-btn-secondary">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <?php echo esc_html__( 'Add AI Player', 'shortcode-arcade' ); ?>
                    </button>
                    <button id="sacga-start-game" class="sacga-btn sacga-btn-primary" disabled>
                        <?php echo esc_html__( 'Start Game', 'shortcode-arcade' ); ?>
                    </button>
                </div>

                <button id="sacga-leave-room" class="sacga-btn sacga-btn-text">
                    <span class="dashicons dashicons-exit"></span>
                    <?php echo esc_html__( 'Leave Room', 'shortcode-arcade' ); ?>
                </button>
            </div>

            <!-- Game View -->
            <div id="sacga-game" class="sacga-view">
                <div class="sacga-game-info">
                    <div id="sacga-current-turn" class="sacga-turn-indicator"></div>
                    <div class="sacga-game-toolbar">
                        <?php if ( ! empty( $meta['rules'] ) ) : ?>
                            <button type="button" class="sacga-rules-btn" data-game-id="<?php echo esc_attr( $game_id ); ?>" data-game-name="<?php echo esc_attr( $meta['name'] ); ?>">
                                <span class="dashicons dashicons-book"></span>
                                <?php echo esc_html__( 'Rules', 'shortcode-arcade' ); ?>
                            </button>
                        <?php endif; ?>
                        <button id="sacga-forfeit-game" class="sacga-btn sacga-btn-text sacga-btn-danger">
                            <span class="dashicons dashicons-flag"></span>
                            <?php echo esc_html__( 'Forfeit', 'shortcode-arcade' ); ?>
                        </button>
                    </div>
                </div>
                <div id="sacga-game-board" class="sacga-game-board"></div>
            </div>

            <!-- Game Over View -->
            <div id="sacga-gameover" class="sacga-view">
                <div class="sacga-gameover-content">
                    <h2 id="sacga-gameover-title"><?php echo esc_html__( 'Game Over', 'shortcode-arcade' ); ?></h2>
                    <div id="sacga-final-scores"></div>
                    <div class="sacga-gameover-actions">
                        <button id="sacga-play-again" class="sacga-btn sacga-btn-primary"><?php echo esc_html__( 'Play Again', 'shortcode-arcade' ); ?></button>
                        <button id="sacga-back-to-lobby" class="sacga-btn sacga-btn-secondary"><?php echo esc_html__( 'Back to Lobby', 'shortcode-arcade' ); ?></button>
                        <button id="sacga-exit-to-arcade" class="sacga-btn sacga-btn-text"><?php echo esc_html__( 'Back to Arcade', 'shortcode-arcade' ); ?></button>
                    </div>
                </div>
            </div>

            <?php if ( ! empty( $meta['rules'] ) ) : ?>
                <!-- Hidden Rules Data for Modal -->
                <script type="text/template" id="sacga-rules-data-<?php echo esc_attr( $game_id ); ?>" data-sacga-rules-content="<?php echo esc_attr( $game_id ); ?>">
                    <?php echo self::get_rules_html_for_modal( $game_id ); ?>
                </script>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render arcade view
     * Usage: [classic_games_arcade]
     */
    public static function render_arcade( array $atts ): string {
        if ( isset( $_GET['game'] ) ) {
            $game_id = sanitize_text_field( $_GET['game'] );
            $registry = SACGA()->get_game_registry();

            if ( $registry->get( $game_id ) ) {
                return self::render_game( [ 'game' => $game_id ] );
            }

            /* translators: %s: game identifier */
            return '<div class="sacga-error">' . sprintf( esc_html__( 'Game not found: %s', 'shortcode-arcade' ), esc_html( $game_id ) ) . '</div>';
        }

        $games = SACGA()->get_game_registry()->get_all_metadata();

        if ( empty( $games ) ) {
            return '<div class="sacga-error">' . esc_html__( 'No games available.', 'shortcode-arcade' ) . '</div>';
        }

        ob_start();
        ?>
        <div class="sacga-arcade">
            <h2><?php echo esc_html__( 'Classic Games Arcade', 'shortcode-arcade' ); ?></h2>
            <div class="sacga-games-grid">
                <?php foreach ( $games as $id => $meta ) : ?>
                    <div class="sacga-game-card">
                        <h3><?php echo esc_html( $meta['name'] ); ?></h3>
                        <p><?php echo esc_html( $meta['description'] ); ?></p>
                        <div class="sacga-game-meta">
                            <span><span class="dashicons dashicons-groups"></span> <?php
                            /* translators: %s: player count range (e.g., "2-4") */
                            echo sprintf( esc_html__( '%s players', 'shortcode-arcade' ), esc_html( $meta['min_players'] . '-' . $meta['max_players'] ) );
                            ?></span>
                            <?php if ( $meta['ai_supported'] ) : ?>
                                <span><span class="dashicons dashicons-admin-generic"></span> <?php echo esc_html__( 'AI', 'shortcode-arcade' ); ?></span>
                            <?php endif; ?>
                        </div>
                        <a href="?game=<?php echo esc_attr( $id ); ?>" class="sacga-btn sacga-btn-primary"><?php echo esc_html__( 'Play', 'shortcode-arcade' ); ?></a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render available rooms list
     * Usage: [sacga_available_rooms game="checkers" limit="10" show_players="true" show_created="true" refresh="30"]
     */
    public static function render_available_rooms( array $atts ): string {
        $atts = shortcode_atts( [
            'game'         => '',
            'limit'        => 10,
            'show_players' => 'true',
            'show_created' => 'true',
            'refresh'      => 0,
        ], $atts );

        $game_filter   = sanitize_text_field( $atts['game'] );
        $limit         = absint( $atts['limit'] );
        $show_players  = filter_var( $atts['show_players'], FILTER_VALIDATE_BOOLEAN );
        $show_created  = filter_var( $atts['show_created'], FILTER_VALIDATE_BOOLEAN );
        $refresh       = absint( $atts['refresh'] );

        // Enqueue styles
        wp_enqueue_style( 'sacga-available-rooms', SACGA_PLUGIN_URL . 'assets/css/sacga-available-rooms.css', [], SACGA_VERSION );

        // Enqueue auto-refresh script if enabled
        if ( $refresh > 0 ) {
            wp_enqueue_script( 'sacga-available-rooms', SACGA_PLUGIN_URL . 'assets/js/sacga-available-rooms.js', [ 'jquery' ], SACGA_VERSION, true );
            wp_localize_script( 'sacga-available-rooms', 'sacgaRoomsConfig', [
                'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'sacga_rooms_refresh' ),
                'refresh'  => $refresh,
                'game'     => $game_filter,
                'limit'    => $limit,
            ] );
        }

        // Query available rooms
        $rooms = self::get_available_rooms( $game_filter, $limit );

        // Get game registry for metadata
        $registry = SACGA()->get_game_registry();

        // Generate unique container ID for AJAX refresh
        $container_id = 'sacga-rooms-' . wp_rand( 1000, 9999 );

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $container_id ); ?>" class="sacga-available-rooms" data-game="<?php echo esc_attr( $game_filter ); ?>" data-limit="<?php echo esc_attr( $limit ); ?>" data-refresh="<?php echo esc_attr( $refresh ); ?>">
            <?php if ( empty( $rooms ) ) : ?>
                <div class="sacga-rooms-empty">
                    <div class="sacga-rooms-empty-icon">
                        <span class="dashicons dashicons-groups"></span>
                    </div>
                    <p class="sacga-rooms-empty-message"><?php echo esc_html__( 'No rooms available right now.', 'shortcode-arcade' ); ?></p>
                    <p class="sacga-rooms-empty-hint"><?php echo esc_html__( 'Start a new game to create a room!', 'shortcode-arcade' ); ?></p>
                </div>
            <?php else : ?>
                <div class="sacga-rooms-grid">
                    <?php foreach ( $rooms as $room ) :
                        $game = $registry->get( $room['game_id'] );
                        $meta = $game ? $game->register_game() : null;
                        $max_players = $meta ? (int) $meta['max_players'] : 4;
                        $player_count = (int) $room['player_count'];
                        $seats_available = $max_players - $player_count;
                        $game_name = $meta ? $meta['name'] : ucfirst( $room['game_id'] );
                        $game_type = $meta ? $meta['type'] : '';

                        // Build join URL
                        $join_url = add_query_arg( [
                            'game' => $room['game_id'],
                            'room' => $room['room_code'],
                        ], get_permalink() );
                        $is_full = $seats_available <= 0;
                    ?>
                        <div class="sacga-room-card sacga-room-status-<?php echo esc_attr( $room['status'] ); ?><?php echo $is_full ? ' sacga-room-full' : ''; ?>" data-room-code="<?php echo esc_attr( $room['room_code'] ); ?>" data-game-id="<?php echo esc_attr( $room['game_id'] ); ?>">
                            <div class="sacga-room-header">
                                <span class="sacga-room-game"><?php echo esc_html( $game_name ); ?></span>
                                <?php if ( $game_type ) : ?>
                                    <span class="sacga-room-type sacga-room-type-<?php echo esc_attr( $game_type ); ?>"><?php echo esc_html( ucfirst( $game_type ) ); ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="sacga-room-body">
                                <div class="sacga-room-code">
                                    <span class="sacga-room-code-label"><?php echo esc_html__( 'Room Code', 'shortcode-arcade' ); ?></span>
                                    <span class="sacga-room-code-value"><?php echo esc_html( $room['room_code'] ); ?></span>
                                </div>

                                <div class="sacga-room-info">
                                    <?php if ( $show_players ) : ?>
                                        <div class="sacga-room-players">
                                            <span class="dashicons dashicons-groups"></span>
                                            <span class="sacga-room-players-count">
                                                <?php
                                                /* translators: %1$d: current players, %2$d: max players */
                                                printf( esc_html__( '%1$d / %2$d players', 'shortcode-arcade' ), $player_count, $max_players );
                                                ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="sacga-room-seats <?php echo $seats_available <= 1 ? 'sacga-room-seats-low' : ''; ?>">
                                        <span class="dashicons dashicons-admin-users"></span>
                                        <span class="sacga-room-seats-count">
                                            <?php
                                            /* translators: %d: number of available seats */
                                            printf( esc_html( _n( '%d seat open', '%d seats open', $seats_available, 'shortcode-arcade' ) ), $seats_available );
                                            ?>
                                        </span>
                                    </div>

                                    <?php if ( $show_created && ! empty( $room['created_at'] ) ) : ?>
                                        <div class="sacga-room-created">
                                            <span class="dashicons dashicons-clock"></span>
                                            <span class="sacga-room-created-time">
                                                <?php
                                                /* translators: %s: human-readable time difference */
                                                printf( esc_html__( '%s ago', 'shortcode-arcade' ), human_time_diff( strtotime( $room['created_at'] ) ) );
                                                ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="sacga-room-status">
                                    <?php if ( $room['status'] === 'lobby' ) : ?>
                                        <span class="sacga-status-badge sacga-status-lobby">
                                            <span class="dashicons dashicons-clock"></span>
                                            <?php echo esc_html__( 'Waiting', 'shortcode-arcade' ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="sacga-status-badge sacga-status-active">
                                            <span class="dashicons dashicons-controls-play"></span>
                                            <?php echo esc_html__( 'In Progress', 'shortcode-arcade' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="sacga-room-footer">
                                <a href="<?php echo esc_url( $join_url ); ?>" class="sacga-btn sacga-btn-join">
                                    <span class="dashicons dashicons-migrate"></span>
                                    <?php echo esc_html__( 'Join Room', 'shortcode-arcade' ); ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( $refresh > 0 ) : ?>
                <div class="sacga-rooms-refresh-indicator">
                    <span class="dashicons dashicons-update"></span>
                    <?php
                    /* translators: %d: number of seconds */
                    printf( esc_html__( 'Auto-refreshing every %d seconds', 'shortcode-arcade' ), $refresh );
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get available rooms from database
     */
    private static function get_available_rooms( string $game_filter = '', int $limit = 10 ): array {
        global $wpdb;

        $registry = SACGA()->get_game_registry();

        // Build query
        $sql = "SELECT r.*, COUNT(p.id) as player_count
                FROM {$wpdb->prefix}sacga_rooms r
                LEFT JOIN {$wpdb->prefix}sacga_room_players p ON r.id = p.room_id
                WHERE r.status IN ('lobby', 'active')
                AND r.expires_at > NOW()";

        // Filter by game if specified
        if ( ! empty( $game_filter ) ) {
            $sql .= $wpdb->prepare( " AND r.game_id = %s", $game_filter );
        }

        $sql .= " GROUP BY r.id";

        // Filter out full rooms - need subquery or HAVING
        $sql .= " HAVING player_count < (
            CASE r.game_id ";

        // Get max players for each game dynamically
        $games = $registry->get_all_metadata();
        foreach ( $games as $id => $meta ) {
            $sql .= $wpdb->prepare( " WHEN %s THEN %d", $id, $meta['max_players'] );
        }
        $sql .= " ELSE 4 END)";

        $sql .= " ORDER BY r.status ASC, r.created_at DESC";
        $sql .= $wpdb->prepare( " LIMIT %d", $limit );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * AJAX handler for refreshing rooms list
     */
    public static function ajax_refresh_rooms(): void {
        check_ajax_referer( 'sacga_rooms_refresh', 'nonce' );

        $game  = isset( $_POST['game'] ) ? sanitize_text_field( wp_unslash( $_POST['game'] ) ) : '';
        $limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 10;

        $rooms = self::get_available_rooms( $game, $limit );
        $registry = SACGA()->get_game_registry();

        $result = [];
        foreach ( $rooms as $room ) {
            $game_obj = $registry->get( $room['game_id'] );
            $meta = $game_obj ? $game_obj->register_game() : null;

            $max_players = $meta ? (int) $meta['max_players'] : 4;
            $player_count = (int) $room['player_count'];

            $result[] = [
                'room_code'     => $room['room_code'],
                'game_id'       => $room['game_id'],
                'game_name'     => $meta ? $meta['name'] : ucfirst( $room['game_id'] ),
                'game_type'     => $meta ? $meta['type'] : '',
                'status'        => $room['status'],
                'player_count'  => $player_count,
                'max_players'   => $max_players,
                'is_full'       => $player_count >= $max_players,
                'created_at'    => $room['created_at'],
                'created_ago'   => human_time_diff( strtotime( $room['created_at'] ) ),
            ];
        }

        wp_send_json_success( $result );
    }

    /**
     * Enqueue game-specific assets
     */
    private static function enqueue_game_assets( string $game_id, string $game_type ): void {
        $js_file = SACGA_PLUGIN_DIR . "assets/js/games/sacga-{$game_id}.js";
        $base_css = '';

        if ( $game_type === 'card' ) {
            $base_css = SACGA_PLUGIN_DIR . 'assets/css/games/cards-base.css';
        } elseif ( $game_type === 'dice' ) {
            $base_css = SACGA_PLUGIN_DIR . 'assets/css/games/dice-base.css';
        }

        $base_handle = '';
        if ( $base_css && file_exists( $base_css ) ) {
            $base_handle = $game_type === 'card' ? 'sacga-cards-base' : 'sacga-dice-base';
            wp_enqueue_style(
                $base_handle,
                SACGA_PLUGIN_URL . str_replace( SACGA_PLUGIN_DIR, '', $base_css ),
                [ 'sacga-styles' ],
                SACGA_VERSION
            );
        }

        $css_file = SACGA_PLUGIN_DIR . "assets/css/games/{$game_id}.css";
        $legacy_css_file = SACGA_PLUGIN_DIR . "assets/css/games/sacga-{$game_id}.css";
        $css_file = file_exists( $css_file ) ? $css_file : $legacy_css_file;

        if ( file_exists( $js_file ) ) {
            wp_enqueue_script(
                "sacga-game-{$game_id}",
                SACGA_PLUGIN_URL . "assets/js/games/sacga-{$game_id}.js",
                [ 'sacga-engine', 'jquery', 'wp-i18n' ],
                SACGA_VERSION,
                true
            );
            wp_set_script_translations( "sacga-game-{$game_id}", 'shortcode-arcade', SACGA_PLUGIN_DIR . 'languages' );
        }

        if ( $css_file && file_exists( $css_file ) ) {
            $css_deps = [ 'sacga-styles' ];
            if ( $base_handle ) {
                $css_deps[] = $base_handle;
            }
            wp_enqueue_style(
                "sacga-game-{$game_id}",
                SACGA_PLUGIN_URL . str_replace( SACGA_PLUGIN_DIR, '', $css_file ),
                $css_deps,
                SACGA_VERSION
            );
        }
    }

    /**
     * Render game rules
     * Usage: [sacga_rules game="checkers" layout="sections" show_title="true"]
     * Usage: [sacga_rules] - Shows all games with rules (master rules page)
     *
     * @param array $atts Shortcode attributes.
     * @return string Rendered HTML.
     */
    public static function render_rules( array $atts ): string {
        $atts = shortcode_atts( [
            'game'       => '',
            'layout'     => 'sections',
            'show_title' => 'true',
        ], $atts );

        $game_id    = sanitize_text_field( $atts['game'] );
        $layout     = in_array( $atts['layout'], [ 'sections', 'compact' ], true ) ? $atts['layout'] : 'sections';
        $show_title = filter_var( $atts['show_title'], FILTER_VALIDATE_BOOLEAN );

        // Enqueue rules styles
        self::enqueue_rules_assets();

        // No game specified - render master rules page with all games
        if ( empty( $game_id ) ) {
            return self::render_all_rules( $layout, $show_title );
        }

        $registry = SACGA()->get_game_registry();
        $game     = $registry->get( $game_id );

        // Game not found
        if ( ! $game ) {
            /* translators: %s: game identifier */
            return '<div class="sacga-rules-error">' . sprintf( esc_html__( 'Game not found: %s', 'shortcode-arcade' ), esc_html( $game_id ) ) . '</div>';
        }

        $meta  = $game->register_game();
        $rules = $meta['rules'] ?? null;

        // No rules defined
        if ( empty( $rules ) ) {
            return '<div class="sacga-game-rules sacga-rules-empty">' .
                   '<p>' . esc_html__( 'Rules are not available for this game yet.', 'shortcode-arcade' ) . '</p>' .
                   '</div>';
        }

        // Normalize rules to structured format
        $structured_rules = self::normalize_rules( $rules );

        // Render based on layout
        if ( $layout === 'compact' ) {
            return self::render_rules_compact( $meta, $structured_rules, $show_title );
        }

        return self::render_rules_sections( $meta, $structured_rules, $show_title );
    }

    /**
     * Render master rules page with all games (accordion style)
     *
     * @param string $layout Layout type (sections or compact).
     * @param bool   $show_title Whether to show titles.
     * @return string Rendered HTML.
     */
    private static function render_all_rules( string $layout, bool $show_title ): string {
        $registry = SACGA()->get_game_registry();
        $all_games = $registry->get_all_metadata();

        if ( empty( $all_games ) ) {
            return '<div class="sacga-rules-error">' . esc_html__( 'No games available.', 'shortcode-arcade' ) . '</div>';
        }

        // Filter to only games with rules and sort alphabetically
        $games_with_rules = [];
        foreach ( $all_games as $game_id => $meta ) {
            if ( ! empty( $meta['rules'] ) ) {
                $games_with_rules[ $game_id ] = $meta;
            }
        }

        if ( empty( $games_with_rules ) ) {
            return '<div class="sacga-game-rules sacga-rules-empty">' .
                   '<p>' . esc_html__( 'No game rules are available yet.', 'shortcode-arcade' ) . '</p>' .
                   '</div>';
        }

        // Sort by game name
        uasort( $games_with_rules, function( $a, $b ) {
            return strcasecmp( $a['name'], $b['name'] );
        } );

        // Group games by type
        $game_types = [
            'board' => [ 'label' => __( 'Board Games', 'shortcode-arcade' ), 'icon' => 'dashicons-grid-view', 'games' => [] ],
            'card'  => [ 'label' => __( 'Card Games', 'shortcode-arcade' ), 'icon' => 'dashicons-index-card', 'games' => [] ],
            'dice'  => [ 'label' => __( 'Dice Games', 'shortcode-arcade' ), 'icon' => 'dashicons-image-rotate', 'games' => [] ],
        ];

        foreach ( $games_with_rules as $game_id => $meta ) {
            $type = $meta['type'] ?? 'other';
            if ( isset( $game_types[ $type ] ) ) {
                $game_types[ $type ]['games'][ $game_id ] = $meta;
            }
        }

        ob_start();
        ?>
        <div class="sacga-rules-master" id="sacga-rules-top">
            <header class="sacga-rules-master-header">
                <h1 class="sacga-rules-master-title"><?php echo esc_html__( 'Game Rules', 'shortcode-arcade' ); ?></h1>
                <p class="sacga-rules-master-desc"><?php echo esc_html__( 'Click on any game to view its official rules.', 'shortcode-arcade' ); ?></p>
                <div class="sacga-rules-actions">
                    <button type="button" class="sacga-rules-expand-all sacga-btn sacga-btn-secondary">
                        <span class="dashicons dashicons-editor-expand"></span>
                        <?php echo esc_html__( 'Expand All', 'shortcode-arcade' ); ?>
                    </button>
                    <button type="button" class="sacga-rules-collapse-all sacga-btn sacga-btn-secondary">
                        <span class="dashicons dashicons-editor-contract"></span>
                        <?php echo esc_html__( 'Collapse All', 'shortcode-arcade' ); ?>
                    </button>
                </div>
            </header>

            <!-- Accordion by Game Type -->
            <?php foreach ( $game_types as $type => $type_data ) :
                if ( empty( $type_data['games'] ) ) continue;
            ?>
                <section class="sacga-rules-type-section">
                    <h2 class="sacga-rules-type-heading">
                        <span class="dashicons <?php echo esc_attr( $type_data['icon'] ); ?>"></span>
                        <?php echo esc_html( $type_data['label'] ); ?>
                        <span class="sacga-rules-type-count">(<?php echo count( $type_data['games'] ); ?>)</span>
                    </h2>

                    <div class="sacga-rules-accordion">
                        <?php foreach ( $type_data['games'] as $game_id => $meta ) :
                            $structured_rules = self::normalize_rules( $meta['rules'] );
                            $player_range = $meta['min_players'] === $meta['max_players']
                                ? $meta['min_players']
                                : $meta['min_players'] . '-' . $meta['max_players'];
                        ?>
                            <details class="sacga-rules-panel" id="rules-<?php echo esc_attr( $game_id ); ?>">
                                <summary class="sacga-rules-panel-header">
                                    <span class="sacga-rules-panel-title"><?php echo esc_html( $meta['name'] ); ?></span>
                                    <span class="sacga-rules-panel-meta">
                                        <span class="sacga-rules-players">
                                            <span class="dashicons dashicons-groups"></span>
                                            <?php echo esc_html( $player_range ); ?>
                                        </span>
                                        <?php if ( ! empty( $meta['ai_supported'] ) ) : ?>
                                            <span class="sacga-rules-ai" title="<?php echo esc_attr__( 'AI Available', 'shortcode-arcade' ); ?>">
                                                <span class="dashicons dashicons-admin-generic"></span>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="sacga-rules-panel-icon"></span>
                                </summary>
                                <div class="sacga-rules-panel-content">
                                    <?php
                                    $sections = [
                                        'objective' => __( 'Objective', 'shortcode-arcade' ),
                                        'setup'     => __( 'Setup', 'shortcode-arcade' ),
                                        'gameplay'  => __( 'Gameplay', 'shortcode-arcade' ),
                                        'winning'   => __( 'Winning', 'shortcode-arcade' ),
                                        'notes'     => __( 'Notes', 'shortcode-arcade' ),
                                    ];
                                    foreach ( $sections as $key => $label ) :
                                        $content = trim( $structured_rules[ $key ] ?? '' );
                                        if ( empty( $content ) ) continue;
                                    ?>
                                        <div class="sacga-rules-section sacga-rules-section-<?php echo esc_attr( $key ); ?>">
                                            <h4 class="sacga-rules-heading"><?php echo esc_html( $label ); ?></h4>
                                            <div class="sacga-rules-content">
                                                <?php echo self::format_rules_content( $content ); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Normalize rules to structured format
     * Supports both legacy array format and new structured format
     *
     * @param array $rules Raw rules data.
     * @return array Normalized structured rules.
     */
    private static function normalize_rules( $rules ): array {
        // Already structured format
        if ( isset( $rules['objective'] ) || isset( $rules['setup'] ) || isset( $rules['gameplay'] ) || isset( $rules['winning'] ) ) {
            return [
                'objective' => $rules['objective'] ?? '',
                'setup'     => $rules['setup'] ?? '',
                'gameplay'  => $rules['gameplay'] ?? '',
                'winning'   => $rules['winning'] ?? '',
                'notes'     => $rules['notes'] ?? '',
            ];
        }

        // Legacy array format - convert intelligently
        if ( is_array( $rules ) && isset( $rules[0] ) ) {
            return self::convert_legacy_rules( $rules );
        }

        // String format - put in gameplay
        if ( is_string( $rules ) ) {
            return [
                'objective' => '',
                'setup'     => '',
                'gameplay'  => $rules,
                'winning'   => '',
                'notes'     => '',
            ];
        }

        return [
            'objective' => '',
            'setup'     => '',
            'gameplay'  => '',
            'winning'   => '',
            'notes'     => '',
        ];
    }

    /**
     * Convert legacy rules array to structured format
     * Intelligently categorizes rules based on keywords
     *
     * @param array $rules Legacy rules array.
     * @return array Structured rules.
     */
    private static function convert_legacy_rules( array $rules ): array {
        $structured = [
            'objective' => [],
            'setup'     => [],
            'gameplay'  => [],
            'winning'   => [],
            'notes'     => [],
        ];

        $objective_keywords = [ 'goal', 'objective', 'aim', 'object', 'purpose' ];
        $setup_keywords     = [ 'players', 'deck', 'deal', 'setup', 'start', 'begin', 'cards each' ];
        $gameplay_keywords  = [ 'turn', 'play', 'move', 'draw', 'discard', 'action', 'phase', 'meld', 'bet', 'fold', 'call', 'raise' ];
        $winning_keywords   = [ 'win', 'winning', 'winner', 'end', 'game over', 'score', 'scoring', 'points', 'loses', 'victory' ];

        foreach ( $rules as $rule ) {
            $rule_lower = strtolower( $rule );
            $categorized = false;

            // Check objective
            foreach ( $objective_keywords as $keyword ) {
                if ( strpos( $rule_lower, $keyword ) !== false ) {
                    $structured['objective'][] = $rule;
                    $categorized = true;
                    break;
                }
            }

            if ( $categorized ) continue;

            // Check winning (before gameplay to catch "winning" before "play")
            foreach ( $winning_keywords as $keyword ) {
                if ( strpos( $rule_lower, $keyword ) !== false ) {
                    $structured['winning'][] = $rule;
                    $categorized = true;
                    break;
                }
            }

            if ( $categorized ) continue;

            // Check setup
            foreach ( $setup_keywords as $keyword ) {
                if ( strpos( $rule_lower, $keyword ) !== false ) {
                    $structured['setup'][] = $rule;
                    $categorized = true;
                    break;
                }
            }

            if ( $categorized ) continue;

            // Check gameplay
            foreach ( $gameplay_keywords as $keyword ) {
                if ( strpos( $rule_lower, $keyword ) !== false ) {
                    $structured['gameplay'][] = $rule;
                    $categorized = true;
                    break;
                }
            }

            if ( $categorized ) continue;

            // Default to gameplay
            $structured['gameplay'][] = $rule;
        }

        // Convert arrays to strings
        return [
            'objective' => implode( ' ', $structured['objective'] ),
            'setup'     => implode( "\n", $structured['setup'] ),
            'gameplay'  => implode( "\n", $structured['gameplay'] ),
            'winning'   => implode( ' ', $structured['winning'] ),
            'notes'     => implode( "\n", $structured['notes'] ),
        ];
    }

    /**
     * Render rules in sections layout (full)
     *
     * @param array $meta Game metadata.
     * @param array $rules Structured rules.
     * @param bool  $show_title Whether to show title.
     * @return string Rendered HTML.
     */
    private static function render_rules_sections( array $meta, array $rules, bool $show_title ): string {
        $sections = [
            'objective' => __( 'Objective', 'shortcode-arcade' ),
            'setup'     => __( 'Setup', 'shortcode-arcade' ),
            'gameplay'  => __( 'Gameplay', 'shortcode-arcade' ),
            'winning'   => __( 'Winning', 'shortcode-arcade' ),
            'notes'     => __( 'Notes', 'shortcode-arcade' ),
        ];

        ob_start();
        ?>
        <div class="sacga-game-rules sacga-rules-sections">
            <?php if ( $show_title ) : ?>
                <h2 class="sacga-rules-title">
                    <?php
                    /* translators: %s: game name */
                    printf( esc_html__( '%s Rules', 'shortcode-arcade' ), esc_html( $meta['name'] ) );
                    ?>
                </h2>
            <?php endif; ?>

            <div class="sacga-rules-body">
                <?php foreach ( $sections as $key => $label ) :
                    $content = trim( $rules[ $key ] ?? '' );
                    if ( empty( $content ) ) continue;
                ?>
                    <section class="sacga-rules-section sacga-rules-section-<?php echo esc_attr( $key ); ?>">
                        <h3 class="sacga-rules-heading"><?php echo esc_html( $label ); ?></h3>
                        <div class="sacga-rules-content">
                            <?php echo self::format_rules_content( $content ); ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render rules in compact layout
     *
     * @param array $meta Game metadata.
     * @param array $rules Structured rules.
     * @param bool  $show_title Whether to show title.
     * @return string Rendered HTML.
     */
    private static function render_rules_compact( array $meta, array $rules, bool $show_title ): string {
        ob_start();
        ?>
        <div class="sacga-game-rules sacga-rules-compact">
            <?php if ( $show_title ) : ?>
                <h3 class="sacga-rules-title">
                    <?php
                    /* translators: %s: game name */
                    printf( esc_html__( '%s Quick Reference', 'shortcode-arcade' ), esc_html( $meta['name'] ) );
                    ?>
                </h3>
            <?php endif; ?>

            <div class="sacga-rules-body">
                <?php
                // Combine non-empty sections
                $combined = [];
                if ( ! empty( trim( $rules['objective'] ) ) ) {
                    $combined[] = '<strong>' . esc_html__( 'Goal:', 'shortcode-arcade' ) . '</strong> ' . esc_html( trim( $rules['objective'] ) );
                }
                if ( ! empty( trim( $rules['setup'] ) ) ) {
                    $combined[] = '<strong>' . esc_html__( 'Setup:', 'shortcode-arcade' ) . '</strong> ' . esc_html( self::flatten_content( $rules['setup'] ) );
                }
                if ( ! empty( trim( $rules['gameplay'] ) ) ) {
                    $combined[] = '<strong>' . esc_html__( 'Play:', 'shortcode-arcade' ) . '</strong> ' . esc_html( self::flatten_content( $rules['gameplay'] ) );
                }
                if ( ! empty( trim( $rules['winning'] ) ) ) {
                    $combined[] = '<strong>' . esc_html__( 'Win:', 'shortcode-arcade' ) . '</strong> ' . esc_html( trim( $rules['winning'] ) );
                }

                echo wp_kses_post( implode( ' &bull; ', $combined ) );
                ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Format rules content for display
     * Converts newlines to list items or paragraphs
     *
     * @param string $content Raw content.
     * @return string Formatted HTML.
     */
    private static function format_rules_content( string $content ): string {
        $lines = array_filter( array_map( 'trim', explode( "\n", $content ) ) );

        if ( count( $lines ) <= 1 ) {
            return '<p>' . esc_html( $content ) . '</p>';
        }

        // Multiple lines - create a list
        $output = '<ul class="sacga-rules-list">';
        foreach ( $lines as $line ) {
            $output .= '<li>' . esc_html( $line ) . '</li>';
        }
        $output .= '</ul>';

        return $output;
    }

    /**
     * Flatten multiline content to single line
     *
     * @param string $content Content with possible newlines.
     * @return string Flattened content.
     */
    private static function flatten_content( string $content ): string {
        return preg_replace( '/\s+/', ' ', trim( $content ) );
    }

    /**
     * Enqueue rules assets (CSS and JS)
     */
    private static function enqueue_rules_assets(): void {
        wp_enqueue_style(
            'sacga-rules',
            SACGA_PLUGIN_URL . 'assets/css/sacga-rules.css',
            [],
            SACGA_VERSION
        );

        wp_enqueue_script(
            'sacga-rules-modal',
            SACGA_PLUGIN_URL . 'assets/js/sacga-rules-modal.js',
            [ 'jquery' ],
            SACGA_VERSION,
            true
        );

        wp_localize_script( 'sacga-rules-modal', 'sacgaRulesConfig', [
            'closeLabel' => __( 'Close', 'shortcode-arcade' ),
        ] );
    }

    /**
     * Render rules HTML for modal usage (returns just the content)
     *
     * @param string $game_id Game ID.
     * @return string Rules HTML for modal.
     */
    public static function get_rules_html_for_modal( string $game_id ): string {
        $registry = SACGA()->get_game_registry();
        $game     = $registry->get( $game_id );

        if ( ! $game ) {
            return '<p>' . esc_html__( 'Rules not available.', 'shortcode-arcade' ) . '</p>';
        }

        $meta  = $game->register_game();
        $rules = $meta['rules'] ?? null;

        if ( empty( $rules ) ) {
            return '<p>' . esc_html__( 'Rules are not available for this game yet.', 'shortcode-arcade' ) . '</p>';
        }

        $structured_rules = self::normalize_rules( $rules );

        // Render sections without outer container
        $sections = [
            'objective' => __( 'Objective', 'shortcode-arcade' ),
            'setup'     => __( 'Setup', 'shortcode-arcade' ),
            'gameplay'  => __( 'Gameplay', 'shortcode-arcade' ),
            'winning'   => __( 'Winning', 'shortcode-arcade' ),
            'notes'     => __( 'Notes', 'shortcode-arcade' ),
        ];

        ob_start();
        foreach ( $sections as $key => $label ) :
            $content = trim( $structured_rules[ $key ] ?? '' );
            if ( empty( $content ) ) continue;
        ?>
            <section class="sacga-rules-section sacga-rules-section-<?php echo esc_attr( $key ); ?>">
                <h4 class="sacga-rules-heading"><?php echo esc_html( $label ); ?></h4>
                <div class="sacga-rules-content">
                    <?php echo self::format_rules_content( $content ); ?>
                </div>
            </section>
        <?php endforeach;
        return ob_get_clean();
    }
}
