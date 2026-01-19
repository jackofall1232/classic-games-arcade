/**
 * Rummy Game Renderer
 *
 * Rules displayed in UI:
 * - 2-4 players, 52-card deck
 * - Deal: 10 cards (2 players) or 7 cards (3-4 players)
 * - Turn: Draw from deck or discard pile, then discard one card
 * - Melds: Sets (3-4 of same rank) or Runs (3+ consecutive cards of same suit)
 * - Win: Meld all cards to go out
 * - Scoring: Remaining cards = penalty points (Face=10, Ace=1, Numbers=face value)
 * - Game Over: First to 100 penalty points loses
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

    const RummyRenderer = {
        mySeat: null,
        onMove: null,
        state: null,
        selectedCards: [],
        selectedMelds: [],

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            if (this.state.phase === 'drawing') {
                this.renderDrawingPhase();
            } else if (this.state.phase === 'discarding') {
                this.renderDiscardingPhase();
            } else if (this.state.phase === 'round_end') {
                this.renderRoundEnd();
            }
        },

        renderDrawingPhase: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];
            const topDiscard = this.state.discard_pile[this.state.discard_pile.length - 1];
            const deckCount = this.state.deck.length;

            let html = '<div class="rummy-game">';

            // Header with rules
            html += this.renderHeader();

            // Game table
            html += '<div class="rummy-table">';

            // Deck and discard pile
            html += '<div class="rummy-center">';
            html += '<div class="deck-area">';
            html += '<div class="deck-pile" id="rummy-draw-deck">';
            html += `<div class="card card-back"><span class="deck-count">${deckCount}</span></div>`;
            html += '</div>';
            html += '<div class="deck-label">' + __( 'Draw Pile', 'shortcode-arcade' ) + '</div>';
            html += '</div>';

            html += '<div class="discard-area">';
            html += '<div class="discard-pile" id="rummy-draw-discard">';
            if (topDiscard && !topDiscard.hidden) {
                html += SACGACards.renderCard(topDiscard, { size: 'large' });
            } else {
                html += '<div class="empty-pile">' + __( 'Empty', 'shortcode-arcade' ) + '</div>';
            }
            html += '</div>';
            html += '<div class="deck-label">' + __( 'Discard Pile', 'shortcode-arcade' ) + '</div>';
            html += '</div>';
            html += '</div>';

            // Turn indicator
            html += '<div class="turn-indicator">';
            const currentPlayer = this.state.players[this.state.current_turn];
            if (isMyTurn) {
                html += '<strong>' + __( 'Your Turn:', 'shortcode-arcade' ) + '</strong> ' + __( 'Draw a card from the deck or discard pile', 'shortcode-arcade' );
            } else {
                html += `<strong>${escapeHtml(currentPlayer.name)}${__( "'s Turn", 'shortcode-arcade' )}</strong>`;
            }
            html += '</div>';

            // Other players' info
            html += this.renderOtherPlayers();

            // My hand
            html += '<div class="my-hand-section">';
            html += '<h4>' + __( 'Your Hand', 'shortcode-arcade' ) + '</h4>';
            html += SACGACards.renderHand(myHand, {
                fanned: true,
                selectedCards: [],
                validCards: []
            });
            html += '</div>';

            // My melds
            if (this.state.melds[this.mySeat] && this.state.melds[this.mySeat].length > 0) {
                html += '<div class="my-melds-section">';
                html += '<h4>' + __( 'Your Melds', 'shortcode-arcade' ) + '</h4>';
                html += this.renderMelds(this.state.melds[this.mySeat]);
                html += '</div>';
            }

            html += '</div>'; // end rummy-table

            html += '</div>'; // end rummy-game

            $('#sacga-game-board').html(html);

            if (isMyTurn) {
                this.bindDrawingEvents();
            }
        },

        renderDiscardingPhase: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];
            const topDiscard = this.state.discard_pile[this.state.discard_pile.length - 1];
            const deckCount = this.state.deck.length;

            let html = '<div class="rummy-game">';

            // Header with rules
            html += this.renderHeader();

            // Game table
            html += '<div class="rummy-table">';

            // Deck and discard pile
            html += '<div class="rummy-center">';
            html += '<div class="deck-area">';
            html += '<div class="deck-pile">';
            html += `<div class="card card-back"><span class="deck-count">${deckCount}</span></div>`;
            html += '</div>';
            html += '<div class="deck-label">' + __( 'Draw Pile', 'shortcode-arcade' ) + '</div>';
            html += '</div>';

            html += '<div class="discard-area">';
            html += '<div class="discard-pile">';
            if (topDiscard && !topDiscard.hidden) {
                html += SACGACards.renderCard(topDiscard, { size: 'large' });
            } else {
                html += '<div class="empty-pile">' + __( 'Empty', 'shortcode-arcade' ) + '</div>';
            }
            html += '</div>';
            html += '<div class="deck-label">' + __( 'Discard Pile', 'shortcode-arcade' ) + '</div>';
            html += '</div>';
            html += '</div>';

            // Turn indicator
            html += '<div class="turn-indicator">';
            const currentPlayer = this.state.players[this.state.current_turn];
            if (isMyTurn) {
                html += '<strong>' + __( 'Your Turn:', 'shortcode-arcade' ) + '</strong> ' + __( 'Discard a card or go out', 'shortcode-arcade' );
            } else {
                html += `<strong>${escapeHtml(currentPlayer.name)}${__( "'s Turn", 'shortcode-arcade' )}</strong>`;
            }
            html += '</div>';

            // Other players' info
            html += this.renderOtherPlayers();

            // My hand
            html += '<div class="my-hand-section">';
            html += '<h4>' + __( 'Your Hand', 'shortcode-arcade' ) + '</h4>';
            if (isMyTurn) {
                const validCards = myHand.map(c => c.suit + '_' + c.rank);
                html += SACGACards.renderHand(myHand, {
                    fanned: true,
                    selectedCards: this.selectedCards,
                    validCards: validCards
                });

                // Check if player can go out
                const melds = this.findPossibleMelds(myHand);
                const canGoOut = this.checkCanGoOut(myHand, melds);

                html += '<div class="rummy-actions">';
                if (canGoOut) {
                    html += '<button id="rummy-go-out" class="sacga-btn sacga-btn-success">' + __( 'Go Out!', 'shortcode-arcade' ) + '</button>';
                }
                html += '<button id="rummy-discard" class="sacga-btn sacga-btn-primary" ' +
                    (this.selectedCards.length === 1 ? '' : 'disabled') + '>' + __( 'Discard Selected', 'shortcode-arcade' ) + '</button>';
                html += '</div>';
            } else {
                html += SACGACards.renderHand(myHand, {
                    fanned: true,
                    selectedCards: [],
                    validCards: []
                });
            }
            html += '</div>';

            // My melds
            if (this.state.melds[this.mySeat] && this.state.melds[this.mySeat].length > 0) {
                html += '<div class="my-melds-section">';
                html += '<h4>' + __( 'Your Melds', 'shortcode-arcade' ) + '</h4>';
                html += this.renderMelds(this.state.melds[this.mySeat]);
                html += '</div>';
            }

            html += '</div>'; // end rummy-table

            html += '</div>'; // end rummy-game

            $('#sacga-game-board').html(html);

            if (isMyTurn) {
                this.bindDiscardingEvents();
            }
        },

        renderRoundEnd: function() {
            const winner = this.state.players[this.state.winner];
            const scores = this.state.scores;

            let html = '<div class="rummy-game">';

            // Header with rules
            html += this.renderHeader();

            html += '<div class="round-end-section">';
            html += '<h3>' + __( 'Round Over!', 'shortcode-arcade' ) + '</h3>';
            html += `<p><strong>${winner.name}</strong> ${__( 'went out!', 'shortcode-arcade' )}</p>`;

            // Show scores
            html += '<div class="score-table">';
            html += '<table>';
            html += '<thead><tr><th>' + __( 'Player', 'shortcode-arcade' ) + '</th><th>' + __( 'Round Points', 'shortcode-arcade' ) + '</th><th>' + __( 'Total Score', 'shortcode-arcade' ) + '</th></tr></thead>';
            html += '<tbody>';

            for (let seat in this.state.players) {
                const player = this.state.players[seat];
                const hand = this.state.hands[seat];
                let roundPoints = 0;
                hand.forEach(card => {
                    const rank = card.rank;
                    if (rank === 'J' || rank === 'Q' || rank === 'K') {
                        roundPoints += 10;
                    } else if (rank === 'A') {
                        roundPoints += 1;
                    } else {
                        roundPoints += parseInt(rank);
                    }
                });

                const isMe = parseInt(seat) === this.mySeat;
                html += `<tr ${isMe ? 'class="is-me"' : ''}>
                    <td>${escapeHtml(player.name)}${isMe ? ' ' + __( '(You)', 'shortcode-arcade' ) : ''}</td>
                    <td>+${roundPoints}</td>
                    <td>${scores[seat]}</td>
                </tr>`;
            }

            html += '</tbody>';
            html += '</table>';
            html += '</div>';

            if (this.state.game_over) {
                const finalWinner = this.state.players[this.state.winner];
                html += `<div class="game-over"><h2>${__( 'Game Over!', 'shortcode-arcade' )}</h2>`;
                html += `<p><strong>${finalWinner.name}</strong> ${__( 'wins with the lowest score!', 'shortcode-arcade' )}</p></div>`;
            } else {
                html += '<p class="next-round">' + __( 'Starting next round...', 'shortcode-arcade' ) + '</p>';
            }

            html += '</div>';
            html += '</div>';

            $('#sacga-game-board').html(html);
        },

        renderHeader: function() {
            let html = '<div class="rummy-header">';
            html += '<h3>' + __( 'Rummy', 'shortcode-arcade' ) + '</h3>';
            html += '<div class="rummy-rules">';
            html += '<strong>' + __( 'Rules:', 'shortcode-arcade' ) + '</strong> ';
            html += __( '2-4 players, 52 cards.', 'shortcode-arcade' ) + ' ';
            html += __( 'Deal: 10 cards (2p) or 7 cards (3-4p).', 'shortcode-arcade' ) + ' ';
            html += '<strong>' + __( 'Turn:', 'shortcode-arcade' ) + '</strong> ' + __( 'Draw from deck/discard, then discard.', 'shortcode-arcade' ) + ' ';
            html += '<strong>' + __( 'Melds:', 'shortcode-arcade' ) + '</strong> ' + __( 'Sets (3-4 same rank) or Runs (3+ consecutive same suit).', 'shortcode-arcade' ) + ' ';
            html += '<strong>' + __( 'Win:', 'shortcode-arcade' ) + '</strong> ' + __( 'Go out by melding all cards.', 'shortcode-arcade' ) + ' ';
            html += '<strong>' + __( 'Score:', 'shortcode-arcade' ) + '</strong> ' + __( 'Cards in hand = penalty (Face=10, Ace=1, Numbers=value).', 'shortcode-arcade' ) + ' ';
            html += '<strong>' + __( 'Game Over:', 'shortcode-arcade' ) + '</strong> ' + __( 'First to 100 points loses.', 'shortcode-arcade' );
            html += '</div>';

            // Show current scores
            html += '<div class="score-display">';
            html += '<strong>' + __( 'Scores:', 'shortcode-arcade' ) + '</strong> ';
            for (let seat in this.state.players) {
                const player = this.state.players[seat];
                const score = this.state.scores[seat];
                const isMe = parseInt(seat) === this.mySeat;
                html += `<span class="${isMe ? 'my-score' : ''}">${escapeHtml(player.name)}: ${score}</span> `;
            }
            html += '</div>';

            html += '</div>';
            return html;
        },

        renderOtherPlayers: function() {
            let html = '<div class="other-players">';

            for (let seat in this.state.players) {
                seat = parseInt(seat);
                if (seat === this.mySeat) continue;

                const player = this.state.players[seat];
                const handCount = this.state.hands[seat].length;
                const melds = this.state.melds[seat] || [];
                const isCurrent = seat === this.state.current_turn;

                html += `<div class="player-info ${isCurrent ? 'current-turn' : ''}">`;
                html += `<div class="player-name">${escapeHtml(player.name)}${isCurrent ? ' *' : ''}</div>`;
                html += `<div class="player-cards">${handCount} ${__( 'cards', 'shortcode-arcade' )}</div>`;
                if (melds.length > 0) {
                    html += `<div class="player-melds">${melds.length} ${__( 'melds', 'shortcode-arcade' )}</div>`;
                }
                html += '</div>';
            }

            html += '</div>';
            return html;
        },

        renderMelds: function(melds) {
            let html = '<div class="melds-display">';
            melds.forEach((meld, idx) => {
                html += '<div class="meld">';
                meld.forEach(cardId => {
                    const parts = cardId.split('_');
                    const card = { suit: parts[0], rank: parts[1] };
                    html += SACGACards.renderCard(card, { size: 'small' });
                });
                html += '</div>';
            });
            html += '</div>';
            return html;
        },

        bindDrawingEvents: function() {
            const self = this;

            $('#rummy-draw-deck').on('click', function() {
                if (self.state.deck.length > 0 && self.onMove) {
                    self.onMove({ action: 'draw_deck' });
                }
            });

            $('#rummy-draw-discard').on('click', function() {
                if (self.state.discard_pile.length > 0 && self.onMove) {
                    self.onMove({ action: 'draw_discard' });
                }
            });
        },

        bindDiscardingEvents: function() {
            const self = this;

            SACGACards.bindCardClicks('.my-hand-section', (card) => {
                const cardId = card.suit + '_' + card.rank;
                const idx = self.selectedCards.indexOf(cardId);
                if (idx > -1) {
                    self.selectedCards = [];
                } else {
                    self.selectedCards = [cardId];
                }
                self.renderDiscardingPhase();
            });

            $('#rummy-discard').on('click', function() {
                if (self.selectedCards.length === 1 && self.onMove) {
                    self.onMove({
                        action: 'discard',
                        card_id: self.selectedCards[0]
                    });
                    self.selectedCards = [];
                }
            });

            $('#rummy-go-out').on('click', function() {
                const myHand = self.state.hands[self.mySeat];
                const melds = self.findPossibleMelds(myHand);

                // Collect all melded cards
                const meldedCards = [];
                melds.forEach(meld => {
                    meld.forEach(cardId => meldedCards.push(cardId));
                });

                // Find remaining cards
                const remaining = [];
                myHand.forEach(card => {
                    const cardId = card.suit + '_' + card.rank;
                    if (!meldedCards.includes(cardId)) {
                        remaining.push(cardId);
                    }
                });

                if (self.onMove) {
                    self.onMove({
                        action: 'go_out',
                        melds: melds,
                        final_discard: remaining[0] || null
                    });
                }
            });
        },

        findPossibleMelds: function(hand) {
            const rankValues = {
                'A': 1, '2': 2, '3': 3, '4': 4, '5': 5, '6': 6, '7': 7,
                '8': 8, '9': 9, '10': 10, 'J': 11, 'Q': 12, 'K': 13
            };

            // Find all possible sets
            const allSets = [];
            const byRank = {};
            hand.forEach(card => {
                const rank = card.rank;
                if (!byRank[rank]) {
                    byRank[rank] = [];
                }
                byRank[rank].push(card.suit + '_' + card.rank);
            });

            for (let rank in byRank) {
                if (byRank[rank].length >= 3) {
                    allSets.push(byRank[rank]);
                }
            }

            // Find all possible runs
            const allRuns = [];
            const bySuit = {};
            hand.forEach(card => {
                const suit = card.suit;
                if (!bySuit[suit]) {
                    bySuit[suit] = [];
                }
                bySuit[suit].push(card);
            });

            for (let suit in bySuit) {
                const cards = bySuit[suit];
                if (cards.length >= 3) {
                    cards.sort((a, b) => rankValues[a.rank] - rankValues[b.rank]);

                    let run = [cards[0].suit + '_' + cards[0].rank];
                    for (let i = 1; i < cards.length; i++) {
                        const prevRank = rankValues[cards[i - 1].rank];
                        const currRank = rankValues[cards[i].rank];

                        if (currRank === prevRank + 1) {
                            run.push(cards[i].suit + '_' + cards[i].rank);
                        } else {
                            if (run.length >= 3) {
                                allRuns.push([...run]);
                            }
                            run = [cards[i].suit + '_' + cards[i].rank];
                        }
                    }

                    if (run.length >= 3) {
                        allRuns.push(run);
                    }
                }
            }

            // Now find the best non-overlapping combination
            // Greedy approach: pick melds that cover the most cards first
            const allMelds = [...allSets, ...allRuns];
            allMelds.sort((a, b) => b.length - a.length); // Sort by length descending

            const usedCards = new Set();
            const finalMelds = [];

            for (let meld of allMelds) {
                // Check if any card in this meld is already used
                const hasOverlap = meld.some(cardId => usedCards.has(cardId));

                if (!hasOverlap) {
                    // This meld doesn't overlap, add it
                    finalMelds.push(meld);
                    meld.forEach(cardId => usedCards.add(cardId));
                }
            }

            return finalMelds;
        },

        checkCanGoOut: function(hand, melds) {
            // Collect all melded cards
            const meldedCards = [];
            melds.forEach(meld => {
                meld.forEach(cardId => meldedCards.push(cardId));
            });

            // Count remaining cards
            let remaining = 0;
            hand.forEach(card => {
                const cardId = card.suit + '_' + card.rank;
                if (!meldedCards.includes(cardId)) {
                    remaining++;
                }
            });

            // Can go out if 0 or 1 cards remaining
            return remaining <= 1;
        }
    };

    // Register in SACGAGames namespace
    window.SACGAGames = window.SACGAGames || {};
    window.SACGAGames.rummy = RummyRenderer;

})(jQuery);
