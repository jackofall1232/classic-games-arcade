/**
 * Spades Game Renderer
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

    const SpadesRenderer = {
        mySeat: null,
        onMove: null,
        state: null,

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            if (this.state.phase === 'bidding') {
                this.renderBiddingPhase();
            } else if (this.state.phase === 'playing') {
                this.renderPlayingPhase();
            } else if (this.state.phase === 'round_end') {
                this.renderRoundEnd();
            }
        },

        renderBiddingPhase: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];
            const myTeam = this.mySeat % 2;
            const partnerSeat = (this.mySeat + 2) % 4;

            let html = '<div class="spades-bidding">';
            html += '<h3>' + __( 'Bidding Phase', 'shortcode-arcade' ) + '</h3>';

            // Show bids
            html += '<div class="bid-status">';
            for (let seat = 0; seat < 4; seat++) {
                const player = this.state.players[seat];
                const bid = this.state.bids[seat];
                const isPartner = seat === partnerSeat;
                const bidText = bid === null ? __( 'Waiting...', 'shortcode-arcade' ) : (bid === 0 ? __( 'Nil', 'shortcode-arcade' ) : bid);
                const teamClass = seat % 2 === myTeam ? 'my-team' : 'opp-team';

                html += `<div class="bid-entry ${teamClass} ${seat === this.mySeat ? 'is-me' : ''}">
                    <span class="player-name">${escapeHtml(player.name)}${isPartner ? ' (' + __( 'Partner', 'shortcode-arcade' ) + ')' : ''}</span>
                    <span class="player-bid">${bidText}</span>
                </div>`;
            }
            html += '</div>';

            // My hand
            html += '<div class="my-hand-preview">';
            html += SACGACards.renderHand(myHand, { fanned: true, validCards: [] });
            html += '</div>';

            // Bid buttons if my turn
            if (isMyTurn) {
                html += '<div class="sacga-bid-area">';
                html += '<p>' + __( 'Select your bid:', 'shortcode-arcade' ) + '</p>';
                html += '<div class="sacga-bid-buttons">';
                html += '<button class="sacga-bid-btn pass" data-bid="nil">' + __( 'Nil', 'shortcode-arcade' ) + '</button>';
                for (let i = 1; i <= 13; i++) {
                    html += `<button class="sacga-bid-btn" data-bid="${i}">${i}</button>`;
                }
                html += '</div></div>';
            } else {
                html += `<p class="waiting">${__( 'Waiting for', 'shortcode-arcade' )} ${escapeHtml(this.state.players[this.state.current_turn].name)} ${__( 'to bid...', 'shortcode-arcade' )}</p>`;
            }

            html += '</div>';

            $('#sacga-game-board').html(html);

            if (isMyTurn) {
                this.bindBidEvents();
            }
        },

        bindBidEvents: function() {
            const self = this;
            $('.sacga-bid-btn').on('click', function() {
                const bid = $(this).data('bid');
                if (self.onMove) {
                    self.onMove({ bid: bid });
                }
            });
        },

        renderPlayingPhase: function() {
            const currentTurn = this.state.current_turn;
            const isMyTurn = currentTurn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];
            const myTeam = this.mySeat % 2;

            let html = '<div class="spades-table sacga-table">';

            // Render player positions
            const positions = this.getPositions();
            positions.forEach(pos => {
                html += `<div class="sacga-seat-${pos.position}">`;
                html += this.renderPlayerArea(pos.seat, pos.position);
                html += '</div>';
            });

            // Center - trick area
            html += '<div class="sacga-table-center">';
            html += '<div class="sacga-play-area">';

            if (this.state.trick.length > 0) {
                html += SACGACards.renderTrick(this.state.trick, this.state.players);
            } else {
                html += '<div class="trick-empty">' + __( 'Spades Trump', 'shortcode-arcade' ) + '</div>';
            }

            html += '</div>';

            if (this.state.spades_broken) {
                html += '<div class="spades-broken">' + __( 'Spades Broken', 'shortcode-arcade' ) + '</div>';
            }

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

            // Team scoreboard
            html += this.renderTeamScoreboard();

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
            const bid = this.state.bids[seat];
            const tricks = this.state.tricks_won[seat];
            const bidText = bid === 0 ? __( 'Nil', 'shortcode-arcade' ) : bid;

            let html = SACGACards.renderPlayerInfo(player, {
                isActive: isActive,
                showScore: false,
                extras: `<div class="bid-tricks">${__( 'Bid:', 'shortcode-arcade' )} ${bidText} | ${__( 'Won:', 'shortcode-arcade' )} ${tricks}</div>`
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

            if (isLeading) {
                if (this.state.spades_broken) {
                    return myHand.map(c => c.id);
                }
                const nonSpades = myHand.filter(c => c.suit !== 'spades');
                return nonSpades.length > 0 ? nonSpades.map(c => c.id) : myHand.map(c => c.id);
            }

            const leadSuit = trick[0].card.suit;
            const suitCards = myHand.filter(c => c.suit === leadSuit);

            if (suitCards.length > 0) {
                return suitCards.map(c => c.id);
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
        },

        renderTeamScoreboard: function() {
            const myTeam = this.mySeat % 2;
            const teams = this.state.teams;
            const scores = this.state.team_scores;
            const bags = this.state.team_bags;

            let html = '<div class="spades-scoreboard sacga-scoreboard">';
            html += '<table>';
            html += '<tr><th>' + __( 'Team', 'shortcode-arcade' ) + '</th><th>' + __( 'Score', 'shortcode-arcade' ) + '</th><th>' + __( 'Bags', 'shortcode-arcade' ) + '</th></tr>';

            for (let t = 0; t < 2; t++) {
                const teamPlayers = teams[t].map(s => this.state.players[s].name).join(' & ');
                const isMyTeam = t === myTeam;
                html += `<tr class="${isMyTeam ? 'my-team' : ''}">
                    <td>${escapeHtml(teamPlayers)}${isMyTeam ? ' ' + __( '(You)', 'shortcode-arcade' ) : ''}</td>
                    <td>${scores[t]}</td>
                    <td>${bags[t]}</td>
                </tr>`;
            }

            html += '</table></div>';
            return html;
        },

        renderRoundEnd: function() {
            let html = '<div class="spades-round-end">';
            html += '<h2>' + __( 'Round Complete!', 'shortcode-arcade' ) + '</h2>';
            html += this.renderTeamScoreboard();
            html += '<button id="spades-next-round" class="sacga-btn sacga-btn-primary">' + __( 'Next Round', 'shortcode-arcade' ) + '</button>';
            html += '</div>';

            $('#sacga-game-board').html(html);

            // Bind next round button
            $('#spades-next-round').on('click', () => {
                if (this.onMove) {
                    this.onMove({ action: 'next_round' });
                }
            });
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
        }
    };

    window.SACGAGames = window.SACGAGames || {};
    window.SACGAGames.spades = SpadesRenderer;

})(jQuery);
