/**
 * Pig Dice Game Renderer
 */
(function($) {
    'use strict';

    const { __, sprintf } = wp.i18n;

    const PigRenderer = {
        mySeat: null,
        onMove: null,
        state: null,

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            this.renderBoard();
            this.bindActions();
        },

        renderBoard: function() {
            const state = this.state;
            const isMyTurn = state.current_turn === this.mySeat;
            const isGameOver = !!state.game_over;
            const lastMessage = this.getLastActionMessage(state);
            const scoresHtml = this.renderScores(state);
            const dieHtml = state.last_roll ? window.SACGADice.renderDice([state.last_roll], { size: 'large' }) : '';
            const lastAction = state.last_action;
            const isBust = lastAction === 'roll' && state.last_roll === 1;
            const isHold = lastAction === 'hold';
            const rollAnimationClass = lastAction === 'roll' ? ' pig-roll-animate' : '';
            const bustClass = isBust ? ' pig-bust' : '';
            const holdClass = isHold ? ' pig-hold' : '';

            let html = '<div class="pig-game' + (isMyTurn ? ' is-my-turn' : ' is-waiting') + '">';
            html += '<div class="pig-header">';
            html += '<h2>' + __( 'Pig', 'shortcode-arcade' ) + '</h2>';
            html += '<p>' + sprintf( __( 'First to %d points wins.', 'shortcode-arcade' ), state.target_score ) + '</p>';
            html += '</div>';

            html += '<div class="pig-status' + holdClass + '">';
            html += '<p>' + __( 'Round total:', 'shortcode-arcade' ) + ' <strong>' + state.round_total + '</strong></p>';
            if (lastMessage) {
                html += '<p class="pig-last-action">' + lastMessage;
                if (isBust) {
                    html += ' <span class="pig-bust-badge">' + __( 'Bust!', 'shortcode-arcade' ) + '</span>';
                }
                if (isHold) {
                    html += ' <span class="pig-bank-badge">' + __( 'Banked', 'shortcode-arcade' ) + '</span>';
                }
                html += '</p>';
            }
            if (dieHtml) {
                html += '<div class="pig-last-roll' + rollAnimationClass + bustClass + '">' + dieHtml + '</div>';
            }
            if (isMyTurn && !isGameOver) {
                html += '<p class="pig-turn-indicator">' + __( 'Your turn!', 'shortcode-arcade' ) + '</p>';
            }
            html += '</div>';

            html += '<div class="pig-scoreboard">' + scoresHtml + '</div>';

            html += '<div class="pig-actions">';
            html += '<button class="sacga-btn sacga-btn-primary pig-action" data-action="roll"' + (isMyTurn && !isGameOver ? '' : ' disabled') + '>' + __( 'Roll', 'shortcode-arcade' ) + '</button>';
            html += '<button class="sacga-btn pig-action" data-action="hold"' + (isMyTurn && !isGameOver ? '' : ' disabled') + '>' + __( 'Hold', 'shortcode-arcade' ) + '</button>';
            if (!isMyTurn && !isGameOver) {
                html += '<p class="pig-turn-note">' + __( 'Waiting for other player...', 'shortcode-arcade' ) + '</p>';
            }
            html += '</div>';

            html += '</div>';

            $('#sacga-game-board').html(html);
        },

        renderScores: function(state) {
            const players = state.players || {};
            const seats = Object.keys(players).map(Number).sort((a, b) => a - b);
            let html = '<div class="pig-scoreboard-panel">';
            html += '<div class="pig-scoreboard-header">';
            html += '<h3>' + __( 'Scoreboard', 'shortcode-arcade' ) + '</h3>';
            html += '</div>';
            html += '<div class="pig-scores">';

            seats.forEach((seat) => {
                const player = players[seat] || {};
                const name = player.name || sprintf( __( 'Player %d', 'shortcode-arcade' ), seat + 1 );
                const score = state.scores[seat] ?? 0;
                const isCurrent = state.current_turn === seat;

                html += '<div class="pig-score' + (isCurrent ? ' is-current' : '') + '">';
                html += '<div class="pig-score-main">';
                html += '<span class="pig-player">' + this.escapeHtml(name) + '</span>';
                if (player.is_ai) {
                    html += '<span class="pig-ai">' + __( 'AI', 'shortcode-arcade' ) + '</span>';
                }
                html += '</div>';
                html += '<div class="pig-score-total">';
                html += '<span class="pig-score-label">' + __( 'Total', 'shortcode-arcade' ) + '</span>';
                html += '<span class="pig-score-value">' + score + '</span>';
                html += '</div>';
                if (isCurrent) {
                    html += '<span class="pig-current-indicator">' + __( 'Current turn', 'shortcode-arcade' ) + '</span>';
                }
                html += '</div>';
            });

            html += '</div>';
            html += '</div>';
            return html;
        },

        getLastActionMessage: function(state) {
            if (!state.last_action || state.last_player === null || state.last_player === undefined) {
                return '';
            }

            const players = state.players || {};
            const player = players[state.last_player] || {};
            const name = player.name || sprintf( __( 'Player %d', 'shortcode-arcade' ), state.last_player + 1 );

            if (state.last_action === 'roll') {
                if (state.last_roll === 1) {
                    return sprintf( __( '%s rolled a 1 and busted.', 'shortcode-arcade' ), this.escapeHtml(name) );
                }
                return sprintf( __( '%s rolled a %d.', 'shortcode-arcade' ), this.escapeHtml(name), state.last_roll );
            }

            if (state.last_action === 'hold') {
                return sprintf( __( '%s held and banked points.', 'shortcode-arcade' ), this.escapeHtml(name) );
            }

            return '';
        },

        bindActions: function() {
            const self = this;
            const $board = $('#sacga-game-board');
            $board.off('click', '.pig-action');
            $board.on('click', '.pig-action', function() {
                const $button = $(this);
                if ($button.is(':disabled')) {
                    return;
                }

                const action = $button.data('action');
                if (!action || !self.onMove) {
                    return;
                }

                self.onMove({ action: action });
            });
        },

        escapeHtml: function(text) {
            if (window.SACGA && typeof window.SACGA.escapeHtml === 'function') {
                return window.SACGA.escapeHtml(text);
            }
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    window.SACGAGames = window.SACGAGames || {};
    window.SACGAGames.pig = PigRenderer;

})(jQuery);
