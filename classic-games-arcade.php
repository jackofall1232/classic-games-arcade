<?php
/**
 * Plugin Name: Classic Games Arcade
 * Plugin URI: https://shortcodearcade.com/classic-games-arcade
 * Description: A modular WordPress arcade for classic card and board games with room-based multiplayer and AI opponents.
 * Version: 0.1.0
 * Author: Shortcode Arcade
 * Author URI: https://shortcodearcade.com
 * License: GPL-2.0+
 * Text Domain: shortcode-arcade
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SACGA_VERSION', '0.1.0' );
define( 'SACGA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SACGA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check if Pro features are available (stub for now)
 */
function sacga_is_pro() {
    return true; // For now, enable all features
}

/**
 * Main Plugin Class
 */
final class SACGA_Classic_Games_Arcade {

    private static $instance = null;
    private $game_registry = null;
    private $room_manager = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        // Traits
        require_once SACGA_PLUGIN_DIR . 'includes/traits/trait-turn-gate.php';

        // Core engine
        require_once SACGA_PLUGIN_DIR . 'includes/engine/trait-sacga-card-game.php';
        require_once SACGA_PLUGIN_DIR . 'includes/engine/class-sacga-game-contract.php';
        require_once SACGA_PLUGIN_DIR . 'includes/engine/class-sacga-game-registry.php';
        require_once SACGA_PLUGIN_DIR . 'includes/engine/class-sacga-room-manager.php';
        require_once SACGA_PLUGIN_DIR . 'includes/engine/class-sacga-game-state.php';
        require_once SACGA_PLUGIN_DIR . 'includes/engine/class-sacga-ai-engine.php';

        // REST API
        require_once SACGA_PLUGIN_DIR . 'includes/rest/class-sacga-rest-controller.php';

        // Shortcodes
        require_once SACGA_PLUGIN_DIR . 'includes/shortcodes/class-sacga-shortcodes.php';

        // Admin
        if ( is_admin() ) {
            require_once SACGA_PLUGIN_DIR . 'admin/class-sacga-admin.php';
        }
    }

    private function init_hooks() {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

        // Set cookie early before any output
        add_action( 'plugins_loaded', [ $this, 'maybe_set_guest_token' ], 1 );
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
        add_action( 'init', [ $this, 'init' ], 5 );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'sacga_cleanup_expired_rooms', [ $this, 'cleanup_expired_rooms' ] );
        add_action( 'admin_notices', [ $this, 'check_tables_admin_notice' ] );
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );

        // Clear arcade URL cache when posts are saved (might contain shortcode)
        add_action( 'save_post', [ $this, 'clear_arcade_url_cache' ] );
    }

    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'shortcode-arcade', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public function init() {
        $this->game_registry = new SACGA_Game_Registry();
        $this->room_manager = new SACGA_Room_Manager();

        // Discover and load games
        $this->game_registry->discover_games();

        // Register shortcodes
        SACGA_Shortcodes::register();

        // Register AJAX handlers for rooms refresh
        add_action( 'wp_ajax_sacga_refresh_rooms', [ 'SACGA_Shortcodes', 'ajax_refresh_rooms' ] );
        add_action( 'wp_ajax_nopriv_sacga_refresh_rooms', [ 'SACGA_Shortcodes', 'ajax_refresh_rooms' ] );
    }

    public function register_rest_routes() {
        $controller = new SACGA_REST_Controller();
        $controller->register_routes();
    }

    public function enqueue_scripts() {
        if ( ! $this->should_load_assets() ) {
            return;
        }

        // Core styles
        wp_enqueue_style(
            'sacga-styles',
            SACGA_PLUGIN_URL . 'assets/css/sacga-core.css',
            [],
            SACGA_VERSION
        );

        // Dashicons for icons
        wp_enqueue_style( 'dashicons' );

        // Card utilities (shared for card games)
        wp_enqueue_script(
            'sacga-cards',
            SACGA_PLUGIN_URL . 'assets/js/sacga-cards.js',
            [ 'jquery', 'wp-i18n' ],
            SACGA_VERSION,
            true
        );

        // Dice utilities (shared for dice games)
        wp_enqueue_script(
            'sacga-dice',
            SACGA_PLUGIN_URL . 'assets/js/sacga-dice.js',
            [ 'jquery', 'wp-i18n' ],
            SACGA_VERSION,
            true
        );

        // Core engine
        wp_enqueue_script(
            'sacga-engine',
            SACGA_PLUGIN_URL . 'assets/js/sacga-engine.js',
            [ 'jquery', 'wp-i18n', 'sacga-cards', 'sacga-dice' ],
            SACGA_VERSION,
            true
        );

        // Set script translations for i18n
        wp_set_script_translations( 'sacga-engine', 'shortcode-arcade', SACGA_PLUGIN_DIR . 'languages' );
        wp_set_script_translations( 'sacga-cards', 'shortcode-arcade', SACGA_PLUGIN_DIR . 'languages' );
        wp_set_script_translations( 'sacga-dice', 'shortcode-arcade', SACGA_PLUGIN_DIR . 'languages' );

        $guest_data = $this->get_guest_token_for_response();

        wp_localize_script( 'sacga-engine', 'sacgaConfig', [
            'restUrl'      => rest_url( 'sacga/v1/' ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'pollInterval' => 2000,
            'userId'       => get_current_user_id(),
            'guestToken'   => $guest_data['token'] ?? null,
            'guestId'      => $guest_data['guest_id'] ?? null,
            'arcadeUrl'    => $this->get_arcade_page_url(),
        ] );
    }

    private function should_load_assets() {
        global $post;
        if ( ! $post ) {
            return false;
        }
        return has_shortcode( $post->post_content, 'sacga_game' ) ||
               has_shortcode( $post->post_content, 'classic_games_arcade' ) ||
               has_shortcode( $post->post_content, 'sacga_rules' );
    }

    public function maybe_set_guest_token() {
        if ( is_user_logged_in() ) {
            return;
        }

        $data = $this->get_guest_token_data();
        if ( $data && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[SACGA] Guest token active: ' . substr( $data['token'], 0, 12 ) . '... (secure=' . ( is_ssl() ? 'yes' : 'no' ) . ')' );
        }
    }

    private function get_guest_token_data(): ?array {
        if ( is_user_logged_in() ) {
            return null;
        }

        $token = isset( $_COOKIE['sacga_guest_token'] ) ? sanitize_text_field( $_COOKIE['sacga_guest_token'] ) : null;
        if ( $token ) {
            $validated = $this->validate_guest_token( $token );
            if ( ! is_wp_error( $validated ) ) {
                return [
                    'token'    => $token,
                    'guest_id' => $validated['guest_id'],
                    'expires'  => $validated['exp'],
                ];
            }
        }

        $guest_id = wp_generate_uuid4();
        $expires = time() + $this->get_guest_token_ttl();
        $token = $this->generate_guest_token( $guest_id, $expires );

        $this->set_guest_token_cookie( $token, $expires );
        $_COOKIE['sacga_guest_token'] = $token;

        return [
            'token'    => $token,
            'guest_id' => $guest_id,
            'expires'  => $expires,
        ];
    }

    private function get_guest_token_ttl(): int {
        return (int) apply_filters( 'sacga_guest_token_ttl', DAY_IN_SECONDS );
    }

    private function set_guest_token_cookie( string $token, int $expires ): void {
        if ( headers_sent() ) {
            return;
        }

        // Use more permissive cookie settings for better cross-site compatibility
        // Path: '/' instead of COOKIEPATH for wider availability
        // Domain: '' (empty) instead of COOKIE_DOMAIN to work on all subdomains
        // SameSite: Lax for better CSRF protection while allowing cross-site navigation
        $secure = is_ssl(); // Only set Secure flag on HTTPS
        $httponly = true;   // Prevent JavaScript access for security
        $path = '/';
        $domain = '';

        if ( PHP_VERSION_ID >= 70300 ) {
            setcookie( 'sacga_guest_token', $token, [
                'expires' => $expires,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => 'Lax'
            ] );
        } else {
            setcookie( 'sacga_guest_token', $token, $expires, $path, $domain, $secure, $httponly );
        }
    }

    private function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    private function base64url_decode( string $data ): string {
        $remainder = strlen( $data ) % 4;
        if ( $remainder ) {
            $data .= str_repeat( '=', 4 - $remainder );
        }
        return (string) base64_decode( strtr( $data, '-_', '+/' ) );
    }

    public function generate_guest_token( string $guest_id, int $expires, ?string $room_code = null ): string {
        $payload = [
            'guest_id' => $guest_id,
            'exp'      => $expires,
        ];

        if ( $room_code ) {
            $payload['room'] = $room_code;
        }

        $payload_json = wp_json_encode( $payload );
        $payload_b64 = $this->base64url_encode( $payload_json );
        $signature = hash_hmac( 'sha256', $payload_b64, wp_salt( 'auth' ) );

        return $payload_b64 . '.' . $signature;
    }

    public function validate_guest_token( string $token, ?string $room_code = null ) {
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 2 ) {
            return new WP_Error( 'guest_token_invalid', __( 'Invalid guest token.', 'shortcode-arcade' ), [ 'status' => 403 ] );
        }

        [ $payload_b64, $signature ] = $parts;
        $expected = hash_hmac( 'sha256', $payload_b64, wp_salt( 'auth' ) );

        if ( ! hash_equals( $expected, $signature ) ) {
            return new WP_Error( 'guest_token_invalid', __( 'Invalid guest token signature.', 'shortcode-arcade' ), [ 'status' => 403 ] );
        }

        $payload_json = $this->base64url_decode( $payload_b64 );
        $payload = json_decode( $payload_json, true );

        if ( ! is_array( $payload ) || empty( $payload['guest_id'] ) || empty( $payload['exp'] ) ) {
            return new WP_Error( 'guest_token_invalid', __( 'Invalid guest token payload.', 'shortcode-arcade' ), [ 'status' => 403 ] );
        }

        if ( time() > (int) $payload['exp'] ) {
            return new WP_Error( 'guest_token_expired', __( 'Guest token expired.', 'shortcode-arcade' ), [ 'status' => 401 ] );
        }

        if ( ! empty( $payload['room'] ) && $room_code && strtoupper( (string) $payload['room'] ) !== strtoupper( $room_code ) ) {
            return new WP_Error( 'guest_token_invalid', __( 'Guest token does not match this room.', 'shortcode-arcade' ), [ 'status' => 403 ] );
        }

        return $payload;
    }

    public function get_guest_token_for_response(): ?array {
        return $this->get_guest_token_data();
    }

    public function activate() {
        // Clear previous failure flag
        delete_option( 'sacga_table_creation_failed' );

        // Migrate existing schema first
        $this->migrate_schema();

        // Create/update tables
        $this->create_tables();

        // Add custom cron schedule for activation
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );

        // Clear any existing scheduled events first
        $timestamp = wp_next_scheduled( 'sacga_cleanup_expired_rooms' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'sacga_cleanup_expired_rooms' );
        }

        // Schedule room cleanup every 15 minutes
        wp_schedule_event( time(), 'sacga_15min', 'sacga_cleanup_expired_rooms' );

        flush_rewrite_rules();
    }

    public function deactivate() {
        // Clear scheduled cleanup
        $timestamp = wp_next_scheduled( 'sacga_cleanup_expired_rooms' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'sacga_cleanup_expired_rooms' );
        }

        flush_rewrite_rules();
    }

    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules( $schedules ) {
        $schedules['sacga_15min'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => esc_html__( 'Every 15 minutes', 'shortcode-arcade' ),
        ];
        return $schedules;
    }

    /**
     * Cleanup expired rooms cron callback
     */
    public function cleanup_expired_rooms() {
        if ( ! $this->room_manager ) {
            $this->room_manager = new SACGA_Room_Manager();
        }

        $count = $this->room_manager->cleanup_expired_rooms();

        // Log cleanup for debugging
        if ( $count > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( 'SACGA: Cleaned up %d expired room(s)', $count ) );
        }
    }

    /**
     * Check if all required tables exist
     */
    private function tables_exist(): bool {
        global $wpdb;
        $required_tables = [ 'sacga_rooms', 'sacga_room_players', 'sacga_game_state' ];

        foreach ( $required_tables as $table ) {
            $table_exists = $wpdb->get_var( $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $wpdb->esc_like( $wpdb->prefix . $table )
            ) );

            if ( ! $table_exists ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ensure tables exist - creates them if missing
     * This is the recommended pattern: never rely solely on activation hook
     */
    public function ensure_tables_exist() {
        // Run schema migrations first (handles existing tables with old schema)
        $this->migrate_schema();

        if ( $this->tables_exist() ) {
            return true;
        }

        // Tables missing - attempt to create them
        error_log( '[SACGA] Tables missing - attempting to create them automatically' );
        return $this->create_tables();
    }

    /**
     * Migrate database schema for existing installations
     * Handles upgrading from old schema to new schema
     */
    private function migrate_schema(): void {
        global $wpdb;

        // Check if migration already completed (cached in WordPress options)
        $migration_version = get_option( 'sacga_schema_version', 0 );
        $current_version = 4; // Increment this when adding new migrations

        if ( $migration_version >= $current_version ) {
            return; // Already migrated to current version
        }

        $table_name = $wpdb->prefix . 'sacga_game_state';

        // Check if table exists
        $table_exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $wpdb->esc_like( $table_name )
        ) );

        if ( ! $table_exists ) {
            // Table doesn't exist yet, will be created fresh
            update_option( 'sacga_schema_version', $current_version );
            return;
        }

        // Check if we have the old 'state' column instead of 'game_data'
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}" );

        // Migration 1: Rename 'state' column to 'game_data'
        if ( in_array( 'state', $columns, true ) && ! in_array( 'game_data', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_name} CHANGE COLUMN `state` `game_data` longtext NOT NULL" );
        }

        // Migration 2: Add 'state_version' column if missing
        if ( ! in_array( 'state_version', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN `state_version` int(11) NOT NULL DEFAULT 1 AFTER `room_id`" );
        }

        // Migration 3: Add 'current_turn' column if missing
        if ( ! in_array( 'current_turn', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN `current_turn` int(11) NOT NULL DEFAULT 0 AFTER `state_version`" );
            $wpdb->query( "ALTER TABLE {$table_name} ADD KEY `current_turn` (`current_turn`)" );
        }

        // Migration 4: Add client_id, connected, last_seen columns to room_players for auto-rejoin
        $players_table = $wpdb->prefix . 'sacga_room_players';
        $players_table_exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $wpdb->esc_like( $players_table )
        ) );

        if ( $players_table_exists ) {
            $player_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$players_table}" );

            if ( ! in_array( 'client_id', $player_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$players_table} ADD COLUMN `client_id` varchar(36) DEFAULT NULL AFTER `guest_token`" );
                $wpdb->query( "ALTER TABLE {$players_table} ADD KEY `client_id` (`client_id`)" );
            }

            if ( ! in_array( 'connected', $player_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$players_table} ADD COLUMN `connected` tinyint(1) NOT NULL DEFAULT 1 AFTER `client_id`" );
            }

            if ( ! in_array( 'last_seen', $player_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$players_table} ADD COLUMN `last_seen` int(11) DEFAULT NULL AFTER `connected`" );
            }
        }

        // Mark migration as complete
        update_option( 'sacga_schema_version', $current_version );
    }

    private function create_tables(): bool {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // CRITICAL: Must be loaded inside the function that calls dbDelta
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        error_log( '[SACGA] Starting table creation process' );
        error_log( '[SACGA] Table prefix: ' . $wpdb->prefix );
        error_log( '[SACGA] Charset collate: ' . $charset_collate );

        // dbDelta is EXTREMELY picky about SQL formatting:
        // - Two spaces between column name and data type
        // - Two spaces before PRIMARY KEY
        // - Must use KEY not INDEX
        // - No backticks around field names
        // - Each field on its own line
        $tables = [
            'sacga_rooms' => "CREATE TABLE {$wpdb->prefix}sacga_rooms (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  room_code varchar(6) NOT NULL,
  game_id varchar(50) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'lobby',
  settings longtext,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY room_code (room_code),
  KEY status (status),
  KEY expires_at (expires_at)
) $charset_collate;",

            'sacga_room_players' => "CREATE TABLE {$wpdb->prefix}sacga_room_players (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  room_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  guest_token varchar(36) DEFAULT NULL,
  client_id varchar(36) DEFAULT NULL,
  connected tinyint(1) NOT NULL DEFAULT 1,
  last_seen int(11) DEFAULT NULL,
  display_name varchar(100) NOT NULL,
  seat_position tinyint(3) unsigned NOT NULL,
  is_ai tinyint(1) NOT NULL DEFAULT 0,
  ai_difficulty varchar(20) DEFAULT NULL,
  joined_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY room_id (room_id),
  KEY user_id (user_id),
  KEY guest_token (guest_token),
  KEY client_id (client_id)
) $charset_collate;",

            'sacga_game_state' => "CREATE TABLE {$wpdb->prefix}sacga_game_state (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  room_id bigint(20) unsigned NOT NULL,
  state_version int(11) NOT NULL DEFAULT 1,
  current_turn int(11) NOT NULL DEFAULT 0,
  game_data longtext NOT NULL,
  etag varchar(32) NOT NULL,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY room_id (room_id),
  KEY current_turn (current_turn)
) $charset_collate;"
        ];

        $all_success = true;

        foreach ( $tables as $table_name => $sql ) {
            error_log( "[SACGA] Attempting to create table: {$wpdb->prefix}{$table_name}" );

            // Log the exact SQL being used
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "[SACGA] SQL for {$table_name}:\n" . $sql );
            }

            $result = dbDelta( $sql );

            // Log dbDelta result
            error_log( "[SACGA] dbDelta result for {$table_name}: " . print_r( $result, true ) );

            // Verify table was created
            $table_exists = $wpdb->get_var( $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $wpdb->esc_like( $wpdb->prefix . $table_name )
            ) );

            if ( ! $table_exists ) {
                error_log( "[SACGA] CRITICAL: Failed to create table {$wpdb->prefix}{$table_name}" );
                error_log( "[SACGA] Last database error: " . $wpdb->last_error );
                $all_success = false;
                update_option( 'sacga_table_creation_failed', true );
            } else {
                error_log( "[SACGA] Successfully verified table {$wpdb->prefix}{$table_name}" );
            }
        }

        if ( $all_success ) {
            delete_option( 'sacga_table_creation_failed' );
        }

        return $all_success;
    }

    /**
     * Check if tables exist and show admin notice if not
     */
    public function check_tables_admin_notice() {
        if ( ! is_admin() || ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        // Handle manual table creation request
        if ( isset( $_GET['sacga_create_tables'] ) && check_admin_referer( 'sacga_create_tables' ) ) {
            $this->create_tables();
            wp_safe_redirect( remove_query_arg( [ 'sacga_create_tables', '_wpnonce' ] ) );
            exit;
        }

        global $wpdb;
        $missing_tables = [];
        $required_tables = [ 'sacga_rooms', 'sacga_room_players', 'sacga_game_state' ];

        foreach ( $required_tables as $table ) {
            if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$table}'" ) ) {
                $missing_tables[] = $wpdb->prefix . $table;
            }
        }

        if ( ! empty( $missing_tables ) || get_option( 'sacga_table_creation_failed' ) ) {
            $manual_create_url = wp_nonce_url(
                add_query_arg( 'sacga_create_tables', '1' ),
                'sacga_create_tables'
            );
            ?>
            <div class="notice notice-error">
                <h3><?php esc_html_e( 'Classic Games Arcade: Database Tables Missing', 'shortcode-arcade' ); ?></h3>
                <p><strong><?php esc_html_e( 'The following database tables are missing:', 'shortcode-arcade' ); ?></strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <?php foreach ( $missing_tables as $table ): ?>
                        <li><code><?php echo esc_html( $table ); ?></code></li>
                    <?php endforeach; ?>
                </ul>

                <h4><?php esc_html_e( 'Troubleshooting Steps:', 'shortcode-arcade' ); ?></h4>
                <ol style="margin-left: 20px;">
                    <li>
                        <strong><?php esc_html_e( 'Try automatic creation:', 'shortcode-arcade' ); ?></strong>
                        <a href="<?php echo esc_url( $manual_create_url ); ?>" class="button button-primary"><?php esc_html_e( 'Create Tables Now', 'shortcode-arcade' ); ?></a>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'Deactivate and reactivate:', 'shortcode-arcade' ); ?></strong>
                        <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="button"><?php esc_html_e( 'Go to Plugins', 'shortcode-arcade' ); ?></a>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'Check error logs:', 'shortcode-arcade' ); ?></strong> <?php esc_html_e( 'Look in wp-content/debug.log for detailed error messages', 'shortcode-arcade' ); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'Verify database permissions:', 'shortcode-arcade' ); ?></strong> <?php esc_html_e( 'Ensure your database user has CREATE TABLE privileges', 'shortcode-arcade' ); ?>
                    </li>
                </ol>

                <p><strong><?php esc_html_e( 'Debug Information:', 'shortcode-arcade' ); ?></strong></p>
                <ul style="list-style: none; font-family: monospace; font-size: 11px;">
                    <li><?php esc_html_e( 'Table Prefix:', 'shortcode-arcade' ); ?> <code><?php echo esc_html( $wpdb->prefix ); ?></code></li>
                    <li><?php esc_html_e( 'Database:', 'shortcode-arcade' ); ?> <code><?php echo esc_html( DB_NAME ); ?></code></li>
                    <li><?php esc_html_e( 'Charset/Collate:', 'shortcode-arcade' ); ?> <code><?php echo esc_html( $wpdb->get_charset_collate() ); ?></code></li>
                    <li><?php esc_html_e( 'PHP Version:', 'shortcode-arcade' ); ?> <code><?php echo esc_html( PHP_VERSION ); ?></code></li>
                    <li><?php esc_html_e( 'MySQL Version:', 'shortcode-arcade' ); ?> <code><?php echo esc_html( $wpdb->db_version() ); ?></code></li>
                </ul>
            </div>
            <?php
        }
    }

    public function get_game_registry() {
        return $this->game_registry;
    }

    public function get_room_manager() {
        return $this->room_manager;
    }

    /**
     * Get the arcade page URL
     *
     * Finds the permalink of the page containing the [classic_games_arcade] shortcode.
     * Uses transient caching to avoid repeated database queries.
     *
     * @return string|null The arcade page URL, or null if not found.
     */
    public function get_arcade_page_url(): ?string {
        global $post;

        // If current page has the arcade shortcode, use its permalink
        if ( $post && has_shortcode( $post->post_content, 'classic_games_arcade' ) ) {
            return get_permalink( $post->ID );
        }

        // Check transient cache
        $cached_url = get_transient( 'sacga_arcade_page_url' );
        if ( false !== $cached_url ) {
            // Empty string means no arcade page found (cached negative result)
            return '' === $cached_url ? null : $cached_url;
        }

        // Search for a page with the arcade shortcode
        $arcade_page = get_posts( [
            'post_type'      => [ 'page', 'post' ],
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            's'              => '[classic_games_arcade',
            'orderby'        => 'date',
            'order'          => 'ASC',
        ] );

        // Verify the shortcode exists in the content
        $arcade_url = null;
        if ( ! empty( $arcade_page ) ) {
            foreach ( $arcade_page as $page ) {
                if ( has_shortcode( $page->post_content, 'classic_games_arcade' ) ) {
                    $arcade_url = get_permalink( $page->ID );
                    break;
                }
            }
        }

        // Cache the result (or empty string for not found) for 1 hour
        set_transient( 'sacga_arcade_page_url', $arcade_url ?? '', HOUR_IN_SECONDS );

        return $arcade_url;
    }

    /**
     * Clear the arcade page URL cache
     *
     * Should be called when pages are updated that might contain the shortcode.
     */
    public function clear_arcade_url_cache(): void {
        delete_transient( 'sacga_arcade_page_url' );
    }
}

/**
 * Returns the main instance
 */
function SACGA() {
    return SACGA_Classic_Games_Arcade::instance();
}

// Initialize
add_action( 'plugins_loaded', 'SACGA' );
