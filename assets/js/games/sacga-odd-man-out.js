/**
 * Odd Man Out Game Renderer
 *
 * @package ShortcodeArcade
 * @since 1.2.0
 */
(function($) {
    'use strict';

    const { __, sprintf } = wp.i18n;

    const OddManOutRenderer = {
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
            const isGameOver = !!state.game_over;
            const awaitingGate = state.awaiting_gate;

            let html = '<div class="odd-man-out-game">';

            // Header
            html += '<div class="omo-header">';
            html += '<h2>' + __('Odd Man Out', 'shortcode-arcade') + '</h2>';
            html += '<p class="omo-subtitle">' + sprintf(__('First to %d points wins', 'shortcode-arcade'), state.target_score) + '</p>';
            if (state.round > 0) {
                html += '<p class="omo-round">' + sprintf(__('Round %d', 'shortcode-arcade'), state.round) + '</p>';
            }
            html += '</div>';

            // Status area
            html += '<div class="omo-status">';
            html += this.renderStatus(state, awaitingGate);
            html += '</div>';

            // Coins area - the main game display
            html += '<div class="omo-coins-area">';
            html += this.renderCoinsArea(state);
            html += '</div>';

            // Scoreboard
            html += '<div class="omo-scoreboard">';
            html += this.renderScoreboard(state);
            html += '</div>';

            // Action buttons
            html += '<div class="omo-actions">';
            html += this.renderActions(state, awaitingGate, isGameOver);
            html += '</div>';

            html += '</div>';

            $('#sacga-game-board').html(html);
        },

        renderStatus: function(state, awaitingGate) {
            let html = '';

            if (awaitingGate === 'start_game') {
                html += '<p class="omo-message">' + __('Ready to flip? Click Begin Game!', 'shortcode-arcade') + '</p>';
            } else if (awaitingGate === 'resolve_round') {
                if (state.no_score) {
                    html += '<div class="omo-result-reveal omo-no-score">';
                    html += '<p class="omo-result-text">' + __('All coins match!', 'shortcode-arcade') + '</p>';
                    html += '<p class="omo-no-winner">' + __('No one scores this round.', 'shortcode-arcade') + '</p>';
                    html += '</div>';
                } else {
                    const oddPlayer = state.players[state.odd_player];
                    const oddName = oddPlayer ? oddPlayer.name : sprintf(__('Player %d', 'shortcode-arcade'), state.odd_player + 1);
                    html += '<div class="omo-result-reveal omo-has-winner">';
                    html += '<p class="omo-odd-label">' + __('Odd One Out:', 'shortcode-arcade') + '</p>';
                    html += '<p class="omo-odd-winner">' + this.escapeHtml(oddName) + '</p>';
                    html += '<span class="omo-score-badge">+1</span>';
                    html += '</div>';
                }
            } else if (awaitingGate === 'next_round') {
                html += '<p class="omo-message">' + __('Ready for the next flip?', 'shortcode-arcade') + '</p>';
            } else if (state.game_over) {
                const winnerNames = this.getWinnerNames(state);
                html += '<div class="omo-game-over">';
                html += '<p class="omo-winner">' + sprintf(__('%s wins!', 'shortcode-arcade'), winnerNames) + '</p>';
                html += '</div>';
            }

            return html;
        },

        renderCoinsArea: function(state) {
            let html = '<div class="omo-coins-triangle">';

            const players = state.players || {};
            const seats = Object.keys(players).map(Number).sort((a, b) => a - b);

            seats.forEach((seat) => {
                const player = players[seat];
                const coin = state.coins ? state.coins[seat] : null;
                const name = player.name || sprintf(__('Player %d', 'shortcode-arcade'), seat + 1);
                const isOdd = state.odd_player === seat;
                const isMe = seat === this.mySeat;

                html += '<div class="omo-player-slot' + (isOdd ? ' is-odd' : '') + (isMe ? ' is-me' : '') + '" data-seat="' + seat + '">';

                // Coin (or placeholder)
                if (coin) {
                    html += '<div class="omo-coin omo-coin-' + coin + (isOdd ? ' is-odd' : '') + '">';
                    html += '<span class="omo-coin-face">' + (coin === 'heads' ? 'H' : 'T') + '</span>';
                    html += '</div>';
                } else {
                    html += '<div class="omo-coin omo-coin-waiting">';
                    html += '<span class="omo-coin-face">?</span>';
                    html += '</div>';
                }

                // Player name
                html += '<div class="omo-player-info">';
                html += '<span class="omo-player-name">' + this.escapeHtml(name) + '</span>';
                if (player.is_ai) {
                    html += '<span class="omo-ai-badge">' + __('AI', 'shortcode-arcade') + '</span>';
                }
                html += '</div>';

                // Odd indicator
                if (isOdd) {
                    html += '<div class="omo-odd-indicator">' + __('ODD!', 'shortcode-arcade') + '</div>';
                }

                html += '</div>';
            });

            html += '</div>';
            return html;
        },

        renderScoreboard: function(state) {
            let html = '<div class="omo-scoreboard-panel">';
            html += '<h3>' + __('Scores', 'shortcode-arcade') + '</h3>';
            html += '<div class="omo-scores">';

            const players = state.players || {};
            const seats = Object.keys(players).map(Number).sort((a, b) => a - b);

            seats.forEach((seat) => {
                const player = players[seat];
                const name = player.name || sprintf(__('Player %d', 'shortcode-arcade'), seat + 1);
                const score = state.scores[seat] ?? 0;
                const isMe = seat === this.mySeat;
                const isWinner = state.winners && state.winners.includes(seat);

                html += '<div class="omo-score-row' + (isMe ? ' is-me' : '') + (isWinner ? ' is-winner' : '') + '">';
                html += '<span class="omo-score-name">' + this.escapeHtml(name);
                if (player.is_ai) {
                    html += ' <span class="omo-ai-tag">' + __('AI', 'shortcode-arcade') + '</span>';
                }
                html += '</span>';
                html += '<span class="omo-score-value">' + score + '</span>';
                if (isWinner) {
                    html += '<span class="omo-winner-badge">' + __('Winner!', 'shortcode-arcade') + '</span>';
                }
                html += '</div>';
            });

            html += '</div>';
            html += '</div>';
            return html;
        },

        renderActions: function(state, awaitingGate, isGameOver) {
            let html = '';

            if (awaitingGate === 'start_game') {
                html += '<button class="sacga-btn sacga-btn-primary omo-action" data-action="begin_game">';
                html += __('Begin Game', 'shortcode-arcade');
                html += '</button>';
            } else if (awaitingGate === 'resolve_round') {
                html += '<button class="sacga-btn sacga-btn-primary omo-action" data-action="continue">';
                html += __('Continue', 'shortcode-arcade');
                html += '</button>';
            } else if (awaitingGate === 'next_round') {
                html += '<button class="sacga-btn sacga-btn-primary omo-action" data-action="continue">';
                html += __('Flip Again', 'shortcode-arcade');
                html += '</button>';
            } else if (isGameOver) {
                html += '<p class="omo-game-complete">' + __('Game Complete!', 'shortcode-arcade') + '</p>';
            }

            return html;
        },

        getWinnerNames: function(state) {
            if (!state.winners || state.winners.length === 0) {
                return __('No one', 'shortcode-arcade');
            }

            const players = state.players || {};
            const names = state.winners.map((seat) => {
                const player = players[seat];
                return player ? player.name : sprintf(__('Player %d', 'shortcode-arcade'), seat + 1);
            });

            return names.join(', ');
        },

        bindActions: function() {
            const self = this;
            const $board = $('#sacga-game-board');

            // Unbind previous handlers
            $board.off('click', '.omo-action');

            // Gate actions (begin_game, continue)
            $board.on('click', '.omo-action', function() {
                const action = $(this).data('action');
                if (action && self.onMove) {
                    self.onMove({ action: action });
                }
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
    window.SACGAGames['odd-man-out'] = OddManOutRenderer;

})(jQuery);
