/**
 * Fourfall Game Renderer
 */
(function($) {
    'use strict';

    const { __, _n, _x, sprintf } = wp.i18n;

    const FourfallRenderer = {
        mySeat: null,
        onMove: null,
        state: null,

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            const board = this.state.board;
            const isMyTurn = this.state.current_turn === mySeat && !this.state.game_over;
            const winningCells = this.state.winning_cells || [];
            const match = this.state.match || {};
            const wins = match.wins || [0, 0];
            const gamesPerMatch = match.games_per_match || 1;
            const winsRequired = match.wins_required || Math.ceil(gamesPerMatch / 2);
            const gameNumber = match.game_number || 1;
            const redName = this.state.players?.[0]?.name || 'Red';
            const yellowName = this.state.players?.[1]?.name || 'Yellow';

            let html = '<div class="fourfall-board">';

            // Column drop buttons (only show when it's player's turn)
            html += '<div class="fourfall-drop-row">';
            for (let col = 0; col < 7; col++) {
                const canDrop = board[0][col] === 0 && isMyTurn;
                const discClass = mySeat === 0 ? 'fourfall-disc-red' : 'fourfall-disc-yellow';
                html += '<div class="fourfall-drop-zone' + (canDrop ? ' fourfall-droppable' : '') + '" data-col="' + col + '">';
                if (canDrop) {
                    html += '<div class="fourfall-preview-disc ' + discClass + '"></div>';
                }
                html += '</div>';
            }
            html += '</div>';

            // Game board
            for (let row = 0; row < 6; row++) {
                html += '<div class="fourfall-row">';

                for (let col = 0; col < 7; col++) {
                    const disc = board[row][col];
                    const isWinning = winningCells.some(c => c.row === row && c.col === col);

                    html += '<div class="fourfall-cell" data-row="' + row + '" data-col="' + col + '">';
                    html += '<div class="fourfall-slot">';

                    if (disc > 0) {
                        const discClass = disc === 1 ? 'fourfall-disc-red' : 'fourfall-disc-yellow';
                        const winClass = isWinning ? ' fourfall-winning' : '';
                        html += '<div class="fourfall-disc ' + discClass + winClass + '"></div>';
                    }

                    html += '</div>';
                    html += '</div>';
                }

                html += '</div>';
            }

            html += '</div>';

            // Game info
            html += '<div class="fourfall-info">';
            html += '<div class="fourfall-status">';

            if (this.state.game_over) {
                if (match.match_over && match.winner !== null) {
                    const winnerName = this.state.players[match.winner]?.name || (match.winner === 0 ? 'Red' : 'Yellow');
                    html += '<span class="fourfall-winner">' + winnerName + ' ' + __('wins the match!', 'shortcode-arcade') + '</span>';
                } else if (this.state.winning_cells && this.state.winning_cells.length > 0) {
                    const winner = board[this.state.winning_cells[0].row][this.state.winning_cells[0].col];
                    const winnerSeat = winner === 1 ? 0 : 1;
                    const winnerName = this.state.players[winnerSeat]?.name || (winnerSeat === 0 ? 'Red' : 'Yellow');
                    html += '<span class="fourfall-winner">' + winnerName + ' ' + __('wins!', 'shortcode-arcade') + '</span>';
                } else {
                    html += '<span class="fourfall-draw">' + __("It's a draw!", 'shortcode-arcade') + '</span>';
                }
            } else {
                const turnColor = this.state.current_turn === 0 ? 'Red' : 'Yellow';
                const turnName = this.state.players[this.state.current_turn]?.name || turnColor;
                if (isMyTurn) {
                    html += '<span class="fourfall-your-turn">' + __('Your turn - click a column to drop', 'shortcode-arcade') + '</span>';
                } else {
                    html += '<span class="fourfall-waiting">' + __('Waiting for', 'shortcode-arcade') + ' ' + turnName + '</span>';
                }
            }

            html += '</div>';
            html += '<div class="fourfall-match">';
            html += '<div class="fourfall-round-indicator">' + sprintf(__('Game %1$d of %2$d', 'shortcode-arcade'), gameNumber, gamesPerMatch) + '</div>';
            html += '<div class="fourfall-match-score">';
            html += '<span class="fourfall-player-score fourfall-player-red">' + redName + ': ' + wins[0] + '</span>';
            html += '<span class="fourfall-player-score fourfall-player-yellow">' + yellowName + ': ' + wins[1] + '</span>';
            html += '</div>';
            html += '<div class="fourfall-match-target">' + sprintf(__('First to %d wins', 'shortcode-arcade'), winsRequired) + '</div>';
            html += '</div>';
            html += '<div class="fourfall-move-count">' + __('Moves:', 'shortcode-arcade') + ' ' + this.state.move_count + '</div>';
            html += '</div>';

            $('#sacga-game-board').html(html);

            if (isMyTurn) {
                this.bindBoardEvents();
            }
        },

        bindBoardEvents: function() {
            const self = this;

            $('.fourfall-drop-zone.fourfall-droppable').on('click', function() {
                const col = parseInt($(this).data('col'));
                self.dropDisc(col);
            });

            // Also allow clicking on cells in that column
            $('.fourfall-cell').on('click', function() {
                const col = parseInt($(this).data('col'));
                if (self.state.board[0][col] === 0) {
                    self.dropDisc(col);
                }
            });
        },

        dropDisc: function(col) {
            if (this.onMove) {
                this.onMove({ col: col });
            }
        }
    };

    // Register renderer
    window.SACGAGames = window.SACGAGames || {};
    window.SACGAGames.fourfall = FourfallRenderer;

})(jQuery);
