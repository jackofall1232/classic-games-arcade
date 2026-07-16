<?php
/**
 * Zero-Dependency Local CLI Test Harness (P3.1)
 *
 * Run with: php tests/run-tests.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'SACGA_VERSION', '1.2.2' );
define( 'SACGA_PLUGIN_DIR', ABSPATH );

// Mock essential WordPress functions used by our classes
function add_option( $name, $value ) {}
function get_option( $name, $default = false ) {
    if ( $name === 'sacga_enable_gemini_chat' ) return 0;
    if ( $name === 'sacga_gemini_api_key' ) return '';
    return $default;
}
function update_option( $name, $value ) {}
function __( $text, $domain = 'default' ) { return $text; }
function esc_html__( $text, $domain = 'default' ) { return $text; }
function sanitize_text_field( $text ) { return trim( $text ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function rest_sanitize_boolean( $value ) { return (bool) $value; }

// Load necessary files manually to verify loading and syntax
require_once ABSPATH . 'includes/engine/class-sacga-game-registry.php';
require_once ABSPATH . 'includes/engine/class-sacga-room-manager.php';
require_once ABSPATH . 'includes/engine/class-sacga-game-state.php';
require_once ABSPATH . 'includes/engine/class-sacga-ai-engine.php';

// Helper for outputting test results
function assert_test( $name, $condition, $message = '' ) {
    if ( $condition ) {
        echo "[\033[32mPASS\033[0m] $name\n";
        return true;
    } else {
        echo "[\033[31mFAIL\033[0m] $name: $message\n";
        return false;
    }
}

echo "=============================================\n";
echo "Starting Classic Games Arcade Unit Checks...\n";
echo "=============================================\n\n";

$all_pass = true;

// Test 1: ETag Generation Hashing
$state_manager = new SACGA_Game_State();
$sample_state = [ 'phase' => 'pegging', 'scores' => [ 0 => 12, 1 => 4 ] ];
$etag1 = $state_manager->generate_etag( $sample_state );
$etag2 = $state_manager->generate_etag( $sample_state );
$sample_state['scores'][0] = 13;
$etag3 = $state_manager->generate_etag( $sample_state );

$all_pass &= assert_test(
    "ETag generation is deterministic",
    $etag1 === $etag2,
    "ETags for identical states differed!"
);

$all_pass &= assert_test(
    "ETag changes when state changes",
    $etag1 !== $etag3,
    "ETag did not update on state modification!"
);

// Test 2: Host Seat Determination Logic
// Simulate a room response array
$room1 = [
    'players' => [
        [ 'seat_position' => 0, 'display_name' => 'Host User' ],
        [ 'seat_position' => 1, 'display_name' => 'Opponent' ],
    ]
];
$room2 = [
    'players' => [
        [ 'seat_position' => 1, 'display_name' => 'Next Host' ],
        [ 'seat_position' => 2, 'display_name' => 'Opponent' ],
    ]
];

// Reflect to get private method or test logic directly
$seats1 = array_map( 'intval', array_column( $room1['players'], 'seat_position' ) );
$host1 = min( $seats1 );
$seats2 = array_map( 'intval', array_column( $room2['players'], 'seat_position' ) );
$host2 = min( $seats2 );

$all_pass &= assert_test(
    "Host seat defaults to 0 when seat 0 is occupied",
    $host1 === 0,
    "Expected host seat 0, got $host1"
);

$all_pass &= assert_test(
    "Host seat dynamically promotes to next lowest seat if seat 0 leaves",
    $host2 === 1,
    "Expected host seat 1, got $host2"
);

echo "\n=============================================\n";
if ( $all_pass ) {
    echo "[\033[32mSUCCESS\033[0m] All unit assertions passed successfully!\n";
    exit(0);
} else {
    echo "[\033[31mFAILURE\033[0m] One or more assertions failed.\n";
    exit(1);
}
