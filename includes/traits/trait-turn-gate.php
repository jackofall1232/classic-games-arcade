<?php
/**
 * Turn Gate Trait
 *
 * Provides a standard way to manage gate-based flow control
 * (Begin Game, Continue, Next Turn, etc.)
 *
 * Core Principles:
 * - Turns are player-owned: A turn belongs to exactly one player (human or AI)
 * - Gates are flow controls: A gate is NOT a turn
 * - No turn during gate: When a gate is open, current_turn MUST be null
 * - Engine never guesses: Frontend and AI must rely solely on state fields
 *
 * @package ShortcodeArcade
 * @since 1.0.0
 */

trait SACGA_Turn_Gate_Trait {

    /**
     * Open a gate (suspend turn ownership, enter waiting phase)
     *
     * Effects:
     * - phase = 'waiting'
     * - awaiting_gate = $gate_type
     * - current_turn = null (turn ownership suspended)
     * - gate populated with metadata
     *
     * @param array  &$state    Game state (passed by reference)
     * @param string $gate_type Gate type ('start_game', 'next_turn', etc.)
     * @param array  $data      Additional gate metadata (next_turn, next_round, etc.)
     * @return void
     */
    protected function open_gate(array &$state, string $gate_type, array $data = []): void {
        $state['phase'] = 'waiting';
        $state['awaiting_gate'] = $gate_type;
        $state['current_turn'] = null; // Suspend turn ownership

        $state['gate'] = array_merge([
            'type' => $gate_type,
        ], $data);
    }

    /**
     * Close a gate (restore normal turn-based flow)
     *
     * Effects:
     * - awaiting_gate = null
     * - gate = null
     *
     * Note: Caller is responsible for restoring current_turn and phase
     *
     * @param array &$state Game state (passed by reference)
     * @return void
     */
    protected function close_gate(array &$state): void {
        $state['awaiting_gate'] = null;
        $state['gate'] = null;
    }

    /**
     * Check if a gate is currently open
     *
     * @param array $state Game state
     * @return bool True if awaiting_gate is not null
     */
    protected function is_gate_open(array $state): bool {
        return !empty($state['awaiting_gate']);
    }

    /**
     * Check if a move is a gate action
     *
     * Gate actions are special flow control actions that:
     * - Bypass turn ownership checks
     * - Are validated only against awaiting_gate value
     * - Restore turn ownership when resolved
     *
     * @param array $move Move data
     * @return bool True if move is a recognized gate action
     */
    protected function is_gate_action(array $move): bool {
        return isset($move['action']) && in_array($move['action'], [
            'begin_game',
            'continue',
        ], true);
    }

    /**
     * Validate that a gate action matches the current gate state
     *
     * @param array  $state Game state
     * @param string $action Expected action ('begin_game', 'continue')
     * @param string $expected_gate Expected gate type ('start_game', 'next_turn')
     * @return bool True if gate action is valid
     */
    protected function validate_gate_action(array $state, string $action, string $expected_gate): bool {
        if (empty($state['awaiting_gate'])) {
            return false;
        }

        if ($state['awaiting_gate'] !== $expected_gate) {
            return false;
        }

        return true;
    }

    /**
     * Get precomputed gate data
     *
     * @param array  $state Game state
     * @param string $key   Gate data key (e.g., 'next_turn', 'next_round')
     * @param mixed  $default Default value if key not found
     * @return mixed Gate data value or default
     */
    protected function get_gate_data(array $state, string $key, $default = null) {
        return $state['gate'][$key] ?? $default;
    }
}
