/**
 * War Game Renderer
 */
(function($) {
    'use strict';

    const { __, sprintf } = wp.i18n;

    const WarRenderer = {
        mySeat: null,
        onMove: null,
        state: null,
        lastTurnCount: null,

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            const shouldAnimate = this.lastTurnCount !== null && this.state.turn_count !== this.lastTurnCount;
            this.lastTurnCount = this.state.turn_count;

            this.renderBoard(shouldAnimate);
            this.bindActions();
        },

        renderBoard: function(shouldAnimate) {
            const state = this.state;
            const isMyTurn = state.current_turn === this.mySeat;
            const isGameOver = !!state.game_over;
            const potCount = state.battle?.pot?.length || 0;
            const lastResult = state.last_result || {};
            const warDepth = lastResult.war_depth || 0;
            const wasWar = !!lastResult.was_war;
            const animClass = shouldAnimate ? ' war-animating' : '';
            const warClass = wasWar ? ' war-was-war' : '';

            let html = '<div class="war-game sacga-game-war' + (isMyTurn ? ' is-my-turn' : '') + animClass + warClass + '">';
            html += '<div class="war-header">';
            html += '<div class="war-title">' + __( 'War', 'shortcode-arcade' ) + '</div>';
            html += '<div class="war-subtitle">' + __( 'Flip for glory. Higher rank takes the pot.', 'shortcode-arcade' ) + '</div>';
            html += '</div>';

            html += '<div class="war-status">';
            html += '<div class="war-status-line">' + sprintf( __( 'Turn %d', 'shortcode-arcade' ), state.turn_count || 0 ) + '</div>';
            html += '<div class="war-status-line">' + sprintf( __( 'War depth: %d', 'shortcode-arcade' ), warDepth ) + '</div>';
            if (lastResult.turn_summary) {
                html += '<div class="war-status-line war-summary">' + this.escapeHtml(lastResult.turn_summary) + '</div>';
            }
            html += '<div class="war-status-message" aria-live="polite">' + this.escapeHtml(lastResult.message || '') + '</div>';
            html += '</div>';

            html += '<div class="war-table">';
            html += this.renderSeat(1, 'opponent');

            html += '<div class="war-battle-area">';
            html += '<div class="war-battle-header">';
            html += '<span class="war-badge">' + __( 'Battle Zone', 'shortcode-arcade' ) + '</span>';
            html += '<span class="war-pot">' + sprintf( __( 'Pot: %d', 'shortcode-arcade' ), potCount ) + '</span>';
            html += '</div>';

            if (wasWar) {
                html += '<div class="war-alert">' + __( 'WAR!', 'shortcode-arcade' ) + '</div>';
            }

            html += '<div class="war-battle-cards">';
            html += this.renderBattleCard(1, 'opponent');
            html += this.renderBattleCard(0, 'player');
            html += '</div>';
            html += '</div>';

            html += this.renderSeat(0, 'player');
            html += '</div>';

            html += '<div class="war-controls">';
            html += '<button class="sacga-btn sacga-btn-primary war-action" data-action="flip"' + (isMyTurn && !isGameOver ? '' : ' disabled') + '>' + __( 'Flip', 'shortcode-arcade' ) + '</button>';
            if (!isMyTurn && !isGameOver) {
                const waitingName = this.getPlayerName(state.current_turn);
                html += '<div class="war-waiting">' + sprintf( __( 'Waiting for %s to flip...', 'shortcode-arcade' ), this.escapeHtml(waitingName) ) + '</div>';
            }
            if (isGameOver) {
                html += '<div class="war-gameover">' + __( 'Game over. Check the results panel for the winner.', 'shortcode-arcade' ) + '</div>';
            }
            html += '</div>';

            html += '</div>';

            $('#sacga-game-board').html(html);
        },

        renderSeat: function(seat, positionClass) {
            const player = this.state.players?.[seat] || {};
            const name = player.name || sprintf( __( 'Player %d', 'shortcode-arcade' ), seat + 1 );
            const drawCount = player.draw_pile ? player.draw_pile.length : 0;
            const wonCount = player.won_pile ? player.won_pile.length : 0;
            const totalCards = player.total_cards ?? (drawCount + wonCount);
            const isWinner = this.state.last_result?.winner_seat === seat;

            let html = '<div class="war-seat war-seat-' + positionClass + (isWinner ? ' war-seat-winner' : '') + '">';
            html += '<div class="war-seat-header">';
            html += '<div class="war-seat-name">' + this.escapeHtml(name) + (player.is_ai ? ' <span class="war-ai">' + __( 'AI', 'shortcode-arcade' ) + '</span>' : '') + '</div>';
            html += '<div class="war-seat-count">' + sprintf( __( 'Total cards: %d', 'shortcode-arcade' ), totalCards ) + '</div>';
            html += '</div>';
            html += '<div class="war-seat-stacks">';
            html += '<div class="war-stack">';
            html += '<div class="war-stack-label">' + __( 'Draw', 'shortcode-arcade' ) + '</div>';
            html += this.renderStack(drawCount);
            html += '</div>';
            html += '<div class="war-stack">';
            html += '<div class="war-stack-label">' + __( 'Won', 'shortcode-arcade' ) + '</div>';
            html += this.renderStack(wonCount);
            html += '</div>';
            html += '</div>';
            html += '</div>';

            return html;
        },

        renderStack: function(count) {
            const visibleCards = Math.min(count, 5);
            let html = '<div class="war-card-stack" data-count="' + count + '">';

            for (let i = 0; i < visibleCards; i++) {
                html += '<div class="sacga-card sacga-card-back"></div>';
            }

            if (count === 0) {
                html += '<div class="war-stack-empty">' + __( 'Empty', 'shortcode-arcade' ) + '</div>';
            }

            html += '</div>';
            return html;
        },

        renderBattleCard: function(seat, positionClass) {
            const card = this.state.battle?.face_up?.[seat] || null;
            const label = this.getPlayerName(seat);
            let html = '<div class="war-battle-card war-battle-card-' + positionClass + '">';
            html += '<div class="war-battle-label">' + this.escapeHtml(label) + '</div>';
            if (card && card.rank) {
                html += window.SACGACards.renderCard(card, { size: 'large' });
            } else {
                html += '<div class="war-card-placeholder">' + __( 'Face down', 'shortcode-arcade' ) + '</div>';
            }
            html += '</div>';
            return html;
        },

        bindActions: function() {
            const self = this;
            const $board = $('#sacga-game-board');
            $board.off('click', '.war-action');
            $board.on('click', '.war-action', function() {
                const $button = $(this);
                if ($button.is(':disabled')) {
                    return;
                }
                if (!self.onMove) {
                    return;
                }
                const action = $button.data('action');
                if (action === 'flip') {
                    self.onMove({ action: 'flip' });
                }
            });
        },

        getPlayerName: function(seat) {
            const player = this.state.players?.[seat] || {};
            return player.name || sprintf( __( 'Player %d', 'shortcode-arcade' ), seat + 1 );
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
    window.SACGAGames.war = WarRenderer;

})(jQuery);
