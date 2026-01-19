/**
 * Backgammon Game Renderer
 *
 * Board Layout (from white/player 0's perspective):
 *
 *   13  14  15  16  17  18  |BAR|  19  20  21  22  23  24
 *   ▼   ▼   ▼   ▼   ▼   ▼         ▼   ▼   ▼   ▼   ▼   ▼
 *
 *   ▲   ▲   ▲   ▲   ▲   ▲         ▲   ▲   ▲   ▲   ▲   ▲
 *   12  11  10   9   8   7  |BAR|   6   5   4   3   2   1
 *
 * White (player 0) moves from 24 → 1 and bears off on the right
 * Black (player 1) moves from 1 → 24 and bears off on the left
 */
(function($) {
    'use strict';

    const { __, sprintf } = wp.i18n;

    const BackgammonRenderer = {
        mySeat: null,
        onMove: null,
        state: null,
        selectedPoint: null,
        validMoves: [],

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;
            this.selectedPoint = null;
            this.validMoves = [];

            this.renderBoard();
            this.bindEvents();
        },

        renderBoard: function() {
            const state = this.state;
            const isMyTurn = state.current_turn === this.mySeat;
            const phase = state.phase;
            const board = state.board;
            const bar = state.bar;
            const borneOff = state.borne_off;

            let html = '<div class="backgammon-game">';

            // Game header with player info
            html += '<div class="backgammon-header">';
            html += this.renderPlayerInfo(state);
            html += '</div>';

            // Main board area with bearing off trays
            html += '<div class="backgammon-table">';

            // Left bearing off tray (for player 1 / black)
            html += '<div class="backgammon-tray backgammon-tray-left">';
            html += '<div class="tray-label">' + __('Off', 'shortcode-arcade') + '</div>';
            html += '<div class="tray-checkers tray-p1">';
            html += this.renderBorneOff(1, borneOff[1]);
            html += '</div>';
            html += '</div>';

            // Main board
            html += '<div class="backgammon-board">';

            // Top row: points 13-18 (left quadrant) and 19-24 (right quadrant)
            html += '<div class="backgammon-row backgammon-row-top">';
            html += this.renderQuadrant([13, 14, 15, 16, 17, 18], 'top', board);
            html += '<div class="backgammon-bar-space"></div>';
            html += this.renderQuadrant([19, 20, 21, 22, 23, 24], 'top', board);
            html += '</div>';

            // Center bar
            html += '<div class="backgammon-bar-row">';
            html += this.renderBar(bar);
            html += '</div>';

            // Bottom row: points 12-7 (left quadrant) and 6-1 (right quadrant)
            html += '<div class="backgammon-row backgammon-row-bottom">';
            html += this.renderQuadrant([12, 11, 10, 9, 8, 7], 'bottom', board);
            html += '<div class="backgammon-bar-space"></div>';
            html += this.renderQuadrant([6, 5, 4, 3, 2, 1], 'bottom', board);
            html += '</div>';

            html += '</div>'; // .backgammon-board

            // Right bearing off tray (for player 0 / white)
            html += '<div class="backgammon-tray backgammon-tray-right">';
            html += '<div class="tray-label">' + __('Off', 'shortcode-arcade') + '</div>';
            html += '<div class="tray-checkers tray-p0">';
            html += this.renderBorneOff(0, borneOff[0]);
            html += '</div>';
            html += '</div>';

            html += '</div>'; // .backgammon-table

            // Dice and controls
            html += '<div class="backgammon-controls">';
            html += this.renderDice(state);
            html += this.renderActions(state, isMyTurn);
            html += '</div>';

            // Status area
            html += '<div class="backgammon-status">';
            html += this.renderStatus(state, isMyTurn);
            html += '</div>';

            html += '</div>'; // .backgammon-game

            $('#sacga-game-board').html(html);
        },

        renderPlayerInfo: function(state) {
            const players = state.players || {};
            let html = '<div class="backgammon-players">';

            [0, 1].forEach((seat) => {
                const player = players[seat] || {};
                const name = player.name || sprintf(__('Player %d', 'shortcode-arcade'), seat + 1);
                const color = seat === 0 ? 'white' : 'black';
                const isCurrent = state.current_turn === seat;
                const isMe = seat === this.mySeat;

                html += '<div class="backgammon-player' + (isCurrent ? ' is-current' : '') + (isMe ? ' is-me' : '') + '">';
                html += '<span class="player-checker-icon checker-' + color + '"></span>';
                html += '<span class="player-name">' + this.escapeHtml(name) + '</span>';
                if (player.is_ai) {
                    html += '<span class="player-ai-badge">AI</span>';
                }
                if (isCurrent) {
                    html += '<span class="player-turn-badge">' + __('Turn', 'shortcode-arcade') + '</span>';
                }
                html += '<span class="player-off-count">' + __('Off:', 'shortcode-arcade') + ' ' + state.borne_off[seat] + '/15</span>';
                html += '</div>';
            });

            html += '</div>';
            return html;
        },

        renderQuadrant: function(points, position, board) {
            let html = '<div class="backgammon-quadrant">';

            for (const pointNum of points) {
                html += this.renderPoint(pointNum, board[pointNum], position);
            }

            html += '</div>';
            return html;
        },

        renderPoint: function(pointNum, pointData, position) {
            const player = pointData.player;
            const count = pointData.count;
            const isOdd = pointNum % 2 === 1;
            const colorClass = isOdd ? 'point-dark' : 'point-light';
            const isSelected = this.selectedPoint === pointNum;
            const isValidDest = this.validMoves.some(m => m.to === pointNum);

            let classes = 'backgammon-point ' + colorClass + ' point-' + position;
            if (isSelected) classes += ' selected';
            if (isValidDest) classes += ' valid-dest';
            if (player === this.mySeat && count > 0 && this.state.phase === 'move' &&
                this.state.current_turn === this.mySeat && this.state.bar[this.mySeat] === 0) {
                classes += ' can-select';
            }

            let html = '<div class="' + classes + '" data-point="' + pointNum + '">';

            // Point number label
            html += '<span class="point-num">' + pointNum + '</span>';

            // Triangle shape
            html += '<div class="point-triangle"></div>';

            // Checkers stack
            html += '<div class="point-checkers">';
            if (count > 0) {
                const displayCount = Math.min(count, 5);
                const checkerColor = player === 0 ? 'white' : 'black';

                for (let i = 0; i < displayCount; i++) {
                    html += '<div class="backgammon-checker checker-' + checkerColor + '"></div>';
                }

                if (count > 5) {
                    html += '<span class="checker-count">(' + count + ')</span>';
                }
            }
            html += '</div>';

            html += '</div>';
            return html;
        },

        renderBar: function(bar) {
            let html = '<div class="backgammon-bar">';

            // Top section - player 1's hit checkers
            html += '<div class="bar-section bar-top">';
            if (bar[1] > 0) {
                const count = Math.min(bar[1], 4);
                for (let i = 0; i < count; i++) {
                    const canClick = this.mySeat === 1 && this.state.phase === 'move' && this.state.current_turn === 1;
                    html += '<div class="backgammon-checker checker-black' + (canClick ? ' bar-checker' : '') + '" data-player="1"></div>';
                }
                if (bar[1] > 4) {
                    html += '<span class="bar-count">(' + bar[1] + ')</span>';
                }
            }
            html += '</div>';

            // Middle label
            html += '<div class="bar-label">BAR</div>';

            // Bottom section - player 0's hit checkers
            html += '<div class="bar-section bar-bottom">';
            if (bar[0] > 0) {
                const count = Math.min(bar[0], 4);
                for (let i = 0; i < count; i++) {
                    const canClick = this.mySeat === 0 && this.state.phase === 'move' && this.state.current_turn === 0;
                    html += '<div class="backgammon-checker checker-white' + (canClick ? ' bar-checker' : '') + '" data-player="0"></div>';
                }
                if (bar[0] > 4) {
                    html += '<span class="bar-count">(' + bar[0] + ')</span>';
                }
            }
            html += '</div>';

            html += '</div>';
            return html;
        },

        renderBorneOff: function(player, count) {
            let html = '';
            const color = player === 0 ? 'white' : 'black';
            const displayCount = Math.min(count, 5);

            for (let i = 0; i < displayCount; i++) {
                html += '<div class="backgammon-checker checker-' + color + ' checker-small"></div>';
            }

            if (count > 0) {
                html += '<span class="tray-count">' + count + '</span>';
            }

            return html;
        },

        renderDice: function(state) {
            const dice = state.dice || [];
            const diceRemaining = state.dice_remaining || [];

            if (dice.length === 0) {
                return '<div class="backgammon-dice"></div>';
            }

            let html = '<div class="backgammon-dice">';

            // For doubles, show 4 dice
            if (dice.length === 2 && dice[0] === dice[1]) {
                const usedCount = 4 - diceRemaining.length;
                for (let i = 0; i < 4; i++) {
                    html += this.renderSingleDie(dice[0], i < usedCount);
                }
            } else {
                // Non-doubles: track which specific dice are used
                let remainingCopy = diceRemaining.slice();
                for (let i = 0; i < dice.length; i++) {
                    const idx = remainingCopy.indexOf(dice[i]);
                    const isUsed = (idx === -1);
                    if (!isUsed) {
                        remainingCopy.splice(idx, 1);
                    }
                    html += this.renderSingleDie(dice[i], isUsed);
                }
            }

            html += '</div>';
            return html;
        },

        renderSingleDie: function(value, isUsed) {
            const usedClass = isUsed ? ' die-used' : '';

            // Create pip positions based on value
            // Standard die pip layout positions
            const pipLayouts = {
                1: ['center'],
                2: ['top-right', 'bottom-left'],
                3: ['top-right', 'center', 'bottom-left'],
                4: ['top-left', 'top-right', 'bottom-left', 'bottom-right'],
                5: ['top-left', 'top-right', 'center', 'bottom-left', 'bottom-right'],
                6: ['top-left', 'top-right', 'middle-left', 'middle-right', 'bottom-left', 'bottom-right']
            };

            const pips = pipLayouts[value] || [];

            let html = '<div class="bg-die' + usedClass + '" data-value="' + value + '">';
            html += '<div class="die-inner">';

            pips.forEach(pos => {
                html += '<span class="pip pip-' + pos + '"></span>';
            });

            html += '</div></div>';
            return html;
        },

        renderActions: function(state, isMyTurn) {
            const phase = state.phase;
            const isGameOver = !!state.game_over;
            const diceRemaining = state.dice_remaining || [];

            let html = '<div class="backgammon-actions">';

            if (isGameOver) {
                const winner = state.winner;
                const winnerName = state.players[winner] ? state.players[winner].name : __('Player', 'shortcode-arcade');
                html += '<div class="game-over-msg">' + sprintf(__('%s wins!', 'shortcode-arcade'), this.escapeHtml(winnerName)) + '</div>';
            } else if (isMyTurn) {
                if (phase === 'roll') {
                    html += '<button class="sacga-btn sacga-btn-primary btn-roll" data-action="roll">';
                    html += __('Roll Dice', 'shortcode-arcade');
                    html += '</button>';
                } else if (phase === 'move') {
                    if (diceRemaining.length === 0) {
                        html += '<div class="move-msg">' + __('Turn complete', 'shortcode-arcade') + '</div>';
                    } else if (state.bar[this.mySeat] > 0) {
                        html += '<div class="move-msg move-hint">' + __('Click your checker on the bar to enter', 'shortcode-arcade') + '</div>';
                    } else if (this.selectedPoint !== null) {
                        html += '<div class="move-msg move-hint">' + __('Click a highlighted point to move', 'shortcode-arcade') + '</div>';
                    } else {
                        html += '<div class="move-msg move-hint">' + __('Click a checker to select it', 'shortcode-arcade') + '</div>';
                    }
                }
            } else {
                const currentPlayer = state.players[state.current_turn];
                const name = currentPlayer ? currentPlayer.name : __('Opponent', 'shortcode-arcade');
                html += '<div class="waiting-msg">' + sprintf(__("Waiting for %s..."), this.escapeHtml(name)) + '</div>';
            }

            html += '</div>';
            return html;
        },

        renderStatus: function(state, isMyTurn) {
            let html = '';

            // Show if player has checkers on bar
            if (state.bar[this.mySeat] > 0 && isMyTurn && state.phase === 'move') {
                html += '<div class="status-warning">';
                html += sprintf(__('You have %d checker(s) on the bar - you must enter first!', 'shortcode-arcade'), state.bar[this.mySeat]);
                html += '</div>';
            }

            return html;
        },

        bindEvents: function() {
            const self = this;
            const $board = $('#sacga-game-board');
            const isMyTurn = this.state.current_turn === this.mySeat;
            const phase = this.state.phase;

            // Unbind previous
            $board.off('.backgammon');

            if (!isMyTurn || this.state.game_over) {
                return;
            }

            // Roll dice button
            $board.on('click.backgammon', '.btn-roll', function() {
                if (self.onMove) {
                    self.onMove({ action: 'roll' });
                }
            });

            if (phase === 'move') {
                // Click on bar checker to select (when we have checkers on bar)
                $board.on('click.backgammon', '.bar-checker', function() {
                    const player = parseInt($(this).data('player'), 10);
                    if (player === self.mySeat && self.state.bar[self.mySeat] > 0) {
                        self.handleBarClick();
                    }
                });

                // Click on point
                $board.on('click.backgammon', '.backgammon-point', function() {
                    const point = parseInt($(this).data('point'), 10);
                    self.handlePointClick(point);
                });

                // Click on bearing off tray
                $board.on('click.backgammon', '.backgammon-tray-right', function() {
                    if (self.mySeat === 0) {
                        self.handleBearOffClick();
                    }
                });

                $board.on('click.backgammon', '.backgammon-tray-left', function() {
                    if (self.mySeat === 1) {
                        self.handleBearOffClick();
                    }
                });
            }
        },

        handlePointClick: function(point) {
            const board = this.state.board;
            const pointData = board[point];

            // If we have a selection (including bar), check if clicking on valid destination
            if (this.selectedPoint !== null) {
                const move = this.validMoves.find(m => m.to === point);
                if (move && this.onMove) {
                    this.onMove(move);
                    return;
                }
            }

            // Can't select board pieces if we have checkers on bar
            if (this.state.bar[this.mySeat] > 0) {
                // Deselect if clicking elsewhere
                if (this.selectedPoint !== null) {
                    this.selectedPoint = null;
                    this.validMoves = [];
                    this.renderBoard();
                    this.bindEvents();
                }
                return;
            }

            // If clicking on our own checker, select it
            if (pointData.player === this.mySeat && pointData.count > 0) {
                this.selectedPoint = point;
                this.validMoves = this.getValidMovesFrom(point);
                this.renderBoard();
                this.bindEvents();
                return;
            }

            // Deselect
            if (this.selectedPoint !== null) {
                this.selectedPoint = null;
                this.validMoves = [];
                this.renderBoard();
                this.bindEvents();
            }
        },

        handleBarClick: function() {
            this.selectedPoint = 'bar';
            this.validMoves = this.getValidMovesFromBar();
            this.renderBoard();
            this.bindEvents();

            // Show bar as selected
            $('.bar-' + (this.mySeat === 0 ? 'bottom' : 'top')).addClass('selected');
        },

        handleBearOffClick: function() {
            if (this.selectedPoint === null || this.selectedPoint === 'bar') {
                return;
            }

            const move = this.validMoves.find(m => m.to === 'off');
            if (move && this.onMove) {
                this.onMove(move);
            }
        },

        getValidMovesFrom: function(point) {
            const moves = [];
            const diceRemaining = this.state.dice_remaining || [];
            const uniqueDice = [...new Set(diceRemaining)];
            const direction = this.mySeat === 0 ? -1 : 1;
            const board = this.state.board;

            for (const die of uniqueDice) {
                const dest = point + (direction * die);

                // Normal move within board
                if (dest >= 1 && dest <= 24) {
                    if (this.canLandOn(dest)) {
                        moves.push({
                            action: 'move',
                            from: point,
                            to: dest,
                            die_value: die
                        });
                    }
                }

                // Bearing off
                if (this.canBearOff()) {
                    if (this.mySeat === 0 && dest <= 0) {
                        // Exact bear off or overshoot with highest checker
                        if (dest === 0 || this.isHighestChecker(point)) {
                            moves.push({
                                action: 'move',
                                from: point,
                                to: 'off',
                                die_value: die
                            });
                        }
                    } else if (this.mySeat === 1 && dest >= 25) {
                        if (dest === 25 || this.isHighestChecker(point)) {
                            moves.push({
                                action: 'move',
                                from: point,
                                to: 'off',
                                die_value: die
                            });
                        }
                    }
                }
            }

            return moves;
        },

        getValidMovesFromBar: function() {
            const moves = [];
            const diceRemaining = this.state.dice_remaining || [];
            const uniqueDice = [...new Set(diceRemaining)];

            for (const die of uniqueDice) {
                // Player 0 enters at 25-die (so die=1 enters at 24, die=6 enters at 19)
                // Player 1 enters at die (so die=1 enters at 1, die=6 enters at 6)
                const entryPoint = this.mySeat === 0 ? (25 - die) : die;

                if (this.canLandOn(entryPoint)) {
                    moves.push({
                        action: 'move',
                        from: 'bar',
                        to: entryPoint,
                        die_value: die
                    });
                }
            }

            return moves;
        },

        canLandOn: function(point) {
            const board = this.state.board;
            const dest = board[point];

            if (!dest) return false;

            // Empty point
            if (dest.player === null) {
                return true;
            }

            // Own checker
            if (dest.player === this.mySeat) {
                return true;
            }

            // Opponent's blot (single checker)
            if (dest.count === 1) {
                return true;
            }

            // Blocked
            return false;
        },

        canBearOff: function() {
            const board = this.state.board;
            const bar = this.state.bar;

            // Can't bear off if on bar
            if (bar[this.mySeat] > 0) {
                return false;
            }

            // Check all checkers are in home board
            for (let i = 1; i <= 24; i++) {
                if (board[i].player === this.mySeat && board[i].count > 0) {
                    // Player 0's home is 1-6
                    if (this.mySeat === 0 && i > 6) return false;
                    // Player 1's home is 19-24
                    if (this.mySeat === 1 && i < 19) return false;
                }
            }

            return true;
        },

        isHighestChecker: function(point) {
            const board = this.state.board;

            if (this.mySeat === 0) {
                // Check if any checker on higher point (closer to 6)
                for (let i = 6; i > point; i--) {
                    if (board[i].player === this.mySeat && board[i].count > 0) {
                        return false;
                    }
                }
            } else {
                // Check if any checker on lower point (closer to 19)
                for (let i = 19; i < point; i++) {
                    if (board[i].player === this.mySeat && board[i].count > 0) {
                        return false;
                    }
                }
            }

            return true;
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

    // Register renderer
    window.SACGAGames = window.SACGAGames || {};
    window.SACGAGames.backgammon = BackgammonRenderer;

})(jQuery);
