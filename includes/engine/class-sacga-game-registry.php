<?php
/**
 * Game Registry - Discovers and manages game modules
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_Game_Registry {
    private const STATUS_VALUES = [ 'available', 'in_progress', 'planned' ];
    private const SHORTCODE_FORMAT = '[sacga_game game="%s"]';

    /**
     * Registered games
     * @var array<string, SACGA_Game_Contract>
     */
    private $games = [];

    /**
     * Discover and load all game modules
     */
    public function discover_games(): void {
        $games_dir = SACGA_PLUGIN_DIR . 'includes/games/';

        if ( ! is_dir( $games_dir ) ) {
            return;
        }

        $game_folders = glob( $games_dir . '*', GLOB_ONLYDIR );

        foreach ( $game_folders as $folder ) {
            $game_id = basename( $folder );
            $game_file = $folder . '/class-sacga-game-' . $game_id . '.php';

            if ( file_exists( $game_file ) ) {
                require_once $game_file;

                $class_name = 'SACGA_Game_' . $this->to_class_name( $game_id );

                if ( class_exists( $class_name ) ) {
                    $game = new $class_name();

                    if ( $game instanceof SACGA_Game_Contract ) {
                        $this->register( $game );
                    }
                }
            }
        }
    }

    /**
     * Convert game-id to ClassName
     */
    private function to_class_name( string $game_id ): string {
        return str_replace( ' ', '_', ucwords( str_replace( [ '-', '_' ], ' ', $game_id ) ) );
    }

    /**
     * Register a game
     */
    public function register( SACGA_Game_Contract $game ): void {
        $this->games[ $game->get_id() ] = $game;
    }

    /**
     * Get a game by ID
     */
    public function get( string $game_id ): ?SACGA_Game_Contract {
        return $this->games[ $game_id ] ?? null;
    }

    /**
     * Get all games
     */
    public function get_all(): array {
        return $this->games;
    }

    /**
     * Get all games metadata
     */
    public function get_all_metadata(): array {
        $metadata = [];

        foreach ( $this->games as $game_id => $game ) {
            $metadata[ $game_id ] = $this->normalize_metadata( $game_id, $game->register_game() );
        }

        return $metadata;
    }

    /**
     * Get all games metadata (alias for admin usage)
     */
    public function get_all_games(): array {
        return $this->get_all_metadata();
    }

    /**
     * Check if game exists
     */
    public function exists( string $game_id ): bool {
        return isset( $this->games[ $game_id ] );
    }

    /**
     * Ensure metadata includes defaults for admin views.
     */
    private function normalize_metadata( string $game_id, array $meta ): array {
        $status = $meta['status'] ?? 'available';
        if ( ! in_array( $status, self::STATUS_VALUES, true ) ) {
            $status = 'available';
        }

        return array_merge( [
            'id'            => $game_id,
            'shortcode'     => sprintf( self::SHORTCODE_FORMAT, $game_id ),
            'status'        => $status,
            'theme_support' => $meta['theme_support'] ?? false,
        ], $meta );
    }
}
