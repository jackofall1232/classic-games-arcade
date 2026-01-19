/**
 * Checkers Game Renderer
 */
(function($) {
    'use strict';

    const { __, _n, _x } = wp.i18n;

    // Piece symbols using Unicode
    const PIECE_SYMBOLS = {
        0: '',
        1: '\u26AB',  // Black circle
        2: '\u26AA',  // White circle
        3: '\u265A',  // Black King
        4: '\u2654'   // White King
    };

    const CheckersRenderer = {
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
            const isMyTurn = this.state.current_turn === mySeat;

            let html = '<div class="checkers-board">';

            for (let row = 0; row < 8; row++) {
                html += '<div class="checkers-row">';

                for (let col = 0; col < 8; col++) {
                    const isLight = (row + col) % 2 === 0;
                    const piece = board[row][col];
                    const cellClass = isLight ? 'checkers-cell-light' : 'checkers-cell-dark';
                    const pieceClass = this.getPieceClass(piece);

                    html += '<div class="checkers-cell ' + cellClass + '" data-row="' + row + '" data-col="' + col + '">';

                    if (piece > 0) {
                        html += '<span class="checkers-piece ' + pieceClass + '" data-row="' + row + '" data-col="' + col + '">' + PIECE_SYMBOLS[piece] + '</span>';
                    }

                    html += '</div>';
                }

                html += '</div>';
            }

            html += '</div>';

            // Game info
            html += '<div class="checkers-info">';
            html += '<div class="checkers-captured">';
            html += '<span class="capture-count">' + __( 'You captured:', 'shortcode-arcade' ) + ' ' + (this.state.captured[mySeat] || 0) + '</span>';
            html += '<span class="capture-count">' + __( 'Opponent:', 'shortcode-arcade' ) + ' ' + (this.state.captured[mySeat === 0 ? 1 : 0] || 0) + '</span>';
            html += '</div>';

            if (this.state.must_jump && this.state.jump_piece) {
                html += '<div class="checkers-notice">' + __( 'Continue your jump!', 'shortcode-arcade' ) + '</div>';
            }
            html += '</div>';

            $('#sacga-game-board').html(html);

            if (isMyTurn) {
                this.bindBoardEvents();
            }
        },

        getPieceClass: function(piece) {
            switch (piece) {
                case 1: return 'checkers-black';
                case 2: return 'checkers-white';
                case 3: return 'checkers-black checkers-king';
                case 4: return 'checkers-white checkers-king';
                default: return '';
            }
        },

        isMyPiece: function(piece) {
            if (this.mySeat === 0) {
                return piece === 1 || piece === 3;
            }
            return piece === 2 || piece === 4;
        },

        isOpponentPiece: function(piece) {
            if (this.mySeat === 0) {
                return piece === 2 || piece === 4;
            }
            return piece === 1 || piece === 3;
        },

        isKing: function(piece) {
            return piece === 3 || piece === 4;
        },

        bindBoardEvents: function() {
            const self = this;

            $('.checkers-piece').on('click', function(e) {
                e.stopPropagation();
                const row = parseInt($(this).data('row'));
                const col = parseInt($(this).data('col'));
                const piece = self.state.board[row][col];

                if (self.isMyPiece(piece)) {
                    self.selectPiece(row, col);
                }
            });

            $('.checkers-cell').on('click', function() {
                if (!self.selectedPiece) return;

                const row = parseInt($(this).data('row'));
                const col = parseInt($(this).data('col'));

                self.tryMove(row, col);
            });
        },

        selectPiece: function(row, col) {
            $('.checkers-cell').removeClass('checkers-selected checkers-valid-move');

            // If must continue jumping, can only select that piece
            if (this.state.must_jump && this.state.jump_piece) {
                if (this.state.jump_piece.row !== row || this.state.jump_piece.col !== col) {
                    return;
                }
            }

            this.selectedPiece = { row: row, col: col };

            $('.checkers-cell[data-row="' + row + '"][data-col="' + col + '"]').addClass('checkers-selected');

            this.validMoves = this.calculateValidMoves(row, col);

            for (const move of this.validMoves) {
                $('.checkers-cell[data-row="' + move.to.row + '"][data-col="' + move.to.col + '"]').addClass('checkers-valid-move');
            }
        },

        calculateValidMoves: function(row, col) {
            const piece = this.state.board[row][col];
            const isKing = this.isKing(piece);
            const moves = [];
            const jumps = [];

            const forward = this.mySeat === 0 ? 1 : -1;
            const directions = isKing
                ? [[-1, -1], [-1, 1], [1, -1], [1, 1]]
                : [[forward, -1], [forward, 1]];

            // Check for jumps
            for (const [dr, dc] of directions) {
                const midRow = row + dr;
                const midCol = col + dc;
                const toRow = row + dr * 2;
                const toCol = col + dc * 2;

                if (this.inBounds(toRow, toCol)) {
                    const midPiece = this.state.board[midRow][midCol];
                    const destPiece = this.state.board[toRow][toCol];

                    if (this.isOpponentPiece(midPiece) && destPiece === 0) {
                        jumps.push({
                            from: { row: row, col: col },
                            to: { row: toRow, col: toCol },
                            is_capture: true
                        });
                    }
                }
            }

            // If jumps available, must jump (or if in must_jump state)
            if (jumps.length > 0 || this.state.must_jump) {
                return jumps;
            }

            // Check for regular moves
            for (const [dr, dc] of directions) {
                const toRow = row + dr;
                const toCol = col + dc;

                if (this.inBounds(toRow, toCol) && this.state.board[toRow][toCol] === 0) {
                    moves.push({
                        from: { row: row, col: col },
                        to: { row: toRow, col: toCol },
                        is_capture: false
                    });
                }
            }

            // Check if there are any jumps on the board (mandatory capture)
            if (this.hasAnyJumps()) {
                return []; // Can't make regular moves if jumps available elsewhere
            }

            return moves;
        },

        hasAnyJumps: function() {
            const board = this.state.board;

            for (let row = 0; row < 8; row++) {
                for (let col = 0; col < 8; col++) {
                    const piece = board[row][col];
                    if (!this.isMyPiece(piece)) continue;

                    const isKing = this.isKing(piece);
                    const forward = this.mySeat === 0 ? 1 : -1;
                    const directions = isKing
                        ? [[-1, -1], [-1, 1], [1, -1], [1, 1]]
                        : [[forward, -1], [forward, 1]];

                    for (const [dr, dc] of directions) {
                        const midRow = row + dr;
                        const midCol = col + dc;
                        const toRow = row + dr * 2;
                        const toCol = col + dc * 2;

                        if (this.inBounds(toRow, toCol)) {
                            const midPiece = board[midRow][midCol];
                            const destPiece = board[toRow][toCol];

                            if (this.isOpponentPiece(midPiece) && destPiece === 0) {
                                return true;
                            }
                        }
                    }
                }
            }

            return false;
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
    window.SACGAGames.checkers = CheckersRenderer;

})(jQuery);
