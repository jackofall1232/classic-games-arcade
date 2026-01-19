/**
 * Diamonds Game Renderer
 *
 * Diamonds are always trump but carry penalties.
 * Jokers are penalty cards that always lose.
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

    const DiamondsRenderer = {
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

            let html = '<div class="diamonds-bidding">';
            html += '<h3>' + __( 'Bidding Phase', 'shortcode-arcade' ) + '</h3>';

            // Show bids
            html += '<div class="bid-status">';
            for (let seat = 0; seat < 4; seat++) {
                const player = this.state.players[seat];
                const bid = this.state.bids[seat];
                const isNil = this.state.nil_bids ? this.state.nil_bids[seat] === true : false;
                const isPartner = seat === partnerSeat;
                const bidText = bid === null ? __( 'Waiting...', 'shortcode-arcade' ) : (isNil ? __( 'Nil', 'shortcode-arcade' ) : bid);
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
                for (let i = 1; i <= 14; i++) { // 14 tricks possible with 56 cards
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

            let html = '<div class="diamonds-table sacga-table">';

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
                html += '<div class="trick-empty">' + __( 'Diamonds Trump', 'shortcode-arcade' ) + '</div>';
            }

            html += '</div>';

            // Penalty tracker (diamonds + jokers)
            html += this.renderPenaltyTracker();

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

        renderPenaltyTracker: function() {
            const myTeam = this.mySeat % 2;
            const oppTeam = (myTeam + 1) % 2;
            const myDiamonds = this.state.diamonds_captured[myTeam];
            const oppDiamonds = this.state.diamonds_captured[oppTeam];
            const myJokers = this.state.jokers_captured ? this.state.jokers_captured[myTeam] : 0;
            const oppJokers = this.state.jokers_captured ? this.state.jokers_captured[oppTeam] : 0;

            let html = '<div class="penalty-tracker">';

            // Diamonds row
            html += '<div class="tracker-row diamonds-row">';
            html += '<span class="tracker-icon">♦</span>';
            html += `<span class="tracker-team my-team">${myDiamonds}</span>`;
            html += '<span class="tracker-sep">|</span>';
            html += `<span class="tracker-team opp-team">${oppDiamonds}</span>`;
            html += '</div>';

            // Jokers row
            html += '<div class="tracker-row jokers-row">';
            html += '<span class="tracker-icon">🃏</span>';
            html += `<span class="tracker-team my-team">${myJokers}</span>`;
            html += '<span class="tracker-sep">|</span>';
            html += `<span class="tracker-team opp-team">${oppJokers}</span>`;
            html += '</div>';

            // Soft moon indicator (diamonds only)
            if (myDiamonds >= 10 || oppDiamonds >= 10) {
                html += '<div class="soft-moon-alert">' + __( 'Soft Moon!', 'shortcode-arcade' ) + '</div>';
            } else if (myDiamonds >= 7 || oppDiamonds >= 7) {
                html += '<div class="soft-moon-warning">' + __( 'Soft Moon Near', 'shortcode-arcade' ) + '</div>';
            }

            html += '</div>';
            return html;
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
            const isNil = this.state.nil_bids ? this.state.nil_bids[seat] === true : false;
            const tricks = this.state.tricks_won[seat];
            const bidText = bid === null ? '—' : (isNil ? __( 'Nil', 'shortcode-arcade' ) : bid);

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
                // Jokers can only be led if you have ONLY jokers
                const nonJokers = myHand.filter(c => c.suit !== 'joker');
                if (nonJokers.length > 0) {
                    // Can lead any non-joker
                    return nonJokers.map(c => c.id);
                }
                // Only have jokers - can lead them
                return myHand.map(c => c.id);
            }

            // Following - must follow suit if possible (jokers don't satisfy follow-suit)
            const leadSuit = trick[0].card.suit;

            // If lead was a joker (rare), anyone can play anything
            if (leadSuit === 'joker') {
                return myHand.map(c => c.id);
            }

            const suitCards = myHand.filter(c => c.suit === leadSuit);

            if (suitCards.length > 0) {
                return suitCards.map(c => c.id);
            }

            // Can't follow - any card valid (including jokers)
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
            const diamonds = this.state.diamonds_captured;

            let html = '<div class="diamonds-scoreboard sacga-scoreboard">';
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
            let html = '<div class="diamonds-round-end">';
            html += '<h2>' + __( 'Round Complete!', 'shortcode-arcade' ) + '</h2>';

            // Show round details if available
            if (this.state.round_details) {
                html += this.renderRoundDetails();
            }

            html += this.renderTeamScoreboard();
            html += '<button id="diamonds-next-round" class="sacga-btn sacga-btn-primary">' + __( 'Next Round', 'shortcode-arcade' ) + '</button>';
            html += '</div>';

            $('#sacga-game-board').html(html);

            // Bind next round button
            $('#diamonds-next-round').on('click', () => {
                if (this.onMove) {
                    this.onMove({ action: 'next_round' });
                }
            });
        },

        renderRoundDetails: function() {
            const myTeam = this.mySeat % 2;
            const details = this.state.round_details;

            let html = '<div class="round-details">';
            html += '<h4>' + __( 'Round Summary', 'shortcode-arcade' ) + '</h4>';

            for (let t = 0; t < 2; t++) {
                const d = details[t];
                const isMyTeam = t === myTeam;
                const teamLabel = isMyTeam ? __( 'Your Team', 'shortcode-arcade' ) : __( 'Opponents', 'shortcode-arcade' );

                html += `<div class="team-details ${isMyTeam ? 'my-team' : ''}">`;
                html += `<strong>${teamLabel}</strong>`;
                html += '<ul>';

                if (d.bid_score !== 0) {
                    html += `<li>${__( 'Bid Score:', 'shortcode-arcade' )} ${d.bid_score > 0 ? '+' : ''}${d.bid_score}</li>`;
                }
                if (d.bags > 0) {
                    html += `<li>${__( 'Overtricks:', 'shortcode-arcade' )} +${d.bags}</li>`;
                }
                if (d.nil_bonus > 0) {
                    html += `<li>${__( 'Nil Bonus:', 'shortcode-arcade' )} +${d.nil_bonus}</li>`;
                }
                if (d.nil_penalty > 0) {
                    html += `<li class="penalty">${__( 'Nil Penalty:', 'shortcode-arcade' )} -${d.nil_penalty}</li>`;
                }
                if (d.diamonds_penalty > 0 || d.soft_moon) {
                    html += `<li class="penalty">${__( 'Diamond Penalty:', 'shortcode-arcade' )} -${d.diamonds_penalty}</li>`;
                }
                if (d.jokers_penalty > 0) {
                    html += `<li class="penalty">${__( 'Joker Penalty:', 'shortcode-arcade' )} -${d.jokers_penalty}</li>`;
                }
                if (d.soft_moon) {
                    html += `<li class="bonus">${__( 'Soft Moon Bonus:', 'shortcode-arcade' )} +${d.soft_moon_bonus} (${d.diamonds_captured} ${__( 'diamonds', 'shortcode-arcade' )})</li>`;
                }
                if (d.bag_penalty > 0) {
                    html += `<li class="penalty">${__( 'Bag Penalty:', 'shortcode-arcade' )} -${d.bag_penalty}</li>`;
                }

                html += `<li class="total"><strong>${__( 'Round Total:', 'shortcode-arcade' )} ${d.round_total > 0 ? '+' : ''}${d.round_total}</strong></li>`;
                html += '</ul></div>';
            }

            html += '</div>';
            return html;
        },

        // Game-specific AI card play animation
        animateAICardPlay: function(seat, card, onComplete) {
            try {
                const trickCards = $('.sacga-play-area .sacga-card');
                const lastCard = trickCards.last();

                if (lastCard.length) {
                    lastCard.addClass('sacga-card-animating sacga-card-just-played');

                    setTimeout(() => {
                        try {
                            lastCard.removeClass('sacga-card-animating');

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
                    if (onComplete) onComplete();
                }
            } catch (error) {
                if (onComplete) onComplete();
            }
        }
    };

    window.SACGAGames = window.SACGAGames || {};
    window.SACGAGames.diamonds = DiamondsRenderer;

})(jQuery);
