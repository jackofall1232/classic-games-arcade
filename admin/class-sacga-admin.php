<?php
/**
 * Admin Panel for Classic Games Arcade
 * Modern tabbed interface
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_Admin {

    /**
     * Initialize admin
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_init', [ $this, 'handle_purge_rooms_action' ] );
        add_action( 'admin_notices', [ $this, 'display_admin_messages' ] );
    }

    /**
     * Handle manual stale rooms purging (P2.6 / P2.14)
     */
    public function handle_purge_rooms_action(): void {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_GET['action'] ) && $_GET['action'] === 'sacga_purge_rooms' ) {
            check_admin_referer( 'sacga_purge_rooms_nonce' );

            // Call Room Manager to clean up rooms
            SACGA()->get_room_manager()->cleanup_expired_rooms();

            // Set transient message and redirect
            set_transient( 'sacga_admin_message', esc_html__( 'Stale inactive rooms successfully purged!', 'shortcode-arcade' ), 45 );

            wp_safe_redirect( admin_url( 'admin.php?page=sacga-settings&tab=settings' ) );
            exit;
        }
    }

    /**
     * Display success notices in the admin panel
     */
    public function display_admin_messages(): void {
        $message = get_transient( 'sacga_admin_message' );
        if ( $message ) {
            delete_transient( 'sacga_admin_message' );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }
    }

    /**
     * Register settings
     */
    public function register_settings(): void {
        register_setting( 'sacga_settings', 'sacga_room_expiration', [
            'type'              => 'integer',
            'default'           => 120,
            'sanitize_callback' => 'absint',
        ] );

        register_setting( 'sacga_settings', 'sacga_default_difficulty', [
            'type'              => 'string',
            'default'           => 'beginner',
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        register_setting( 'sacga_settings', 'sacga_enable_gemini_chat', [
            'type'              => 'boolean',
            'default'           => 0,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ] );

        register_setting( 'sacga_settings', 'sacga_gemini_api_key', [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        register_setting( 'sacga_settings', 'sacga_gemini_chat_frequency', [
            'type'              => 'integer',
            'default'           => 3,
            'sanitize_callback' => 'absint',
        ] );
    }

    /**
     * Add admin menu - simplified to 4 pages
     */
    public function add_menu(): void {
        add_menu_page(
            esc_html__( 'Classic Games Arcade', 'shortcode-arcade' ),
            esc_html__( 'Games Arcade', 'shortcode-arcade' ),
            'manage_options',
            'sacga-dashboard',
            [ $this, 'render_admin_page' ],
            'dashicons-games',
            30
        );

        add_submenu_page(
            'sacga-dashboard',
            esc_html__( 'Dashboard', 'shortcode-arcade' ),
            esc_html__( 'Dashboard', 'shortcode-arcade' ),
            'manage_options',
            'sacga-dashboard',
            [ $this, 'render_admin_page' ]
        );

        add_submenu_page(
            'sacga-dashboard',
            esc_html__( 'Games', 'shortcode-arcade' ),
            esc_html__( 'Games', 'shortcode-arcade' ),
            'manage_options',
            'sacga-games',
            [ $this, 'render_admin_page' ]
        );

        add_submenu_page(
            'sacga-dashboard',
            esc_html__( 'Settings', 'shortcode-arcade' ),
            esc_html__( 'Settings', 'shortcode-arcade' ),
            'manage_options',
            'sacga-settings',
            [ $this, 'render_admin_page' ]
        );

        add_submenu_page(
            'sacga-dashboard',
            esc_html__( 'Shortcodes', 'shortcode-arcade' ),
            esc_html__( 'Shortcodes', 'shortcode-arcade' ),
            'manage_options',
            'sacga-shortcodes',
            [ $this, 'render_admin_page' ]
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'sacga-' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'sacga-admin',
            SACGA_PLUGIN_URL . 'admin/css/sacga-admin.css',
            [],
            SACGA_VERSION
        );

        wp_enqueue_script(
            'sacga-admin',
            SACGA_PLUGIN_URL . 'admin/js/sacga-admin.js',
            [],
            SACGA_VERSION,
            true
        );
    }

    /**
     * Get current tab from URL
     */
    private function get_current_tab(): string {
        $page = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'sacga-dashboard' ) );
        $tab_map = [
            'sacga-dashboard'  => 'dashboard',
            'sacga-games'      => 'games',
            'sacga-settings'   => 'settings',
            'sacga-shortcodes' => 'shortcodes',
        ];
        return $tab_map[ $page ] ?? 'dashboard';
    }

    /**
     * Render unified admin page with tabs
     */
    public function render_admin_page(): void {
        $current_tab = $this->get_current_tab();
        $tabs = [
            'dashboard'  => [
                'label' => esc_html__( 'Dashboard', 'shortcode-arcade' ),
                'icon'  => 'dashicons-dashboard',
                'url'   => admin_url( 'admin.php?page=sacga-dashboard' ),
            ],
            'games'      => [
                'label' => esc_html__( 'Games', 'shortcode-arcade' ),
                'icon'  => 'dashicons-games',
                'url'   => admin_url( 'admin.php?page=sacga-games' ),
            ],
            'settings'   => [
                'label' => esc_html__( 'Settings', 'shortcode-arcade' ),
                'icon'  => 'dashicons-admin-settings',
                'url'   => admin_url( 'admin.php?page=sacga-settings' ),
            ],
            'shortcodes' => [
                'label' => esc_html__( 'Shortcodes', 'shortcode-arcade' ),
                'icon'  => 'dashicons-shortcode',
                'url'   => admin_url( 'admin.php?page=sacga-shortcodes' ),
            ],
        ];
        ?>
        <div class="wrap sacga-admin sacga-modern">
            <!-- Banner Header -->
            <div class="sacga-banner-header">
                <img src="<?php echo esc_url( SACGA_PLUGIN_URL . 'assets/images/classic_games_arcade_banner.png' ); ?>"
                     alt="<?php echo esc_attr__( 'Classic Games Arcade', 'shortcode-arcade' ); ?>"
                     class="sacga-banner-image" />
            </div>

            <!-- Version Badge -->
            <div class="sacga-header-meta">
                <div class="sacga-version-badge">
                    <span class="dashicons dashicons-tag"></span>
                    <?php echo esc_html__( 'Version', 'shortcode-arcade' ); ?> <?php echo esc_html( SACGA_VERSION ); ?>
                </div>
                <div class="sacga-license-badge sacga-pro">
                    <span class="dashicons dashicons-heart"></span>
                    <?php echo esc_html__( 'GPLv3 License', 'shortcode-arcade' ); ?>
                </div>
            </div>

            <!-- Tab Navigation -->
            <nav class="sacga-tab-nav" role="tablist">
                <?php foreach ( $tabs as $tab_id => $tab ) : ?>
                    <a href="<?php echo esc_url( $tab['url'] ); ?>"
                       class="sacga-tab-link <?php echo $current_tab === $tab_id ? 'sacga-tab-active' : ''; ?>"
                       role="tab"
                       aria-selected="<?php echo $current_tab === $tab_id ? 'true' : 'false'; ?>">
                        <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
                        <span class="sacga-tab-label"><?php echo esc_html( $tab['label'] ); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Tab Content -->
            <div class="sacga-tab-content">
                <?php
                switch ( $current_tab ) {
                    case 'games':
                        $this->render_games_tab();
                        break;
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    case 'shortcodes':
                        $this->render_shortcodes_tab();
                        break;
                    default:
                        $this->render_dashboard_tab();
                }
                ?>
            </div>

            <!-- Footer with Copyright -->
            <footer class="sacga-admin-footer">
                <div class="sacga-copyright-image">
                    <img src="<?php echo esc_url( SACGA_PLUGIN_URL . 'assets/images/shortcode_arcade_copyright.jpg' ); ?>"
                         alt="<?php echo esc_attr__( 'Shortcode Arcade', 'shortcode-arcade' ); ?>" />
                </div>
            </footer>
        </div>
        <?php
    }

    /**
     * Render dashboard tab
     */
    private function render_dashboard_tab(): void {
        $registry = SACGA()->get_game_registry();
        $games = $registry->get_all_metadata();
        $game_count = count( $games );

        global $wpdb;
        $active_rooms = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sacga_rooms WHERE status IN ('lobby', 'active')" );
        $total_games_played = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sacga_rooms WHERE status = 'completed'" );
        ?>
        <div class="sacga-dashboard-content">
            <!-- Stats Grid -->
            <div class="sacga-stats-grid">
                <div class="sacga-stat-card sacga-stat-games">
                    <div class="sacga-stat-icon">
                        <span class="dashicons dashicons-games"></span>
                    </div>
                    <div class="sacga-stat-content">
                        <span class="sacga-stat-number"><?php echo esc_html( $game_count ); ?></span>
                        <span class="sacga-stat-label"><?php echo esc_html__( 'Games Available', 'shortcode-arcade' ); ?></span>
                    </div>
                </div>

                <div class="sacga-stat-card sacga-stat-rooms">
                    <div class="sacga-stat-icon">
                        <span class="dashicons dashicons-groups"></span>
                    </div>
                    <div class="sacga-stat-content">
                        <span class="sacga-stat-number"><?php echo esc_html( $active_rooms ?: 0 ); ?></span>
                        <span class="sacga-stat-label"><?php echo esc_html__( 'Active Rooms', 'shortcode-arcade' ); ?></span>
                    </div>
                </div>

                <div class="sacga-stat-card sacga-stat-played">
                    <div class="sacga-stat-icon">
                        <span class="dashicons dashicons-chart-bar"></span>
                    </div>
                    <div class="sacga-stat-content">
                        <span class="sacga-stat-number"><?php echo esc_html( $total_games_played ?: 0 ); ?></span>
                        <span class="sacga-stat-label"><?php echo esc_html__( 'Games Played', 'shortcode-arcade' ); ?></span>
                    </div>
                </div>

                <div class="sacga-stat-card sacga-stat-license">
                    <div class="sacga-stat-icon">
                        <span class="dashicons dashicons-heart"></span>
                    </div>
                    <div class="sacga-stat-content">
                        <span class="sacga-stat-number"><?php echo esc_html__( 'GPLv3', 'shortcode-arcade' ); ?></span>
                        <span class="sacga-stat-label"><?php echo esc_html__( 'License Type', 'shortcode-arcade' ); ?></span>
                    </div>
                </div>
            </div>

            <!-- Quick Start & Games Grid -->
            <div class="sacga-dashboard-grid">
                <div class="sacga-card sacga-quick-start">
                    <div class="sacga-card-header">
                        <span class="dashicons dashicons-lightbulb"></span>
                        <h2><?php echo esc_html__( 'Quick Start', 'shortcode-arcade' ); ?></h2>
                    </div>
                    <div class="sacga-card-body">
                        <p><?php echo esc_html__( 'Add games to any page using shortcodes:', 'shortcode-arcade' ); ?></p>
                        <div class="sacga-shortcode-examples">
                            <div class="sacga-shortcode-item">
                                <code>[sacga_game game="checkers"]</code>
                                <span class="sacga-shortcode-desc"><?php echo esc_html__( 'Single game', 'shortcode-arcade' ); ?></span>
                            </div>
                            <div class="sacga-shortcode-item">
                                <code>[classic_games_arcade]</code>
                                <span class="sacga-shortcode-desc"><?php echo esc_html__( 'Full arcade', 'shortcode-arcade' ); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sacga-card sacga-games-overview">
                    <div class="sacga-card-header">
                        <span class="dashicons dashicons-games"></span>
                        <h2><?php echo esc_html__( 'Installed Games', 'shortcode-arcade' ); ?></h2>
                    </div>
                    <div class="sacga-card-body">
                        <div class="sacga-games-list">
                            <?php foreach ( $games as $id => $meta ) : ?>
                                <div class="sacga-game-row">
                                    <span class="sacga-game-name"><?php echo esc_html( $meta['name'] ); ?></span>
                                    <span class="sacga-game-type"><?php echo esc_html( ucfirst( $meta['type'] ) ); ?></span>
                                    <span class="sacga-game-players"><?php echo esc_html( $meta['min_players'] . '-' . $meta['max_players'] ); ?> <?php echo esc_html__( 'players', 'shortcode-arcade' ); ?></span>
                                    <?php if ( $meta['ai_supported'] ) : ?>
                                        <span class="sacga-badge sacga-badge-ai"><?php echo esc_html__( 'AI', 'shortcode-arcade' ); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render games tab
     */
    private function render_games_tab(): void {
        $registry = SACGA()->get_game_registry();
        $games = $registry->get_all_metadata();
        ?>
        <div class="sacga-games-content">
            <div class="sacga-section-header">
                <h2><?php echo esc_html__( 'Available Games', 'shortcode-arcade' ); ?></h2>
                <p><?php echo esc_html__( 'All games currently installed and available in your arcade.', 'shortcode-arcade' ); ?></p>
            </div>

            <div class="sacga-games-grid">
                <?php foreach ( $games as $id => $meta ) : ?>
                    <div class="sacga-game-card">
                        <div class="sacga-game-card-header">
                            <h3><?php echo esc_html( $meta['name'] ); ?></h3>
                            <span class="sacga-type-badge"><?php echo esc_html( ucfirst( $meta['type'] ) ); ?></span>
                        </div>
                        <div class="sacga-game-card-body">
                            <p><?php echo esc_html( $meta['description'] ?? '' ); ?></p>
                            <div class="sacga-game-meta">
                                <div class="sacga-meta-item">
                                    <span class="dashicons dashicons-groups"></span>
                                    <span><?php echo esc_html( $meta['min_players'] . '-' . $meta['max_players'] ); ?> <?php echo esc_html__( 'players', 'shortcode-arcade' ); ?></span>
                                </div>
                                <div class="sacga-meta-item">
                                    <span class="dashicons dashicons-desktop"></span>
                                    <span><?php echo $meta['ai_supported'] ? esc_html__( 'AI Supported', 'shortcode-arcade' ) : esc_html__( 'Multiplayer Only', 'shortcode-arcade' ); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="sacga-game-card-footer">
                            <code class="sacga-shortcode-display">[sacga_game game="<?php echo esc_attr( $id ); ?>"]</code>
                            <button type="button" class="sacga-copy-btn" data-shortcode='[sacga_game game="<?php echo esc_attr( $id ); ?>"]' title="<?php echo esc_attr__( 'Copy shortcode', 'shortcode-arcade' ); ?>">
                                <span class="dashicons dashicons-clipboard"></span>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings tab
     */
    private function render_settings_tab(): void {
        $room_expiration = get_option( 'sacga_room_expiration', 120 );
        $default_difficulty = get_option( 'sacga_default_difficulty', 'beginner' );
        $enable_gemini = get_option( 'sacga_enable_gemini_chat', 0 );
        $api_key = get_option( 'sacga_gemini_api_key', '' );
        $frequency = get_option( 'sacga_gemini_chat_frequency', 3 );
        ?>
        <div class="sacga-settings-content">
            <div class="sacga-settings-grid">
                <div class="sacga-card">
                    <div class="sacga-card-header">
                        <span class="dashicons dashicons-admin-settings"></span>
                        <h2><?php echo esc_html__( 'General Settings', 'shortcode-arcade' ); ?></h2>
                    </div>
                    <div class="sacga-card-body">
                        <form method="post" action="options.php" class="sacga-settings-form">
                            <?php settings_fields( 'sacga_settings' ); ?>

                            <div class="sacga-form-group">
                                <label for="sacga_room_expiration">
                                    <span class="dashicons dashicons-clock"></span>
                                    <?php echo esc_html__( 'Room Expiration', 'shortcode-arcade' ); ?>
                                </label>
                                <select name="sacga_room_expiration" id="sacga_room_expiration">
                                    <option value="30" <?php selected( $room_expiration, 30 ); ?>><?php echo esc_html__( '30 minutes', 'shortcode-arcade' ); ?></option>
                                    <option value="60" <?php selected( $room_expiration, 60 ); ?>><?php echo esc_html__( '60 minutes', 'shortcode-arcade' ); ?></option>
                                    <option value="120" <?php selected( $room_expiration, 120 ); ?>><?php echo esc_html__( '120 minutes', 'shortcode-arcade' ); ?></option>
                                    <option value="240" <?php selected( $room_expiration, 240 ); ?>><?php echo esc_html__( '240 minutes', 'shortcode-arcade' ); ?></option>
                                </select>
                                <p class="sacga-field-desc"><?php echo esc_html__( 'Rooms expire after this period of inactivity.', 'shortcode-arcade' ); ?></p>
                            </div>

                            <div class="sacga-form-group">
                                <label for="sacga_default_difficulty">
                                    <span class="dashicons dashicons-performance"></span>
                                    <?php echo esc_html__( 'Default AI Difficulty', 'shortcode-arcade' ); ?>
                                </label>
                                <select name="sacga_default_difficulty" id="sacga_default_difficulty">
                                    <option value="beginner" <?php selected( $default_difficulty, 'beginner' ); ?>><?php echo esc_html__( 'Beginner', 'shortcode-arcade' ); ?></option>
                                    <option value="intermediate" <?php selected( $default_difficulty, 'intermediate' ); ?>><?php echo esc_html__( 'Intermediate', 'shortcode-arcade' ); ?></option>
                                    <option value="expert" <?php selected( $default_difficulty, 'expert' ); ?>><?php echo esc_html__( 'Expert', 'shortcode-arcade' ); ?></option>
                                </select>
                                <p class="sacga-field-desc"><?php echo esc_html__( 'Default difficulty level for AI opponents.', 'shortcode-arcade' ); ?></p>
                            </div>

                            <hr class="sacga-settings-divider" style="margin: 25px 0; border: 0; border-top: 1px solid #e5e7eb;" />

                            <div class="sacga-card-subheader" style="margin-bottom: 20px;">
                                <span class="dashicons dashicons-admin-plugins" style="vertical-align: middle; color: #4f46e5; margin-right: 5px; font-size: 20px; width: 20px; height: 20px;"></span>
                                <h3 style="display: inline; margin: 0; font-size: 1.1em; color: #1f2937;"><?php echo esc_html__( 'Gemini Bot Commentary Settings', 'shortcode-arcade' ); ?></h3>
                            </div>

                            <div class="sacga-form-group">
                                <label for="sacga_enable_gemini_chat">
                                    <span class="dashicons dashicons-testimonial"></span>
                                    <?php echo esc_html__( 'Enable Gemini Bot Commentary', 'shortcode-arcade' ); ?>
                                </label>
                                <select name="sacga_enable_gemini_chat" id="sacga_enable_gemini_chat">
                                    <option value="0" <?php selected( $enable_gemini, 0 ); ?>><?php echo esc_html__( 'Disabled', 'shortcode-arcade' ); ?></option>
                                    <option value="1" <?php selected( $enable_gemini, 1 ); ?>><?php echo esc_html__( 'Enabled', 'shortcode-arcade' ); ?></option>
                                </select>
                                <p class="sacga-field-desc"><?php echo esc_html__( 'Enable AI bot trash-talk and commentary during single-player games.', 'shortcode-arcade' ); ?></p>
                            </div>

                            <div class="sacga-form-group" style="margin-top: 20px;">
                                <label for="sacga_gemini_api_key">
                                    <span class="dashicons dashicons-admin-network"></span>
                                    <?php echo esc_html__( 'Gemini API Key', 'shortcode-arcade' ); ?>
                                </label>
                                <input type="password" name="sacga_gemini_api_key" id="sacga_gemini_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text sacga-input" placeholder="AIzaSy..." style="width: 100%; max-width: 400px; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px;" />
                                <p class="sacga-field-desc"><?php echo esc_html__( 'Enter your Google Gemini API key to power bot commentary.', 'shortcode-arcade' ); ?></p>
                            </div>

                            <div class="sacga-form-group" style="margin-top: 20px; margin-bottom: 25px;">
                                <label for="sacga_gemini_chat_frequency">
                                    <span class="dashicons dashicons-controls-repeat"></span>
                                    <?php echo esc_html__( 'Commentary Frequency (Every X moves)', 'shortcode-arcade' ); ?>
                                </label>
                                <select name="sacga_gemini_chat_frequency" id="sacga_gemini_chat_frequency">
                                    <option value="1" <?php selected( $frequency, 1 ); ?>><?php echo esc_html__( 'Every move', 'shortcode-arcade' ); ?></option>
                                    <option value="2" <?php selected( $frequency, 2 ); ?>><?php echo esc_html__( 'Every 2 moves', 'shortcode-arcade' ); ?></option>
                                    <option value="3" <?php selected( $frequency, 3 ); ?>><?php echo esc_html__( 'Every 3 moves', 'shortcode-arcade' ); ?></option>
                                    <option value="5" <?php selected( $frequency, 5 ); ?>><?php echo esc_html__( 'Every 5 moves', 'shortcode-arcade' ); ?></option>
                                </select>
                                <p class="sacga-field-desc"><?php echo esc_html__( 'Adjust how often Gemini bot commentary triggers.', 'shortcode-arcade' ); ?></p>
                            </div>

                            <div class="sacga-form-actions">
                                <?php submit_button( esc_html__( 'Save Settings', 'shortcode-arcade' ), 'sacga-save-btn', 'submit', false ); ?>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="sacga-card">
                    <div class="sacga-card-header">
                        <span class="dashicons dashicons-database"></span>
                        <h2><?php echo esc_html__( 'Database Status', 'shortcode-arcade' ); ?></h2>
                    </div>
                    <div class="sacga-card-body">
                        <?php $this->render_db_status(); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render shortcodes tab - simplified to Game + Shortcode columns
     */
    private function render_shortcodes_tab(): void {
        $registry = SACGA()->get_game_registry();
        $games = $registry->get_all_games();
        ?>
        <div class="sacga-shortcodes-content">
            <div class="sacga-section-header">
                <h2><?php echo esc_html__( 'Shortcode Reference', 'shortcode-arcade' ); ?></h2>
                <p><?php echo esc_html__( 'Developer reference. Copy-paste shortcodes for available games.', 'shortcode-arcade' ); ?></p>
            </div>

            <!-- Global Shortcodes -->
            <div class="sacga-global-shortcode">
                <div class="sacga-global-shortcode-info">
                    <span class="dashicons dashicons-grid-view"></span>
                    <div>
                        <strong><?php echo esc_html__( 'Full Arcade', 'shortcode-arcade' ); ?></strong>
                        <span><?php echo esc_html__( 'Display all available games', 'shortcode-arcade' ); ?></span>
                    </div>
                </div>
                <div class="sacga-shortcode-copy-group">
                    <code>[classic_games_arcade]</code>
                    <button type="button" class="sacga-copy-btn" data-shortcode="[classic_games_arcade]" title="<?php echo esc_attr__( 'Copy shortcode', 'shortcode-arcade' ); ?>">
                        <span class="dashicons dashicons-clipboard"></span>
                    </button>
                </div>
            </div>

            <div class="sacga-global-shortcode">
                <div class="sacga-global-shortcode-info">
                    <span class="dashicons dashicons-groups"></span>
                    <div>
                        <strong><?php echo esc_html__( 'Available Rooms', 'shortcode-arcade' ); ?></strong>
                        <span><?php echo esc_html__( 'Display joinable game rooms', 'shortcode-arcade' ); ?></span>
                    </div>
                </div>
                <div class="sacga-shortcode-copy-group">
                    <code>[sacga_available_rooms]</code>
                    <button type="button" class="sacga-copy-btn" data-shortcode="[sacga_available_rooms]" title="<?php echo esc_attr__( 'Copy shortcode', 'shortcode-arcade' ); ?>">
                        <span class="dashicons dashicons-clipboard"></span>
                    </button>
                </div>
            </div>

            <!-- Available Rooms Attributes -->
            <div class="sacga-shortcode-attributes">
                <h3><?php echo esc_html__( 'Available Rooms Attributes', 'shortcode-arcade' ); ?></h3>
                <table class="sacga-attributes-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Attribute', 'shortcode-arcade' ); ?></th>
                            <th><?php echo esc_html__( 'Default', 'shortcode-arcade' ); ?></th>
                            <th><?php echo esc_html__( 'Description', 'shortcode-arcade' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>game</code></td>
                            <td><em><?php echo esc_html__( '(all)', 'shortcode-arcade' ); ?></em></td>
                            <td><?php echo esc_html__( 'Filter rooms by game ID (e.g., "checkers")', 'shortcode-arcade' ); ?></td>
                        </tr>
                        <tr>
                            <td><code>limit</code></td>
                            <td><code>10</code></td>
                            <td><?php echo esc_html__( 'Maximum number of rooms to display', 'shortcode-arcade' ); ?></td>
                        </tr>
                        <tr>
                            <td><code>show_players</code></td>
                            <td><code>true</code></td>
                            <td><?php echo esc_html__( 'Show player count in room cards', 'shortcode-arcade' ); ?></td>
                        </tr>
                        <tr>
                            <td><code>show_created</code></td>
                            <td><code>true</code></td>
                            <td><?php echo esc_html__( 'Show room creation time', 'shortcode-arcade' ); ?></td>
                        </tr>
                        <tr>
                            <td><code>refresh</code></td>
                            <td><code>0</code></td>
                            <td><?php echo esc_html__( 'Auto-refresh interval in seconds (0 = disabled)', 'shortcode-arcade' ); ?></td>
                        </tr>
                    </tbody>
                </table>
                <div class="sacga-shortcode-example">
                    <strong><?php echo esc_html__( 'Example:', 'shortcode-arcade' ); ?></strong>
                    <code>[sacga_available_rooms game="checkers" limit="5" refresh="30"]</code>
                </div>
            </div>

            <!-- Games Shortcode Table -->
            <div class="sacga-shortcodes-table-wrap">
                <table class="sacga-shortcodes-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Game', 'shortcode-arcade' ); ?></th>
                            <th><?php echo esc_html__( 'Shortcode', 'shortcode-arcade' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $games as $meta ) : ?>
                            <tr>
                                <td>
                                    <div class="sacga-game-info">
                                        <span class="sacga-game-name"><?php echo esc_html( $meta['name'] ?? $meta['id'] ); ?></span>
                                        <?php if ( ! empty( $meta['ai_supported'] ) ) : ?>
                                            <span class="sacga-badge sacga-badge-ai"><?php echo esc_html__( 'AI', 'shortcode-arcade' ); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="sacga-shortcode-copy-group">
                                        <code><?php echo esc_html( $meta['shortcode'] ?? '' ); ?></code>
                                        <button type="button" class="sacga-copy-btn" data-shortcode="<?php echo esc_attr( $meta['shortcode'] ?? '' ); ?>" title="<?php echo esc_attr__( 'Copy shortcode', 'shortcode-arcade' ); ?>">
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render database status
     */
    private function render_db_status(): void {
        global $wpdb;

        $tables = [
            'sacga_rooms'        => esc_html__( 'Rooms', 'shortcode-arcade' ),
            'sacga_room_players' => esc_html__( 'Players', 'shortcode-arcade' ),
            'sacga_game_state'   => esc_html__( 'Game States', 'shortcode-arcade' ),
        ];

        echo '<div class="sacga-db-status">';
        
        // Render raw DB table records counts
        echo '<div class="sacga-db-section-title" style="font-weight: 600; font-size: 0.9em; margin-bottom: 8px; color: #4b5563;">' . esc_html__( 'Database Tables Records', 'shortcode-arcade' ) . '</div>';
        foreach ( $tables as $table => $label ) {
            $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$table}'" ) !== null;
            $count = $exists ? $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table}" ) : '-';
            $status_class = $exists ? 'sacga-status-ok' : 'sacga-status-error';

            echo '<div class="sacga-db-row" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; padding: 6px 10px; background: #f9fafb; border-radius: 4px;">';
            echo '<div style="display: flex; align-items: center; gap: 8px;">';
            echo '<span class="sacga-db-indicator ' . esc_attr( $status_class ) . '" style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: ' . ( $exists ? '#10b981' : '#ef4444' ) . ';"></span>';
            echo '<span class="sacga-db-label">' . esc_html( $label ) . '</span>';
            echo '</div>';
            /* translators: %s: number of records */
            echo '<span class="sacga-db-count" style="font-weight: 500; font-size: 0.9em; color: #1f2937;">' . esc_html( sprintf( __( '%s records', 'shortcode-arcade' ), $count ) ) . '</span>';
            echo '</div>';
        }

        // Render Room Observability Stats (P2.6 / P2.14)
        $lobby_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sacga_rooms WHERE status = 'lobby'" ) ?: 0;
        $active_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sacga_rooms WHERE status = 'active'" ) ?: 0;
        $completed_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sacga_rooms WHERE status = 'completed'" ) ?: 0;

        echo '<hr style="margin: 15px 0; border: 0; border-top: 1px solid #e5e7eb;" />';
        echo '<div class="sacga-db-section-title" style="font-weight: 600; font-size: 0.9em; margin-bottom: 8px; color: #4b5563;">' . esc_html__( 'Live Room Observability Stats', 'shortcode-arcade' ) . '</div>';
        
        $stats = [
            'Lobby Matchrooms'   => [ $lobby_count, '#3b82f6' ],
            'Active Matchrooms'  => [ $active_count, '#10b981' ],
            'Completed Matchrooms' => [ $completed_count, '#6b7280' ],
        ];

        foreach ( $stats as $stat_label => $stat_info ) {
            echo '<div class="sacga-db-row" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; padding: 6px 10px; background: #f9fafb; border-radius: 4px;">';
            echo '<div style="display: flex; align-items: center; gap: 8px;">';
            echo '<span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: ' . esc_attr( $stat_info[1] ) . ';"></span>';
            echo '<span>' . esc_html( $stat_label ) . '</span>';
            echo '</div>';
            echo '<span style="font-weight: 500; font-size: 0.9em; color: #1f2937;">' . esc_html( $stat_info[0] ) . '</span>';
            echo '</div>';
        }

        // Purge rooms action button
        $purge_url = wp_nonce_url( admin_url( 'admin.php?page=sacga-settings&action=sacga_purge_rooms' ), 'sacga_purge_rooms_nonce' );
        echo '<div style="margin-top: 15px; text-align: center;">';
        echo '<a href="' . esc_url( $purge_url ) . '" class="button button-secondary" style="background: #fff3cd; color: #9a3412; border-color: #fde047; font-weight: 600;">';
        echo '<span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 4px;"></span>';
        echo esc_html__( 'Purge Inactive Matchrooms', 'shortcode-arcade' );
        echo '</a>';
        echo '</div>';

        echo '</div>';
    }
}

// Initialize
new SACGA_Admin();
