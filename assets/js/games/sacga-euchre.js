/**
 * Euchre Game Renderer
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

    const SUIT_SYMBOLS = { hearts: '&hearts;', diamonds: '&diams;', clubs: '&clubs;', spades: '&spades;' };

    const EuchreRenderer = {
        mySeat: null,
        onMove: null,
        state: null,

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            const phase = this.state.phase;

            if (phase === 'calling_round1' || phase === 'calling_round2') {
                this.renderCallingPhase();
            } else if (phase === 'dealer_discard') {
                this.renderDiscardPhase();
            } else if (phase === 'playing') {
                this.renderPlayingPhase();
            } else if (phase === 'round_end') {
                this.renderRoundEnd();
            }
        },

        renderCallingPhase: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];
            const turnedCard = this.state.turned_card;
            const isRound1 = this.state.phase === 'calling_round1';

            let html = '<div class="euchre-calling">';
            html += `<h3>${isRound1 ? __( 'First Round', 'shortcode-arcade' ) : __( 'Second Round', 'shortcode-arcade' )} - ${__( 'Call Trump', 'shortcode-arcade' )}</h3>`;

            // Show turned card
            html += '<div class="turned-card-area">';
            html += '<p>' + __( 'Turned card:', 'shortcode-arcade' ) + '</p>';
            html += SACGACards.renderCard(turnedCard);
            html += '</div>';

            // My hand
            html += '<div class="my-hand-preview">';
            html += SACGACards.renderHand(myHand, { fanned: true, validCards: [] });
            html += '</div>';

            // Action buttons
            if (isMyTurn) {
                html += '<div class="call-actions">';

                if (isRound1) {
                    html += `<button class="sacga-btn" data-action="order_up">${__( 'Order Up', 'shortcode-arcade' )} (${SUIT_SYMBOLS[turnedCard.suit]})</button>`;
                    html += `<button class="sacga-btn" data-action="order_up_alone">${__( 'Order Up Alone', 'shortcode-arcade' )}</button>`;
                    html += '<button class="sacga-btn pass" data-action="pass">' + __( 'Pass', 'shortcode-arcade' ) + '</button>';
                } else {
                    const turnedSuit = turnedCard.suit;
                    html += '<p>' + __( 'Call a suit:', 'shortcode-arcade' ) + '</p>';
                    html += '<div class="suit-buttons">';
                    for (const suit of ['hearts', 'diamonds', 'clubs', 'spades']) {
                        if (suit !== turnedSuit) {
                            const color = (suit === 'hearts' || suit === 'diamonds') ? 'red' : 'black';
                            html += `<button class="sacga-btn suit-btn ${color}" data-action="call" data-suit="${suit}">${SUIT_SYMBOLS[suit]}</button>`;
                        }
                    }
                    html += '</div>';

                    if (this.mySeat !== this.state.dealer) {
                        html += '<button class="sacga-btn pass" data-action="pass">' + __( 'Pass', 'shortcode-arcade' ) + '</button>';
                    }
                }

                html += '</div>';
            } else {
                html += `<p class="waiting">${__( 'Waiting for', 'shortcode-arcade' )} ${escapeHtml(this.state.players[this.state.current_turn].name)}...</p>`;
            }

            html += '</div>';

            // Scoreboard
            html += this.renderScoreboard();

            $('#sacga-game-board').html(html);

            if (isMyTurn) {
                this.bindCallEvents();
            }
        },

        bindCallEvents: function() {
            const self = this;

            $('[data-action]').on('click', function() {
                const action = $(this).data('action');
                const suit = $(this).data('suit');

                const move = { action };
                if (suit) move.suit = suit;

                if (self.onMove) {
                    self.onMove(move);
                }
            });
        },

        renderDiscardPhase: function() {
            const myHand = this.state.hands[this.mySeat];
            const isMyTurn = this.state.current_turn === this.mySeat;

            let html = '<div class="euchre-discard">';
            html += '<h3>' + __( 'Dealer Discard', 'shortcode-arcade' ) + '</h3>';
            html += `<p>${__( 'Trump is', 'shortcode-arcade' )} ${SUIT_SYMBOLS[this.state.trump]}. ${__( 'Select a card to discard.', 'shortcode-arcade' )}</p>`;

            html += '<div class="my-hand-section">';
            html += SACGACards.renderHand(myHand, {
                fanned: true,
                validCards: isMyTurn ? myHand.map(c => c.id) : []
            });
            html += '</div>';

            html += '</div>';

            $('#sacga-game-board').html(html);

            if (isMyTurn) {
                this.bindDiscardEvents();
            }
        },

        bindDiscardEvents: function() {
            const self = this;
            SACGACards.bindCardClicks('.my-hand-section', (card) => {
                if (self.onMove) {
                    self.onMove({ card_id: card.id });
                }
            });
        },

        renderPlayingPhase: function() {
            const currentTurn = this.state.current_turn;
            const isMyTurn = currentTurn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];

            let html = '<div class="euchre-table sacga-table">';

            // Player positions
            const positions = this.getPositions();
            positions.forEach(pos => {
                // Skip partner if going alone
                if (this.state.going_alone) {
                    const callerPartner = (this.state.caller + 2) % 4;
                    if (pos.seat === callerPartner) return;
                }

                html += `<div class="sacga-seat-${pos.position}">`;
                html += this.renderPlayerArea(pos.seat, pos.position);
                html += '</div>';
            });

            // Center
            html += '<div class="sacga-table-center">';
            html += '<div class="sacga-play-area">';

            if (this.state.trick.length > 0) {
                html += SACGACards.renderTrick(this.state.trick, this.state.players);
            }

            html += '</div>';
            html += SACGACards.renderTrumpIndicator(this.state.trump);

            if (this.state.going_alone) {
                html += `<div class="going-alone-indicator">${escapeHtml(this.state.players[this.state.caller].name)} ${__( 'going alone!', 'shortcode-arcade' )}</div>`;
            }

            html += '</div>';
            html += '</div>';

            // My hand
            html += '<div class="my-hand-section">';
            const validMoves = isMyTurn ? this.getValidCardIds() : [];
            html += SACGACards.renderHand(myHand, {
                fanned: true,
                validCards: validMoves
            });
            html += '</div>';

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
            const tricks = this.state.tricks_won[seat];
            const isCaller = seat === this.state.caller;

            let html = SACGACards.renderPlayerInfo(player, {
                isActive: isActive,
                showScore: false,
                extras: `<div class="tricks">${__( 'Tricks:', 'shortcode-arcade' )} ${tricks}${isCaller ? ' (' + __( 'Maker', 'shortcode-arcade' ) + ')' : ''}</div>`
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

            if (trick.length === 0) {
                return myHand.map(c => c.id);
            }

            const trump = this.state.trump;
            const leadCard = trick[0].card;
            const leadSuit = this.getEffectiveSuit(leadCard, trump);

            const suitCards = myHand.filter(c => this.getEffectiveSuit(c, trump) === leadSuit);

            if (suitCards.length > 0) {
                return suitCards.map(c => c.id);
            }

            return myHand.map(c => c.id);
        },

        getEffectiveSuit: function(card, trump) {
            if (!trump) return card.suit;

            if (card.rank === 'J') {
                const sameColor = {
                    hearts: 'diamonds', diamonds: 'hearts',
                    clubs: 'spades', spades: 'clubs'
                };
                if (card.suit === sameColor[trump]) {
                    return trump;
                }
            }

            return card.suit;
        },

        bindPlayEvents: function() {
            const self = this;
            SACGACards.bindCardClicks('.my-hand-section', (card) => {
                if (self.onMove) {
                    self.onMove({ card_id: card.id });
                }
            });
        },

        renderScoreboard: function() {
            const myTeam = this.mySeat % 2;
            const teams = this.state.teams;
            const scores = this.state.team_scores;

            let html = '<div class="euchre-scoreboard sacga-scoreboard">';
            html += '<table>';
            html += '<tr><th>' + __( 'Team', 'shortcode-arcade' ) + '</th><th>' + __( 'Score', 'shortcode-arcade' ) + '</th></tr>';

            for (let t = 0; t < 2; t++) {
                const teamPlayers = teams[t].map(s => this.state.players[s].name).join(' & ');
                const isMyTeam = t === myTeam;
                html += `<tr class="${isMyTeam ? 'my-team' : ''}">
                    <td>${escapeHtml(teamPlayers)}</td>
                    <td>${scores[t]} / 10</td>
                </tr>`;
            }

            html += '</table></div>';
            return html;
        },

        renderRoundEnd: function() {
            let html = '<div class="euchre-round-end">';
            html += '<h2>' + __( 'Round Complete!', 'shortcode-arcade' ) + '</h2>';
            html += this.renderScoreboard();
            html += '<button id="euchre-next-round" class="sacga-btn sacga-btn-primary">' + __( 'Next Round', 'shortcode-arcade' ) + '</button>';
            html += '</div>';

            $('#sacga-game-board').html(html);

            // Bind next round button
            $('#euchre-next-round').on('click', () => {
                if (this.onMove) {
                    this.onMove({ action: 'next_round' });
                }
            });
        }
    };

    window.SACGAGames = window.SACGAGames || {};
    window.SACGAGames.euchre = EuchreRenderer;

})(jQuery);
