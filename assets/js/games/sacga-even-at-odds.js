/**
 * Even at Odds Game Renderer
 *
 * @package ShortcodeArcade
 * @since 1.2.0
 */
(function($) {
    'use strict';

    const { __, sprintf } = wp.i18n;

    const EvenAtOddsRenderer = {
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
            const phase = state.phase;
            const myBid = state.bids && state.bids[this.mySeat];
            const canBid = phase === 'bidding' && !myBid && !awaitingGate;

            let html = '<div class="even-at-odds-game">';

            // Header
            html += '<div class="eao-header">';
            html += '<h2>' + __('Even at Odds', 'shortcode-arcade') + '</h2>';
            html += '<p class="eao-subtitle">' + sprintf(__('First to %d points wins', 'shortcode-arcade'), state.target_score) + '</p>';
            if (state.round > 0) {
                html += '<p class="eao-round">' + sprintf(__('Round %d', 'shortcode-arcade'), state.round) + '</p>';
            }
            html += '</div>';

            // Status area
            html += '<div class="eao-status">';
            html += this.renderStatus(state, awaitingGate, phase);
            html += '</div>';

            // Bidding area - only shown during bidding phase or when showing results
            if (phase === 'bidding' || state.last_result) {
                html += '<div class="eao-bidding-area">';
                html += this.renderBiddingArea(state, canBid, myBid);
                html += '</div>';
            }

            // Coin results area - shown after flipping
            if (state.coins && Object.keys(state.coins).length > 0) {
                html += '<div class="eao-coins-area">';
                html += this.renderCoinsArea(state);
                html += '</div>';
            }

            // Scoreboard
            html += '<div class="eao-scoreboard">';
            html += this.renderScoreboard(state);
            html += '</div>';

            // Action buttons
            html += '<div class="eao-actions">';
            html += this.renderActions(state, awaitingGate, canBid, isGameOver);
            html += '</div>';

            html += '</div>';

            $('#sacga-game-board').html(html);
        },

        renderStatus: function(state, awaitingGate, phase) {
            let html = '';

            if (awaitingGate === 'start_game') {
                html += '<p class="eao-message">' + __('Ready to start? Click Begin Game!', 'shortcode-arcade') + '</p>';
            } else if (awaitingGate === 'resolve_round') {
                const parity = state.result.parity;
                const heads = state.result.heads;
                const parityLabel = parity === 'even' ? __('EVEN', 'shortcode-arcade') : __('ODD', 'shortcode-arcade');
                html += '<div class="eao-result-reveal">';
                html += '<p class="eao-heads-count">' + sprintf(__('%d Heads total', 'shortcode-arcade'), heads) + '</p>';
                html += '<p class="eao-parity-result eao-parity-' + parity + '">' + parityLabel + ' ' + __('wins!', 'shortcode-arcade') + '</p>';
                html += '</div>';
            } else if (awaitingGate === 'next_round') {
                html += '<p class="eao-message">' + __('Ready for the next round?', 'shortcode-arcade') + '</p>';
            } else if (phase === 'bidding') {
                const myBid = state.bids && state.bids[this.mySeat];
                if (myBid && myBid !== 'hidden') {
                    html += '<p class="eao-message">' + __('Waiting for other players to bid...', 'shortcode-arcade') + '</p>';
                } else {
                    html += '<p class="eao-message">' + __('Place your bid: Even or Odd?', 'shortcode-arcade') + '</p>';
                }
            } else if (state.game_over) {
                const winnerNames = this.getWinnerNames(state);
                html += '<div class="eao-game-over">';
                html += '<p class="eao-winner">' + sprintf(__('%s wins!', 'shortcode-arcade'), winnerNames) + '</p>';
                html += '</div>';
            }

            return html;
        },

        renderBiddingArea: function(state, canBid, myBid) {
            let html = '<div class="eao-bid-options">';

            if (canBid) {
                // Show bidding buttons
                html += '<button class="sacga-btn eao-bid-btn eao-bid-even" data-action="bid" data-value="even">';
                html += '<span class="eao-bid-label">' + __('EVEN', 'shortcode-arcade') + '</span>';
                html += '</button>';
                html += '<button class="sacga-btn eao-bid-btn eao-bid-odd" data-action="bid" data-value="odd">';
                html += '<span class="eao-bid-label">' + __('ODD', 'shortcode-arcade') + '</span>';
                html += '</button>';
            } else if (myBid && myBid !== 'hidden') {
                // Show my confirmed bid
                const bidLabel = myBid === 'even' ? __('EVEN', 'shortcode-arcade') : __('ODD', 'shortcode-arcade');
                html += '<div class="eao-my-bid eao-bid-' + myBid + '">';
                html += '<span class="eao-bid-submitted">' + __('Your bid:', 'shortcode-arcade') + '</span>';
                html += '<span class="eao-bid-value">' + bidLabel + '</span>';
                html += '</div>';
            }

            // Show bid status for all players
            html += '<div class="eao-bid-status">';
            const players = state.players || {};
            const seats = Object.keys(players).map(Number).sort((a, b) => a - b);

            seats.forEach((seat) => {
                const player = players[seat];
                const bid = state.bids ? state.bids[seat] : null;
                const name = player.name || sprintf(__('Player %d', 'shortcode-arcade'), seat + 1);
                let bidStatus = '';

                if (bid === 'hidden') {
                    bidStatus = __('Ready', 'shortcode-arcade');
                } else if (bid === 'even' || bid === 'odd') {
                    bidStatus = bid === 'even' ? __('EVEN', 'shortcode-arcade') : __('ODD', 'shortcode-arcade');
                } else {
                    bidStatus = __('Waiting...', 'shortcode-arcade');
                }

                const isMe = seat === this.mySeat;
                html += '<div class="eao-player-bid' + (isMe ? ' is-me' : '') + (bid ? ' has-bid' : '') + '">';
                html += '<span class="eao-player-name">' + this.escapeHtml(name) + '</span>';
                html += '<span class="eao-bid-indicator">' + bidStatus + '</span>';
                html += '</div>';
            });

            html += '</div>';
            html += '</div>';

            return html;
        },

        renderCoinsArea: function(state) {
            let html = '<div class="eao-coins-grid">';

            const players = state.players || {};
            const seats = Object.keys(players).map(Number).sort((a, b) => a - b);

            seats.forEach((seat) => {
                const player = players[seat];
                const coin = state.coins[seat];
                const bid = state.last_result ? state.last_result.bids[seat] : null;
                const name = player.name || sprintf(__('Player %d', 'shortcode-arcade'), seat + 1);
                const parity = state.result.parity || (state.last_result ? state.last_result.parity : null);
                const isWinner = bid === parity;
                const isMe = seat === this.mySeat;

                html += '<div class="eao-coin-result' + (isWinner ? ' is-winner' : '') + (isMe ? ' is-me' : '') + '">';
                html += '<div class="eao-coin eao-coin-' + coin + '">';
                html += '<span class="eao-coin-face">' + (coin === 'heads' ? 'H' : 'T') + '</span>';
                html += '</div>';
                html += '<span class="eao-coin-player">' + this.escapeHtml(name) + '</span>';
                if (bid) {
                    const bidLabel = bid === 'even' ? __('Even', 'shortcode-arcade') : __('Odd', 'shortcode-arcade');
                    html += '<span class="eao-coin-bid">' + bidLabel + '</span>';
                }
                if (isWinner) {
                    html += '<span class="eao-point-badge">+1</span>';
                }
                html += '</div>';
            });

            html += '</div>';
            return html;
        },

        renderScoreboard: function(state) {
            let html = '<div class="eao-scoreboard-panel">';
            html += '<h3>' + __('Scores', 'shortcode-arcade') + '</h3>';
            html += '<div class="eao-scores">';

            const players = state.players || {};
            const seats = Object.keys(players).map(Number).sort((a, b) => a - b);

            seats.forEach((seat) => {
                const player = players[seat];
                const name = player.name || sprintf(__('Player %d', 'shortcode-arcade'), seat + 1);
                const score = state.scores[seat] ?? 0;
                const isMe = seat === this.mySeat;
                const isWinner = state.winners && state.winners.includes(seat);

                html += '<div class="eao-score-row' + (isMe ? ' is-me' : '') + (isWinner ? ' is-winner' : '') + '">';
                html += '<span class="eao-score-name">' + this.escapeHtml(name);
                if (player.is_ai) {
                    html += ' <span class="eao-ai-badge">' + __('AI', 'shortcode-arcade') + '</span>';
                }
                html += '</span>';
                html += '<span class="eao-score-value">' + score + '</span>';
                if (isWinner) {
                    html += '<span class="eao-winner-badge">' + __('Winner!', 'shortcode-arcade') + '</span>';
                }
                html += '</div>';
            });

            html += '</div>';
            html += '</div>';
            return html;
        },

        renderActions: function(state, awaitingGate, canBid, isGameOver) {
            let html = '';

            if (awaitingGate === 'start_game') {
                html += '<button class="sacga-btn sacga-btn-primary eao-action" data-action="begin_game">';
                html += __('Begin Game', 'shortcode-arcade');
                html += '</button>';
            } else if (awaitingGate === 'resolve_round') {
                html += '<button class="sacga-btn sacga-btn-primary eao-action" data-action="continue">';
                html += __('Continue', 'shortcode-arcade');
                html += '</button>';
            } else if (awaitingGate === 'next_round') {
                html += '<button class="sacga-btn sacga-btn-primary eao-action" data-action="continue">';
                html += __('Next Round', 'shortcode-arcade');
                html += '</button>';
            } else if (isGameOver) {
                html += '<p class="eao-game-complete">' + __('Game Complete!', 'shortcode-arcade') + '</p>';
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
            $board.off('click', '.eao-action');
            $board.off('click', '.eao-bid-btn');

            // Gate actions (begin_game, continue)
            $board.on('click', '.eao-action', function() {
                const action = $(this).data('action');
                if (action && self.onMove) {
                    self.onMove({ action: action });
                }
            });

            // Bid buttons
            $board.on('click', '.eao-bid-btn', function() {
                const $btn = $(this);
                const action = $btn.data('action');
                const value = $btn.data('value');
                if (action && value && self.onMove) {
                    self.onMove({ action: action, value: value });
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
    window.SACGAGames['even-at-odds'] = EvenAtOddsRenderer;

})(jQuery);
