/**
 * Shared Card Game Utilities
 */
(function($) {
    'use strict';

    const { __, _n, _x } = wp.i18n;

    const SUIT_SYMBOLS = {
        hearts: '&hearts;',
        diamonds: '&diams;',
        clubs: '&clubs;',
        spades: '&spades;',
        joker: '&#127183;' // Joker playing card symbol
    };

    const RANK_DISPLAY = {
        '2': '2', '3': '3', '4': '4', '5': '5', '6': '6', '7': '7',
        '8': '8', '9': '9', '10': '10', 'J': 'J', 'Q': 'Q', 'K': 'K', 'A': 'A',
        'joker': '&#9733;' // Star symbol for joker rank
    };

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

    window.SACGACards = {
        /**
         * Render a single card
         */
        renderCard: function(card, options = {}) {
            const defaults = {
                faceDown: false,
                selected: false,
                disabled: false,
                clickable: true,
                size: 'normal' // 'small', 'normal', 'large'
            };
            const opts = { ...defaults, ...options };

            if (!card) {
                return '';
            }

            if (opts.faceDown) {
                return `<div class="sacga-card sacga-card-back" data-card-id="${card.id || ''}"></div>`;
            }

            const suitClass = card.suit;
            const selectedClass = opts.selected ? 'selected' : '';
            const disabledClass = opts.disabled ? 'disabled' : '';
            const sizeClass = opts.size !== 'normal' ? `sacga-card-${opts.size}` : '';

            return `
                <div class="sacga-card ${suitClass} ${selectedClass} ${disabledClass} ${sizeClass}"
                     data-card-id="${card.id}"
                     data-suit="${card.suit}"
                     data-rank="${card.rank}">
                    <span class="sacga-card-corner sacga-card-corner-tl">
                        ${RANK_DISPLAY[card.rank]}${SUIT_SYMBOLS[card.suit]}
                    </span>
                    <span class="sacga-card-suit">${SUIT_SYMBOLS[card.suit]}</span>
                    <span class="sacga-card-rank">${RANK_DISPLAY[card.rank]}</span>
                    <span class="sacga-card-corner sacga-card-corner-br">
                        ${RANK_DISPLAY[card.rank]}${SUIT_SYMBOLS[card.suit]}
                    </span>
                </div>
            `;
        },

        /**
         * Render a hand of cards
         */
        renderHand: function(cards, options = {}) {
            const defaults = {
                fanned: false,
                selectedCards: [],
                validCards: [],
                onCardClick: null
            };
            const opts = { ...defaults, ...options };

            const fannedClass = opts.fanned ? 'sacga-hand-fanned' : '';
            let html = `<div class="sacga-hand ${fannedClass}">`;

            if (!cards || !Array.isArray(cards)) {
                html += '</div>';
                return html;
            }

            cards.forEach(card => {
                const isSelected = opts.selectedCards.includes(card.id);
                const isValid = opts.validCards.length === 0 || opts.validCards.includes(card.id);

                html += this.renderCard(card, {
                    selected: isSelected,
                    disabled: !isValid
                });
            });

            html += '</div>';
            return html;
        },

        /**
         * Render opponent's hidden hand
         */
        renderOpponentHand: function(cardCount, options = {}) {
            const defaults = {
                vertical: false,
                maxShow: 13
            };
            const opts = { ...defaults, ...options };

            const verticalClass = opts.vertical ? 'vertical' : '';
            const count = Math.min(cardCount, opts.maxShow);

            let html = `<div class="sacga-opponent-hand ${verticalClass}">`;
            for (let i = 0; i < count; i++) {
                html += '<div class="sacga-card sacga-card-back"></div>';
            }
            html += '</div>';

            return html;
        },

        /**
         * Render a trick (cards played to center)
         */
        renderTrick: function(trickCards, players, options = {}) {
            const defaults = {
                showLabels: true
            };
            const opts = { ...defaults, ...options };

            let html = '<div class="sacga-trick">';

            trickCards.forEach(play => {
                const playerName = players[play.seat]?.name || `Player ${play.seat + 1}`;
                html += `
                    <div class="sacga-trick-card">
                        ${this.renderCard(play.card)}
                        ${opts.showLabels ? `<span class="sacga-player-label">${escapeHtml(playerName)}</span>` : ''}
                    </div>
                `;
            });

            html += '</div>';
            return html;
        },

        /**
         * Render trump indicator
         */
        renderTrumpIndicator: function(suit) {
            if (!suit) return '';
            return `
                <div class="sacga-trump-indicator ${suit}">
                    ${__( 'Trump:', 'shortcode-arcade' )} ${SUIT_SYMBOLS[suit]}
                </div>
            `;
        },

        /**
         * Render player info box
         */
        renderPlayerInfo: function(player, options = {}) {
            const defaults = {
                isActive: false,
                showScore: true,
                score: 0,
                extras: ''
            };
            const opts = { ...defaults, ...options };

            const activeClass = opts.isActive ? 'active' : '';

            return `
                    <div class="sacga-player-info ${activeClass}">
                    <div class="name">${escapeHtml(player.name)}${player.is_ai ? ' <span class="ai-badge">AI</span>' : ''}</div>
                    ${opts.showScore ? `<div class="score">${__( 'Score:', 'shortcode-arcade' )} ${opts.score}</div>` : ''}
                    ${opts.extras}
                </div>
            `;
        },

        /**
         * Render bid buttons
         */
        renderBidButtons: function(validBids, onBid) {
            let html = '<div class="sacga-bid-buttons">';

            validBids.forEach(bid => {
                const passClass = bid.value === 'pass' ? 'pass' : '';
                html += `
                    <button class="sacga-bid-btn ${passClass}" data-bid="${bid.value}">
                        ${bid.label}
                    </button>
                `;
            });

            html += '</div>';
            return html;
        },

        /**
         * Render scoreboard
         */
        renderScoreboard: function(scores, players, options = {}) {
            const defaults = {
                teamGame: false,
                teams: null
            };
            const opts = { ...defaults, ...options };

            let html = '<div class="sacga-scoreboard"><table>';

            if (opts.teamGame && opts.teams) {
                html += '<tr><th>' + __( 'Team', 'shortcode-arcade' ) + '</th><th>' + __( 'Score', 'shortcode-arcade' ) + '</th></tr>';
                opts.teams.forEach((team, idx) => {
                    const teamPlayers = team.map(seat => players[seat]?.name || `P${seat + 1}`).join(' & ');
                    html += `<tr class="sacga-team-${idx}"><td>${escapeHtml(teamPlayers)}</td><td>${scores[idx] || 0}</td></tr>`;
                });
            } else {
                html += '<tr><th>' + __( 'Player', 'shortcode-arcade' ) + '</th><th>' + __( 'Score', 'shortcode-arcade' ) + '</th></tr>';
                Object.entries(players).forEach(([seat, player]) => {
                    html += `<tr><td>${escapeHtml(player.name)}</td><td>${scores[seat] || 0}</td></tr>`;
                });
            }

            html += '</table></div>';
            return html;
        },

        /**
         * Sort cards by suit then rank
         */
        sortCards: function(cards, options = {}) {
            const defaults = {
                suitOrder: ['spades', 'hearts', 'diamonds', 'clubs'],
                rankOrder: ['A', 'K', 'Q', 'J', '10', '9', '8', '7', '6', '5', '4', '3', '2'],
                trumpFirst: null
            };
            const opts = { ...defaults, ...options };

            let suitOrder = [...opts.suitOrder];
            if (opts.trumpFirst) {
                suitOrder = [opts.trumpFirst, ...suitOrder.filter(s => s !== opts.trumpFirst)];
            }

            return [...cards].sort((a, b) => {
                const suitDiff = suitOrder.indexOf(a.suit) - suitOrder.indexOf(b.suit);
                if (suitDiff !== 0) return suitDiff;
                return opts.rankOrder.indexOf(a.rank) - opts.rankOrder.indexOf(b.rank);
            });
        },

        /**
         * Get cards that can be legally played
         */
        getPlayableCards: function(hand, leadSuit, trump, mustFollowSuit = true) {
            if (!leadSuit || !mustFollowSuit) {
                return hand.map(c => c.id);
            }

            // Must follow suit if possible
            const suitCards = hand.filter(c => c.suit === leadSuit);
            if (suitCards.length > 0) {
                return suitCards.map(c => c.id);
            }

            // Can play anything
            return hand.map(c => c.id);
        },

        /**
         * Bind card click events
         */
        bindCardClicks: function(container, callback) {
            $(container).on('click', '.sacga-card:not(.disabled):not(.sacga-card-back)', function() {
                const cardId = $(this).data('card-id');
                const suit = $(this).data('suit');
                const rank = $(this).data('rank');
                callback({ id: cardId, suit, rank }, $(this));
            });
        },

        /**
         * Highlight winning card in trick
         */
        highlightWinner: function(container, winningCardId) {
            $(container).find('.sacga-card').removeClass('winner');
            $(container).find(`.sacga-card[data-card-id="${winningCardId}"]`).addClass('winner');
        }
    };

})(jQuery);
