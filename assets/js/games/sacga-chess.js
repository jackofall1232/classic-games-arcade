/**
 * Chess Game Renderer
 */
(function($) {
    'use strict';

    const { __, _n, _x } = wp.i18n;

    // Chess piece Unicode symbols
    const PIECE_SYMBOLS = {
        1: '\u2659',   // White Pawn
        2: '\u2656',   // White Rook
        3: '\u2658',   // White Knight
        4: '\u2657',   // White Bishop
        5: '\u2655',   // White Queen
        6: '\u2654',   // White King
        '-1': '\u265F', // Black Pawn
        '-2': '\u265C', // Black Rook
        '-3': '\u265E', // Black Knight
        '-4': '\u265D', // Black Bishop
        '-5': '\u265B', // Black Queen
        '-6': '\u265A', // Black King
    };

    const ChessRenderer = {
        selectedPiece: null,
        validMoves: [],
        mySeat: null,
        onMove: null,
        state: null,

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;
            this.selectedPiece = null;
            this.validMoves = [];

            const board = this.state.board;
            const isMyTurn = this.state.current_turn === mySeat && !this.state.game_over;
            const lastMove = this.state.last_move;

            let html = '<div class="chess-board">';

            for (let row = 0; row < 8; row++) {
                html += '<div class="chess-row">';

                for (let col = 0; col < 8; col++) {
                    const isLight = (row + col) % 2 === 0;
                    const piece = board[row][col];
                    const cellClass = isLight ? 'chess-cell-light' : 'chess-cell-dark';

                    let extraClass = '';
                    if (lastMove) {
                        if ((lastMove.from.row === row && lastMove.from.col === col) ||
                            (lastMove.to.row === row && lastMove.to.col === col)) {
                            extraClass = ' chess-last-move';
                        }
                    }

                    html += '<div class="chess-cell ' + cellClass + extraClass + '" data-row="' + row + '" data-col="' + col + '">';

                    if (piece !== 0) {
                        const pieceClass = this.getPieceClass(piece);
                        html += '<span class="chess-piece ' + pieceClass + '" data-row="' + row + '" data-col="' + col + '">';
                        html += PIECE_SYMBOLS[piece] || '';
                        html += '</span>';
                    }

                    html += '</div>';
                }

                html += '</div>';
            }

            html += '</div>';

            // Game info
            html += '<div class="chess-info">';

            // Captured pieces display
            html += '<div class="chess-captured">';
            html += '<div class="chess-captured-row">';
            html += '<span class="chess-captured-label">' + __('Your captures:', 'shortcode-arcade') + '</span>';
            html += '<span class="chess-captured-pieces">' + this.renderCaptured(this.state.captured[mySeat] || []) + '</span>';
            html += '</div>';
            html += '<div class="chess-captured-row">';
            html += '<span class="chess-captured-label">' + __('Opponent:', 'shortcode-arcade') + '</span>';
            html += '<span class="chess-captured-pieces">' + this.renderCaptured(this.state.captured[mySeat === 0 ? 1 : 0] || []) + '</span>';
            html += '</div>';
            html += '</div>';

            // Status
            html += '<div class="chess-status">';
            if (this.state.game_over) {
                const winner = this.state.winner;
                if (winner !== null) {
                    const winnerName = this.state.players[winner]?.name || (winner === 0 ? 'White' : 'Black');
                    html += '<span class="chess-winner">' + winnerName + ' ' + __('wins!', 'shortcode-arcade') + '</span>';
                }
            } else {
                if (isMyTurn) {
                    html += '<span class="chess-your-turn">' + __('Your turn', 'shortcode-arcade') + '</span>';
                } else {
                    const turnName = this.state.players[this.state.current_turn]?.name || (this.state.current_turn === 0 ? 'White' : 'Black');
                    html += '<span class="chess-waiting">' + __('Waiting for', 'shortcode-arcade') + ' ' + turnName + '</span>';
                }
            }
            html += '</div>';

            html += '</div>';

            $('#sacga-game-board').html(html);

            if (isMyTurn) {
                this.bindBoardEvents();
            }
        },

        getPieceClass: function(piece) {
            if (piece > 0) {
                return 'chess-white-piece';
            } else if (piece < 0) {
                return 'chess-black-piece';
            }
            return '';
        },

        renderCaptured: function(pieces) {
            if (!pieces || pieces.length === 0) {
                return '-';
            }
            return pieces.map(p => PIECE_SYMBOLS[p] || '').join(' ');
        },

        isMyPiece: function(piece) {
            if (piece === 0) return false;
            // White (seat 0) has positive, Black (seat 1) has negative
            return (this.mySeat === 0 && piece > 0) || (this.mySeat === 1 && piece < 0);
        },

        isOpponentPiece: function(piece) {
            if (piece === 0) return false;
            return (this.mySeat === 0 && piece < 0) || (this.mySeat === 1 && piece > 0);
        },

        bindBoardEvents: function() {
            const self = this;

            $('.chess-piece').on('click', function(e) {
                e.stopPropagation();
                const row = parseInt($(this).data('row'));
                const col = parseInt($(this).data('col'));
                const piece = self.state.board[row][col];

                if (self.isMyPiece(piece)) {
                    self.selectPiece(row, col);
                } else if (self.selectedPiece && self.isOpponentPiece(piece)) {
                    // Trying to capture
                    self.tryMove(row, col);
                }
            });

            $('.chess-cell').on('click', function() {
                const row = parseInt($(this).data('row'));
                const col = parseInt($(this).data('col'));
                const piece = self.state.board[row][col];

                if (self.isMyPiece(piece)) {
                    self.selectPiece(row, col);
                } else if (self.selectedPiece) {
                    self.tryMove(row, col);
                }
            });
        },

        selectPiece: function(row, col) {
            $('.chess-cell').removeClass('chess-selected chess-valid-move chess-capture-move');

            this.selectedPiece = { row: row, col: col };

            $('.chess-cell[data-row="' + row + '"][data-col="' + col + '"]').addClass('chess-selected');

            this.validMoves = this.calculateValidMoves(row, col);

            for (const move of this.validMoves) {
                const targetCell = $('.chess-cell[data-row="' + move.to.row + '"][data-col="' + move.to.col + '"]');
                const targetPiece = this.state.board[move.to.row][move.to.col];
                if (targetPiece !== 0) {
                    targetCell.addClass('chess-capture-move');
                } else {
                    targetCell.addClass('chess-valid-move');
                }
            }
        },

        calculateValidMoves: function(row, col) {
            const piece = this.state.board[row][col];
            const pieceType = Math.abs(piece);
            const moves = [];

            // Get all possible moves based on piece type
            const candidates = this.getPieceCandidates(row, col, pieceType);

            for (const [toRow, toCol] of candidates) {
                if (!this.inBounds(toRow, toCol)) continue;

                const dest = this.state.board[toRow][toCol];

                // Can't capture own piece
                if (this.isMyPiece(dest)) continue;

                // Validate piece-specific rules
                if (this.isValidMove(row, col, toRow, toCol, piece, pieceType)) {
                    moves.push({
                        from: { row: row, col: col },
                        to: { row: toRow, col: toCol }
                    });
                }
            }

            return moves;
        },

        getPieceCandidates: function(row, col, pieceType) {
            const candidates = [];

            switch (pieceType) {
                case 1: // Pawn
                    const forward = this.mySeat === 0 ? -1 : 1;
                    const startRow = this.mySeat === 0 ? 6 : 1;
                    candidates.push([row + forward, col]);
                    if (row === startRow) {
                        candidates.push([row + forward * 2, col]);
                    }
                    candidates.push([row + forward, col - 1]);
                    candidates.push([row + forward, col + 1]);
                    break;

                case 2: // Rook
                    for (let i = 0; i < 8; i++) {
                        if (i !== row) candidates.push([i, col]);
                        if (i !== col) candidates.push([row, i]);
                    }
                    break;

                case 3: // Knight
                    const knightMoves = [
                        [-2, -1], [-2, 1], [-1, -2], [-1, 2],
                        [1, -2], [1, 2], [2, -1], [2, 1]
                    ];
                    for (const [dr, dc] of knightMoves) {
                        candidates.push([row + dr, col + dc]);
                    }
                    break;

                case 4: // Bishop
                    for (let i = 1; i < 8; i++) {
                        candidates.push([row + i, col + i]);
                        candidates.push([row + i, col - i]);
                        candidates.push([row - i, col + i]);
                        candidates.push([row - i, col - i]);
                    }
                    break;

                case 5: // Queen
                    for (let i = 0; i < 8; i++) {
                        if (i !== row) candidates.push([i, col]);
                        if (i !== col) candidates.push([row, i]);
                    }
                    for (let i = 1; i < 8; i++) {
                        candidates.push([row + i, col + i]);
                        candidates.push([row + i, col - i]);
                        candidates.push([row - i, col + i]);
                        candidates.push([row - i, col - i]);
                    }
                    break;

                case 6: // King
                    for (let dr = -1; dr <= 1; dr++) {
                        for (let dc = -1; dc <= 1; dc++) {
                            if (dr !== 0 || dc !== 0) {
                                candidates.push([row + dr, col + dc]);
                            }
                        }
                    }
                    break;
            }

            return candidates;
        },

        isValidMove: function(fromRow, fromCol, toRow, toCol, piece, pieceType) {
            const board = this.state.board;
            const dest = board[toRow][toCol];
            const isCapture = dest !== 0;

            switch (pieceType) {
                case 1: // Pawn
                    const forward = this.mySeat === 0 ? -1 : 1;
                    const startRow = this.mySeat === 0 ? 6 : 1;
                    const rowDelta = toRow - fromRow;
                    const colDelta = toCol - fromCol;

                    if (isCapture) {
                        return rowDelta === forward && Math.abs(colDelta) === 1;
                    }
                    if (colDelta !== 0) return false;
                    if (rowDelta === forward) {
                        return board[toRow][toCol] === 0;
                    }
                    if (fromRow === startRow && rowDelta === forward * 2) {
                        return board[fromRow + forward][fromCol] === 0 && board[toRow][toCol] === 0;
                    }
                    return false;

                case 2: // Rook
                    return this.isPathClear(fromRow, fromCol, toRow, toCol);

                case 3: // Knight
                    return true; // Knight can jump, candidates already filtered

                case 4: // Bishop
                    return this.isPathClear(fromRow, fromCol, toRow, toCol);

                case 5: // Queen
                    return this.isPathClear(fromRow, fromCol, toRow, toCol);

                case 6: // King
                    return true; // King moves are simple, candidates already filtered
            }

            return false;
        },

        isPathClear: function(fromRow, fromCol, toRow, toCol) {
            const rowStep = toRow > fromRow ? 1 : (toRow < fromRow ? -1 : 0);
            const colStep = toCol > fromCol ? 1 : (toCol < fromCol ? -1 : 0);

            let row = fromRow + rowStep;
            let col = fromCol + colStep;

            while (row !== toRow || col !== toCol) {
                if (this.state.board[row][col] !== 0) {
                    return false;
                }
                row += rowStep;
                col += colStep;
            }

            return true;
        },

        inBounds: function(row, col) {
            return row >= 0 && row < 8 && col >= 0 && col < 8;
        },

        tryMove: function(toRow, toCol) {
            const move = this.validMoves.find(m => m.to.row === toRow && m.to.col === toCol);

            if (move && this.onMove) {
                this.onMove(move);
            }
        }
    };

    // Register renderer
    window.SACGAGames = window.SACGAGames || {};
    window.SACGAGames.chess = ChessRenderer;

})(jQuery);
