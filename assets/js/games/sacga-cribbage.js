/**
 * Cribbage Game Renderer
 */
(function($) {
    'use strict';

    const { __, _n, _x } = wp.i18n;

    const escapeHtml = (text) => {
        if (window.SACGA && typeof window.SACGA.escapeHtml === 'function') {
            return window.SACGA.escapeHtml(text);
        }
        if (text === null || text === undefined) {
            return '';
        }
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const CribbageRenderer = {
        mySeat: null,
        onMove: null,
        state: null,
        selectedCards: [],
        eventsBound: false,

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;

            const prevPhase = this.state ? this.state.phase : null;
            this.state = stateData.state;

            const phase = this.state.phase;

            this.bindGlobalEvents();

            // Fix: Reset selectedCards when entering discard phase
            if (phase === 'discard' && prevPhase !== 'discard') {
                this.selectedCards = [];
            }

            if (phase === 'discard') {
                this.renderDiscardPhase();
            } else if (phase === 'pegging') {
                this.renderPeggingPhase();
            } else if (phase === 'round_end') {
                this.renderRoundEnd();
            }
        },

        renderDiscardPhase: function() {
            const myHand = this.state.hands[this.mySeat] || [];
            const myDiscards = this.state.discards[this.mySeat] || [];
            const hasDiscarded = myDiscards.length === 2;
            const isDealer = this.state.dealer === this.mySeat;

            let html = '<div class="cribbage-game"><div class="cribbage-game-container">';

            // Pegboard on the left
            html += '<div class="cribbage-pegboard-container">';
            html += this.renderScoreboard();
            html += '</div>';

            // Game area on the right
            html += '<div class="cribbage-game-area">';
            html += '<div class="cribbage-discard">';
            html += '<h3>' + __( 'Discard to Crib', 'shortcode-arcade' ) + '</h3>';
            html += `<p>${__( 'Select 2 cards to put in', 'shortcode-arcade' )} ${isDealer ? __( 'your', 'shortcode-arcade' ) : __( "opponent's", 'shortcode-arcade' )} ${__( 'crib.', 'shortcode-arcade' )}</p>`;

            if (hasDiscarded) {
                html += '<p class="waiting">' + __( 'Waiting for opponent to discard...', 'shortcode-arcade' ) + '</p>';
                html += SACGACards.renderHand(myHand, { validCards: [] });
            } else {
                html += SACGACards.renderHand(myHand, {
                    selectedCards: this.selectedCards,
                    validCards: myHand.map(c => c.id)
                });

                html += `<button id="crib-confirm" class="sacga-btn sacga-btn-primary"
                         ${this.selectedCards.length !== 2 ? 'disabled' : ''}>
                    ${__( 'Discard', 'shortcode-arcade' )} (${this.selectedCards.length}/2)
                </button>`;
            }

            html += '</div>';
            html += '</div>';
            html += '</div></div>';

            $('#sacga-game-board').html(html);
        },

        bindGlobalEvents: function() {
            if (this.eventsBound) {
                return;
            }

            const self = this;
            const board = $('#sacga-game-board');
            this.eventsBound = true;

            board.on('click.cribbage', '.cribbage-game .sacga-card:not(.disabled):not(.sacga-card-back)', function() {
                if (!self.state) {
                    return;
                }

                const $card = $(this);
                const card = {
                    id: $card.data('card-id'),
                    suit: $card.data('suit'),
                    rank: $card.data('rank')
                };

                if (self.state.phase === 'discard') {
                    const myDiscards = self.state.discards[self.mySeat] || [];
                    if (myDiscards.length === 2) {
                        return;
                    }

                    const idx = self.selectedCards.indexOf(card.id);
                    if (idx >= 0) {
                        self.selectedCards.splice(idx, 1);
                    } else if (self.selectedCards.length < 2) {
                        self.selectedCards.push(card.id);
                    }
                    self.renderDiscardPhase();
                    return;
                }

                if (self.state.phase === 'pegging' && self.state.current_turn === self.mySeat) {
                    const playable = self.getPlayableCardIds();
                    if (playable.includes(card.id) && self.onMove) {
                        self.onMove({ card_id: card.id });
                    }
                }
            });

            board.on('click.cribbage', '#crib-confirm', function() {
                if (!self.state || self.state.phase !== 'discard') {
                    return;
                }

                const myDiscards = self.state.discards[self.mySeat] || [];
                if (myDiscards.length === 2) {
                    return;
                }

                if (self.selectedCards.length === 2 && self.onMove) {
                    self.onMove({ cards: self.selectedCards });
                    self.selectedCards = [];
                }
            });

            board.on('click.cribbage', '#say-go', function() {
                if (!self.state || self.state.phase !== 'pegging') {
                    return;
                }

                if (self.state.current_turn === self.mySeat && self.onMove) {
                    self.onMove({ action: 'go' });
                }
            });

            board.on('click.cribbage', '#cribbage-next-round', function() {
                if (self.onMove) {
                    self.onMove({ action: 'next_round' });
                }
            });
        },

        renderPeggingPhase: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;
            const myHand = this.state.peg_hands[this.mySeat] || [];
            const oppHand = this.state.peg_hands[(this.mySeat + 1) % 2] || [];
            const oppHandCount = Array.isArray(oppHand) ? oppHand.length : oppHand;
            const starter = this.state.starter;

            let html = '<div class="cribbage-game"><div class="cribbage-game-container">';

            // Pegboard on the left
            html += '<div class="cribbage-pegboard-container">';
            html += this.renderScoreboard();
            html += '</div>';

            // Game area on the right
            html += '<div class="cribbage-game-area">';
            html += '<div class="cribbage-pegging">';

            // Starter card
            if (starter) {
                html += '<div class="starter-area">';
                html += '<p>' + __( 'Starter:', 'shortcode-arcade' ) + '</p>';
                html += SACGACards.renderCard(starter);
                html += '</div>';
            }

            // Peg count
            html += `<div class="peg-count">${__( 'Count:', 'shortcode-arcade' )} <strong>${this.state.peg_count}</strong> / 31</div>`;

            // Peg pile
            html += '<div class="peg-pile">';
            if (this.state.peg_pile.length > 0) {
                this.state.peg_pile.forEach(play => {
                    html += SACGACards.renderCard(play.card);
                });
            } else {
                html += '<span class="empty-pile">' + __( 'Play cards here', 'shortcode-arcade' ) + '</span>';
            }
            html += '</div>';

            // Opponent's hand
            html += '<div class="opponent-area">';
            html += SACGACards.renderPlayerInfo(this.state.players[(this.mySeat + 1) % 2], { showScore: false });
            html += SACGACards.renderOpponentHand(oppHandCount);
            html += '</div>';

            // My hand
            html += '<div class="my-hand-section">';

            if (isMyTurn) {
                const playable = this.getPlayableCardIds();

                if (playable.length === 0) {
                    html += '<p>' + __( 'No playable cards!', 'shortcode-arcade' ) + '</p>';
                    html += SACGACards.renderHand(myHand, { validCards: [] });
                    html += '<button id="say-go" class="sacga-btn">' + __( 'Say "Go"', 'shortcode-arcade' ) + '</button>';
                } else {
                    html += SACGACards.renderHand(myHand, { validCards: playable });
                }
            } else {
                html += SACGACards.renderHand(myHand, { validCards: [] });
                html += '<p class="waiting">' + __( "Opponent's turn...", 'shortcode-arcade' ) + '</p>';
            }

            html += '</div>';
            html += '</div>';
            html += '</div></div>';

            $('#sacga-game-board').html(html);
        },

        getPlayableCardIds: function() {
            const myHand = this.state.peg_hands[this.mySeat];
            const pegCount = this.state.peg_count;
            const pegValues = { 'A': 1, '2': 2, '3': 3, '4': 4, '5': 5, '6': 6, '7': 7, '8': 8, '9': 9, '10': 10, 'J': 10, 'Q': 10, 'K': 10 };

            return myHand
                .filter(c => pegCount + pegValues[c.rank] <= 31)
                .map(c => c.id);
        },

        renderScoreboard: function() {
            const scores = this.state.scores;

            let html = '<div class="cribbage-scoreboard">';

            // Player names and scores
            for (let seat = 0; seat < 2; seat++) {
                const player = this.state.players[seat];
                const score = scores[seat];
                const isMe = seat === this.mySeat;
                const isDealer = seat === this.state.dealer;

                html += `<div class="player-score seat-${seat} ${isMe ? 'my-score' : ''}">
                    <span class="name">${escapeHtml(player.name)}${isDealer ? ' (D)' : ''}</span>
                    <span class="score">${score} / 121</span>
                </div>`;
            }

            // SVG Pegboard
            html += this.renderPegboard(scores);

            html += '</div>';
            return html;
        },

        renderPegboard: function(scores) {
            // Calculate position on the serpentine path (0-121)
            const getPosition = (score) => {
                if (score < 0 || score > 121) return null;
                if (score === 121) return { left: 50, top: 98 };

                const track = Math.floor(score / 30);
                const pos = score % 30;
                const left = (track * 25) + 12.5;
                const top = (track % 2 === 0)
                    ? 5 + (pos * 3.2)
                    : 5 + ((29 - pos) * 3.2);

                return { left: left, top: top };
            };

            // Get previous scores for leapfrog visualization
            const prevScores = this.state.prev_scores || [0, 0];

            let html = '<div class="cribbage-board">';

            // Header
            html += '<div class="board-header">' + __( 'START / FINISH', 'shortcode-arcade' ) + '</div>';

            // Player lane divider
            html += '<div class="board-divider"></div>';

            // Player lane labels
            html += `<div class="player-lane-label lane-left">${escapeHtml(this.state.players[0].name)}</div>`;
            html += `<div class="player-lane-label lane-right">${escapeHtml(this.state.players[1].name)}</div>`;

            // Draw holes for both player lanes
            for (let i = 0; i <= 121; i++) {
                const pos = getPosition(i);
                if (!pos) continue;

                const isMarker = i % 5 === 0 && i > 0;
                const isSkunk = i === 90;
                const isFinish = i === 121;

                // Left lane (Player 0)
                const leftPos = {...pos, left: pos.left - 12.5};
                html += `<div class="board-hole lane-0" style="left: ${leftPos.left}%; top: ${leftPos.top}%;">`;
                if (isMarker) html += '<div class="hole-marker"></div>';
                if (isSkunk) html += '<div class="hole-label">90</div>';
                if (isFinish) html += '<div class="hole-label">121</div>';
                html += '</div>';

                // Right lane (Player 1)
                const rightPos = {...pos, left: pos.left + 12.5};
                html += `<div class="board-hole lane-1" style="left: ${rightPos.left}%; top: ${rightPos.top}%;">`;
                if (isMarker) html += '<div class="hole-marker"></div>';
                if (isSkunk) html += '<div class="hole-label">90</div>';
                if (isFinish) html += '<div class="hole-label">121</div>';
                html += '</div>';
            }

            // Draw two pegs per player (leapfrog system)
            for (let seat = 0; seat < 2; seat++) {
                const currentScore = scores[seat];
                const previousScore = prevScores[seat] || 0;
                const player = this.state.players[seat];
                const laneShift = seat === 0 ? -12.5 : 12.5;

                // Back peg (previous score) - only show if different from current
                if (previousScore > 0 && previousScore !== currentScore) {
                    const backPos = getPosition(previousScore);
                    if (backPos) {
                        html += `<div class="board-peg peg-back peg-${seat}" style="left: ${backPos.left + laneShift}%; top: ${backPos.top}%;" title="${escapeHtml(player.name)}: ${__( 'Previous', 'shortcode-arcade' )} (${previousScore})"></div>`;
                    }
                }

                // Front peg (current score)
                const frontPos = getPosition(currentScore);
                if (frontPos) {
                    html += `<div class="board-peg peg-front peg-${seat}" style="left: ${frontPos.left + laneShift}%; top: ${frontPos.top}%;" title="${escapeHtml(player.name)}: ${currentScore}">`;
                    html += `<div class="peg-score">${currentScore}</div>`;
                    html += '</div>';
                }
            }

            html += '</div>';
            return html;
        },

        renderRoundEnd: function() {
            let html = '<div class="cribbage-game"><div class="cribbage-round-end">';
            html += '<h2>' + __( 'Round Complete!', 'shortcode-arcade' ) + '</h2>';
            html += this.renderScoreboard();
            html += '<button id="cribbage-next-round" class="sacga-btn sacga-btn-primary">' + __( 'Next Round', 'shortcode-arcade' ) + '</button>';
            html += '</div></div>';

            $('#sacga-game-board').html(html);
        }
    };

    window.SACGAGames = window.SACGAGames || {};
    window.SACGAGames.cribbage = CribbageRenderer;

})(jQuery);
