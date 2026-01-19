<?php
/**
 * AI Engine - Handles AI opponent moves
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SACGA_AI_Engine {

    /**
     * Check if it's an AI player's turn
     */
    public function is_ai_turn( int $room_id ): bool {
        $room = SACGA()->get_room_manager()->get_room_by_id( $room_id );

        if ( ! $room || $room['status'] !== 'active' ) {
            return false;
        }

        $state_manager = new SACGA_Game_State();
        $state_data = $state_manager->get( $room_id );

        if ( ! $state_data || ! empty( $state_data['state']['game_over'] ) ) {
            return false;
        }

        $state = $state_data['state'];

        // CRITICAL: AI must not act when a gate is open (awaiting user action like Begin Game or Continue)
        // Gates are session-level controls, not AI turns
        if ( ! empty( $state['awaiting_gate'] ) ) {
            return false;
        }

        // Special handling for passing phase - check if any AI needs to pass
        if ( isset( $state['phase'] ) && $state['phase'] === 'passing' ) {
            foreach ( $room['players'] as $player ) {
                if ( ! $player['is_ai'] ) {
                    continue;
                }

                $seat = (int) $player['seat_position'];
                $passed = $state['passed_cards'][ $seat ] ?? [];

                // If this AI hasn't passed yet, return true
                if ( ! is_array( $passed ) || count( $passed ) !== 3 ) {
                    return true;
                }
            }
            return false; // All AI have passed
        }

        // Special handling for discard phase (Cribbage) - check if any AI needs to discard
        if ( isset( $state['phase'] ) && $state['phase'] === 'discard' ) {
            foreach ( $room['players'] as $player ) {
                if ( ! $player['is_ai'] ) {
                    continue;
                }

                $seat = (int) $player['seat_position'];
                $discards = $state['discards'][ $seat ] ?? [];

                // If this AI hasn't discarded yet, return true
                if ( ! is_array( $discards ) || count( $discards ) !== 2 ) {
                    error_log( "[SACGA AI Engine] Cribbage discard phase: AI at seat $seat needs to discard" );
                    return true;
                }
            }
            return false; // All AI have discarded
        }

        // Special handling for rolloff phase (Overcut) - simultaneous rolling
        if ( isset( $state['phase'] ) && $state['phase'] === 'rolloff' ) {
            foreach ( $room['players'] as $player ) {
                if ( ! $player['is_ai'] ) {
                    continue;
                }

                $seat = (int) $player['seat_position'];
                $waiting = $state['rolloff']['waiting'][ $seat ] ?? false;

                // If this AI hasn't rolled yet, return true
                if ( $waiting ) {
                    return true;
                }
            }
            return false; // All AI have rolled
        }

        // Check if trick is complete - need to process resolution
        if ( ! empty( $state['trick_complete'] ) ) {
            return true; // AI engine needs to run to resolve trick
        }

        // Standard turn-based check
        $current_turn = $state['current_turn'];

        // current_turn can be null when trick is complete
        if ( $current_turn === null ) {
            return false;
        }

        foreach ( $room['players'] as $player ) {
            $seat = (int) $player['seat_position'];
            $is_ai = $player['is_ai'];

            if ( $seat === $current_turn && $is_ai ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process AI turns until it's a human's turn
     * Only processes ONE AI move at a time to allow client to see animations
     */
    public function process_ai_turns( int $room_id ): array {
        $room = SACGA()->get_room_manager()->get_room_by_id( $room_id );

        if ( ! $room || $room['status'] !== 'active' ) {
            return [ 'error' => 'Invalid room' ];
        }

        $state_manager = new SACGA_Game_State();
        $game = SACGA()->get_game_registry()->get( $room['game_id'] );

        if ( ! $game->supports_ai() ) {
            return [ 'error' => 'AI not supported' ];
        }

        $state_data = $state_manager->get( $room_id );
        $state = $state_data['state'];

        // Check if game is over
        if ( ! empty( $state['game_over'] ) ) {
            return $state_data;
        }

        // CRITICAL: AI must not act when a gate is open (awaiting user action like Begin Game or Continue)
        // Gates are session-level controls, not AI turns
        if ( ! empty( $state['awaiting_gate'] ) ) {
            return $state_data;
        }

        // Check if trick is complete and waiting for resolution
        if ( ! empty( $state['trick_complete'] ) && $state['current_turn'] === null ) {
            // Resolve the trick using the game's public method
            if ( method_exists( $game, 'resolve_completed_trick' ) ) {
                $state = $game->resolve_completed_trick( $state );
                $result = $state_manager->update( $room_id, $state );

                if ( is_wp_error( $result ) ) {
                    return $state_data;
                }

                // Refresh state after resolution
                $state_data = $state_manager->get( $room_id );
                $state = $state_data['state'];

                // Check if it's an AI's turn after resolution
                $current_turn = $state['current_turn'];
                if ( $current_turn === null ) {
                    return $state_data;
                }

                $current_player = $this->get_player_at_seat( $room['players'], $current_turn );
                if ( ! $current_player || ! $current_player['is_ai'] ) {
                    return $state_manager->get( $room_id ) ?: [];
                }

                // Fall through to process the AI move
            }
        }

        // Special handling for phases where all players move simultaneously (e.g., Hearts passing)
        if ( isset( $state['phase'] ) && $state['phase'] === 'passing' ) {
            // Process all AI players in passing phase (simultaneous moves)
            foreach ( $room['players'] as $player ) {
                if ( ! $player['is_ai'] ) {
                    continue;
                }

                $seat = (int) $player['seat_position'];
                $passed = $state['passed_cards'][ $seat ] ?? [];

                // Skip if this AI already passed (has 3 cards)
                if ( is_array( $passed ) && count( $passed ) === 3 ) {
                    continue;
                }

                $difficulty = $player['ai_difficulty'] ?? 'beginner';
                $move = $game->ai_move( $state, $seat, $difficulty );

                if ( ! empty( $move ) ) {
                    $result = $state_manager->apply_move( $room_id, $seat, $move );
                    if ( ! is_wp_error( $result ) ) {
                        // Refresh state after move
                        $state_data = $state_manager->get( $room_id );
                        $state = $state_data['state'];
                    }
                }
            }

            return $state_manager->get( $room_id ) ?: [];
        }

        // Special handling for discard phase (Cribbage) - all players discard simultaneously
        if ( isset( $state['phase'] ) && $state['phase'] === 'discard' ) {
            error_log( '[SACGA AI Engine] Processing Cribbage discard phase' );
            // Process all AI players in discard phase (simultaneous moves)
            foreach ( $room['players'] as $player ) {
                if ( ! $player['is_ai'] ) {
                    continue;
                }

                $seat = (int) $player['seat_position'];
                $discards = $state['discards'][ $seat ] ?? [];

                // Skip if this AI already discarded (has 2 cards)
                if ( is_array( $discards ) && count( $discards ) === 2 ) {
                    continue;
                }

                error_log( "[SACGA AI Engine] AI at seat $seat discarding..." );
                $difficulty = $player['ai_difficulty'] ?? 'beginner';
                $move = $game->ai_move( $state, $seat, $difficulty );

                if ( ! empty( $move ) ) {
                    error_log( "[SACGA AI Engine] AI move: " . json_encode( $move ) );
                    $result = $state_manager->apply_move( $room_id, $seat, $move );
                    if ( ! is_wp_error( $result ) ) {
                        error_log( '[SACGA AI Engine] AI move applied successfully' );
                        // Refresh state after move
                        $state_data = $state_manager->get( $room_id );
                        $state = $state_data['state'];
                    } else {
                        error_log( '[SACGA AI Engine] AI move failed: ' . $result->get_error_message() );
                    }
                }
            }

            return $state_manager->get( $room_id ) ?: [];
        }

        // Special handling for rolloff phase (Overcut) - simultaneous rolling
        if ( isset( $state['phase'] ) && $state['phase'] === 'rolloff' ) {
            // Process all AI players in rolloff phase (simultaneous moves)
            foreach ( $room['players'] as $player ) {
                if ( ! $player['is_ai'] ) {
                    continue;
                }

                $seat = (int) $player['seat_position'];
                $waiting = $state['rolloff']['waiting'][ $seat ] ?? false;

                // Skip if this AI already rolled
                if ( ! $waiting ) {
                    continue;
                }

                $difficulty = $player['ai_difficulty'] ?? 'beginner';
                $move = $game->ai_move( $state, $seat, $difficulty );

                if ( ! empty( $move ) ) {
                    $result = $state_manager->apply_move( $room_id, $seat, $move );
                    if ( ! is_wp_error( $result ) ) {
                        // Refresh state after move
                        $state_data = $state_manager->get( $room_id );
                        $state = $state_data['state'];
                    }
                }
            }

            return $state_manager->get( $room_id ) ?: [];
        }

        // Turn-based logic - ONLY PROCESS ONE AI MOVE
        $current_turn = $state['current_turn'];
        $current_player = $this->get_player_at_seat( $room['players'], $current_turn );

        // If not AI, we're done
        if ( ! $current_player || ! $current_player['is_ai'] ) {
            return $state_data;
        }

        $difficulty = $current_player['ai_difficulty'] ?? 'beginner';

        // Get AI move
        $move = $game->ai_move( $state, $current_turn, $difficulty );

        if ( empty( $move ) ) {
            // AI has no valid moves - check if game should end
            $end_check = $game->check_end_condition( $state );

            if ( $end_check['ended'] ) {
                // Mark game as over
                $state['game_over'] = true;
                $state['end_reason'] = $end_check['reason'];
                $state['winners'] = $end_check['winners'];

                // Update state and room status
                $state_manager->update( $room_id, $state );
                SACGA()->get_room_manager()->update_status( $room_id, 'completed' );
            }

            return $state_manager->get( $room_id ) ?: [];
        }

        // Apply ONE move only
        $result = $state_manager->apply_move( $room_id, $current_turn, $move );

        if ( is_wp_error( $result ) ) {
            return $state_data;
        }

        // Return updated state after ONE AI move
        return $state_manager->get( $room_id ) ?: [];
    }

    /**
     * Get player at a specific seat
     */
    private function get_player_at_seat( array $players, int $seat ): ?array {
        foreach ( $players as $player ) {
            if ( (int) $player['seat_position'] === $seat ) {
                return $player;
            }
        }
        return null;
    }

    /**
     * Get AI thinking delay (for animation purposes)
     * Returns delay in milliseconds
     *
     * @param string $difficulty AI difficulty level
     * @return int Milliseconds
     */
    public function get_thinking_delay( string $difficulty ): int {
        switch ( $difficulty ) {
            case 'expert':
                return 800; // Expert "thinks" longer
            case 'intermediate':
                return 600;
            case 'beginner':
            default:
                return 400;
        }
    }

    /**
     * Get recommended animation delay for frontend
     * This can be included in API responses for better animation timing
     *
     * @param array $player Player data including difficulty
     * @return array Animation timing info
     */
    public function get_animation_metadata( array $player ): array {
        $difficulty = $player['ai_difficulty'] ?? 'beginner';

        return [
            'thinking_delay' => $this->get_thinking_delay( $difficulty ),
            'difficulty'     => $difficulty,
            'is_ai'          => true,
        ];
    }
}
