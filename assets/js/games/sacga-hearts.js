/**
 * Hearts Game Renderer
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

    const HeartsRenderer = {
        mySeat: null,
        onMove: null,
        state: null,
        selectedCards: [],

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            if (this.state.phase === 'passing') {
                this.renderPassingPhase();
            } else if (this.state.phase === 'playing') {
                this.renderPlayingPhase();
            } else if (this.state.phase === 'round_end') {
                this.renderRoundEnd();
            }
        },

        renderPassingPhase: function() {
            const myHand = this.state.hands[this.mySeat];
            const direction = this.state.pass_direction;
            const myPassed = this.state.passed_cards[this.mySeat];
            const hasPassed = myPassed && myPassed.length === 3;

            let html = '<div class="hearts-game sacga-game-hearts">';
            html += '<div class="hearts-header">';
            html += `<div class="hearts-title">${__( 'Hearts', 'shortcode-arcade' )}</div>`;
            html += `<div class="hearts-subtitle">${__( 'Classic trick-taking at the felt table.', 'shortcode-arcade' )}</div>`;
            html += '</div>';
            html += '<div class="hearts-status">';
            html += `<div class="hearts-phase-title">${__( 'Passing Phase', 'shortcode-arcade' )}</div>`;
            html += `<p class="pass-direction">${__( 'Pass 3 cards', 'shortcode-arcade' )} <strong>${direction}</strong></p>`;
            html += '</div>';

            // Show passing status for all players
            html += '<div class="hearts-table-area">';
            html += '<div class="pass-status">';
            for (let seat = 0; seat < 4; seat++) {
                const player = this.state.players[seat];
                const passed = this.state.passed_cards[seat];
                const isMe = seat === this.mySeat;
                let status = __( 'Waiting...', 'shortcode-arcade' );

                if (passed === 'waiting' || (passed && passed.length === 3)) {
                    status = __( 'Ready', 'shortcode-arcade' );
                }

                html += `<div class="pass-entry ${isMe ? 'is-me' : ''}">
                    <span class="player-name">${escapeHtml(player.name)}${isMe ? ' ' + __( '(You)', 'shortcode-arcade' ) : ''}</span>
                    <span class="pass-status-text">${status}</span>
                </div>`;
            }
            html += '</div>';
            html += '</div>';

            // My hand
            html += '<div class="my-hand-section hearts-hand-area">';
            if (!hasPassed) {
                html += `<p>${__( 'Select 3 cards to pass', 'shortcode-arcade' )} (${this.selectedCards.length}/3 ${__( 'selected', 'shortcode-arcade' )})</p>`;
                html += SACGACards.renderHand(myHand, {
                    fanned: true,
                    selectedCards: this.selectedCards,
                    validCards: myHand.map(c => c.id)
                });
                html += '<button id="hearts-confirm-pass" class="sacga-btn sacga-btn-primary" ' +
                    (this.selectedCards.length === 3 ? '' : 'disabled') + '>' + __( 'Confirm Pass', 'shortcode-arcade' ) + '</button>';
            } else {
                html += '<p class="waiting">' + __( 'Waiting for other players...', 'shortcode-arcade' ) + '</p>';
                html += SACGACards.renderHand(myHand, {
                    fanned: true,
                    selectedCards: [],
                    validCards: []
                });
            }
            html += '</div>';

            html += this.renderScoreboard();
            html += '</div>';

            $('#sacga-game-board').html(html);

            if (!hasPassed) {
                this.bindPassingEvents();
            }
        },

        bindPassingEvents: function() {
            const self = this;

            SACGACards.bindCardClicks('.my-hand-section', (card) => {
                const idx = self.selectedCards.indexOf(card.id);
                if (idx > -1) {
                    self.selectedCards.splice(idx, 1);
                } else if (self.selectedCards.length < 3) {
                    self.selectedCards.push(card.id);
                }
                self.renderPassingPhase();
            });

            $('#hearts-confirm-pass').on('click', function() {
                if (self.selectedCards.length === 3 && self.onMove) {
                    self.onMove({ cards: self.selectedCards });
                    self.selectedCards = [];
                }
            });
        },

        renderPlayingPhase: function() {
            const currentTurn = this.state.current_turn;
            const isMyTurn = currentTurn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];

            const heartInTrick = this.state.trick.some(play => play.card.suit === 'hearts');
            let html = '<div class="hearts-table sacga-table sacga-game-hearts' + (isMyTurn ? ' is-my-turn' : '') + (this.state.hearts_broken ? ' hearts-broken-active' : '') + '">';

            // Render player positions
            const positions = this.getPositions();
            positions.forEach(pos => {
                html += `<div class="sacga-seat-${pos.position}">`;
                html += this.renderPlayerArea(pos.seat, pos.position);
                html += '</div>';
            });

            // Center - trick area
            html += '<div class="sacga-table-center">';
            html += `<div class="sacga-play-area ${heartInTrick ? 'hearts-heart-played' : ''}">`;

            if (this.state.trick.length > 0) {
                html += SACGACards.renderTrick(this.state.trick, this.state.players);
            } else {
                html += '<div class="trick-empty">' + (this.state.hearts_broken ? __( 'Hearts Broken', 'shortcode-arcade' ) : __( 'Lead a card', 'shortcode-arcade' )) + '</div>';
            }

            html += '</div>';
            html += '</div>';
            html += '</div>';

            // My hand
            html += '<div class="my-hand-section">';
            const validMoves = isMyTurn ? this.getValidCardIds() : [];
            html += SACGACards.renderHand(myHand, {
                fanned: true,
                selectedCards: [],
                validCards: validMoves
            });
            html += '</div>';

            // Scoreboard
            html += this.renderScoreboard();

            $('#sacga-game-board').html(html);

            if (isMyTurn) {
                this.bindPlayEvents();
            }
        },

        getPositions: function() {
            const positions = ['bottom', 'left', 'top', 'right'];
            const result = [];
            for (let i = 0; i < 4; i++) {
                result.push({
                    seat: (this.mySeat + i) % 4,
                    position: positions[i]
                });
            }
            return result;
        },

        renderPlayerArea: function(seat, position) {
            const player = this.state.players[seat];
            const isActive = this.state.current_turn === seat;
            const isMe = seat === this.mySeat;
            const cardCount = isMe ? 0 : this.state.hands[seat];
            const score = this.state.scores[seat];
            const trickCount = this.state.tricks_won[seat].length;

            let html = SACGACards.renderPlayerInfo(player, {
                isActive: isActive,
                showScore: true,
                score: score,
                extras: `<div class="trick-count">${__( 'Tricks:', 'shortcode-arcade' )} ${trickCount}</div>`
            });

            if (!isMe && cardCount > 0) {
                const vertical = position === 'left' || position === 'right';
                html += SACGACards.renderOpponentHand(cardCount, { vertical });
            }

            return html;
        },

        getValidCardIds: function() {
            const myHand = this.state.hands[this.mySeat];
            const trick = this.state.trick;
            const isLeading = trick.length === 0;

            // Check if this is the first trick (no tricks won yet)
            let isFirstTrick = true;
            for (let seat = 0; seat < 4; seat++) {
                if (this.state.tricks_won[seat].length > 0) {
                    isFirstTrick = false;
                    break;
                }
            }

            if (isLeading) {
                // First trick must lead with 2 of clubs
                if (isFirstTrick) {
                    const twoClubs = myHand.find(c => c.id === 'clubs_2');
                    return twoClubs ? ['clubs_2'] : [];
                }

                // Can't lead hearts unless broken or only have hearts
                if (this.state.hearts_broken) {
                    return myHand.map(c => c.id);
                }

                const nonHearts = myHand.filter(c => c.suit !== 'hearts');
                if (nonHearts.length > 0) {
                    return nonHearts.map(c => c.id);
                }

                return myHand.map(c => c.id);
            }

            // Following suit
            const leadSuit = trick[0].card.suit;
            const suitCards = myHand.filter(c => c.suit === leadSuit);

            if (suitCards.length > 0) {
                return suitCards.map(c => c.id);
            }

            // Can't play hearts or Queen of Spades on first trick
            if (isFirstTrick) {
                const validCards = myHand.filter(c =>
                    c.suit !== 'hearts' && c.id !== 'spades_Q'
                );
                if (validCards.length > 0) {
                    return validCards.map(c => c.id);
                }
            }

            return myHand.map(c => c.id);
        },

        bindPlayEvents: function() {
            const self = this;
            SACGACards.bindCardClicks('.my-hand-section', (card) => {
                if (self.onMove) {
                    self.onMove({ card_id: card.id });
                }
            });
            $('.my-hand-section').off('click.heartsInvalid').on('click.heartsInvalid', '.sacga-card.disabled', function() {
                const $hand = $(this).closest('.my-hand-section');
                $hand.addClass('hearts-invalid-play');
                setTimeout(() => {
                    $hand.removeClass('hearts-invalid-play');
                }, 350);
            });
        },

        renderScoreboard: function() {
            const labelPlayer = __( 'Player', 'shortcode-arcade' );
            const labelTricks = __( 'Tricks', 'shortcode-arcade' );
            const labelRound = __( 'Round', 'shortcode-arcade' );
            const labelTotal = __( 'Total', 'shortcode-arcade' );
            let html = '<div class="hearts-scoreboard sacga-scoreboard">';
            html += '<table>';
            html += `<tr><th>${labelPlayer}</th><th>${labelTricks}</th><th>${labelRound}</th><th>${labelTotal}</th></tr>`;

            for (let seat = 0; seat < 4; seat++) {
                const player = this.state.players[seat];
                const isMe = seat === this.mySeat;
                const isCurrent = seat === this.state.current_turn;
                const roundScore = this.state.round_scores[seat];
                const totalScore = this.state.scores[seat];
                const trickCount = this.state.tricks_won[seat].length;

                html += `<tr class="${isMe ? 'is-me' : ''} ${isCurrent ? 'is-current' : ''}">
                    <td data-label="${labelPlayer}">${escapeHtml(player.name)}${isMe ? ' ' + __( '(You)', 'shortcode-arcade' ) : ''}</td>
                    <td data-label="${labelTricks}">${trickCount}</td>
                    <td data-label="${labelRound}">${roundScore}</td>
                    <td data-label="${labelTotal}">${totalScore}</td>
                </tr>`;
            }

            html += '</table></div>';
            return html;
        },

        renderRoundEnd: function() {
            let html = '<div class="hearts-game sacga-game-hearts">';
            html += '<div class="hearts-round-end">';
            html += '<h2>' + __( 'Round Complete!', 'shortcode-arcade' ) + '</h2>';

            // Check for shoot the moon
            let shooter = null;
            for (let seat = 0; seat < 4; seat++) {
                if (this.state.round_scores[seat] === 0 &&
                    this.state.round_scores.filter(s => s === 26).length === 3) {
                    shooter = seat;
                    break;
                }
            }

            if (shooter !== null) {
                const shooterName = escapeHtml(this.state.players[shooter].name);
                html += `<div class="shoot-moon-banner">${shooterName} ${__( 'shot the moon!', 'shortcode-arcade' )}</div>`;
            }

            html += this.renderScoreboard();

            // Check if game is over
            let gameOver = false;
            for (let seat = 0; seat < 4; seat++) {
                if (this.state.scores[seat] >= 100) {
                    gameOver = true;
                    break;
                }
            }

            if (gameOver) {
                const minScore = Math.min(...this.state.scores);
                const winners = [];
                for (let seat = 0; seat < 4; seat++) {
                    if (this.state.scores[seat] === minScore) {
                        winners.push(escapeHtml(this.state.players[seat].name));
                    }
                }
                html += `<div class="game-winner">${winners.join(' ' + __( 'and', 'shortcode-arcade' ) + ' ')} ${__( 'wins!', 'shortcode-arcade' )}</div>`;
            } else {
                html += '<button id="hearts-next-round" class="sacga-btn sacga-btn-primary">' + __( 'Next Round', 'shortcode-arcade' ) + '</button>';
            }

            html += '</div>';
            html += '</div>';

            $('#sacga-game-board').html(html);

            if (!gameOver) {
                $('#hearts-next-round').on('click', () => {
                    if (this.onMove) {
                        this.onMove({ action: 'next_round' });
                    }
                });
            }
        },

        // Game-specific AI card play animation
        animateAICardPlay: function(seat, card, onComplete) {
            try {
                // Find the card that was just played in the trick area
                const trickCards = $('.sacga-play-area .sacga-card');
                const lastCard = trickCards.last();

                if (lastCard.length) {
                    // Add animation classes
                    lastCard.addClass('sacga-card-animating sacga-card-just-played');

                    // Play a subtle scale and highlight effect
                    setTimeout(() => {
                        try {
                            lastCard.removeClass('sacga-card-animating');

                            // Keep the highlight briefly
                            setTimeout(() => {
                                try {
                                    lastCard.removeClass('sacga-card-just-played');
                                } finally {
                                    if (onComplete) onComplete();
                                }
                            }, 400);
                        } catch (error) {
                            if (onComplete) onComplete();
                        }
                    }, 600);
                } else {
                    // Card not found, just complete
                    if (onComplete) onComplete();
                }
            } catch (error) {
                // Always call onComplete to prevent hanging
                if (onComplete) onComplete();
            }
        },

        // Animate trick collection (when trick is won and cards are cleared)
        animateTrickCollection: function(winnerSeat, onComplete) {
            const $playArea = $('.sacga-play-area');
            $playArea.addClass('sacga-trick-collecting');

            setTimeout(() => {
                $playArea.removeClass('sacga-trick-collecting');
                if (onComplete) onComplete();
            }, 500);
        }
    };

    window.SACGAGames = window.SACGAGames || {};
    window.SACGAGames.hearts = HeartsRenderer;

})(jQuery);
