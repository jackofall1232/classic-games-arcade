/**
 * Classic Games Arcade - Frontend Engine
 */
(function($) {
    'use strict';

    const { __, _n, _x } = wp.i18n;

    const SACGA = {
        config: window.sacgaConfig || {},
        state: null,
        room: null,
        pollTimer: null,
        mySeat: null,
        gameId: null,
        roomCode: null,
        isAnimating: false,
        pendingStateUpdate: null,
        animationQueue: [],
        animationSpeed: 1000, // ms per animation
        enableAnimations: true, // Can be disabled if causing issues
        guestTokenPromise: null,

        init: function() {
            const container = $('#sacga-game-container');
            if (!container.length) return;

            this.gameId = container.data('game-id');
            this.roomCode = container.data('room-code');

            // Ensure guest token exists (fallback if cookie failed)
            this.ensureGuestToken();

            this.bindEvents();

            const initialRoomCode = this.normalizeRoomCode(this.roomCode);
            if (initialRoomCode) {
                this.roomCode = initialRoomCode;
                this.joinRoom(initialRoomCode);
            }
        },

        normalizeRoomCode: function(code) {
            if (!code) {
                return null;
            }
            const normalized = String(code).toUpperCase().trim();
            if (normalized.length !== 6) {
                return null;
            }
            return normalized;
        },

        /**
         * Ensure guest token exists using cookies or localStorage
         * localStorage is used as a fallback when cookies are unavailable
         */
        ensureGuestToken: function() {
            // Skip if user is logged in
            if (this.config.userId && this.config.userId > 0) {
                return;
            }

            // If backend provided token via cookie, use it (preferred method)
            if (this.config.guestToken) {
                if (console && console.debug) {
                    console.debug('[SACGA] Guest identified via cookie ✓');
                }
                return;
            }

            const storageToken = localStorage.getItem('sacga_guest_token');
            const storageGuestId = localStorage.getItem('sacga_guest_id');

            if (storageToken) {
                this.config.guestToken = storageToken;
                this.config.guestId = storageGuestId || null;
                if (console && console.debug) {
                    console.debug('[SACGA] Guest identified via localStorage ✓');
                }
                return;
            }

            this.fetchGuestToken();
        },

        waitForGuestToken: function() {
            if (this.config.userId && this.config.userId > 0) {
                return $.Deferred().resolve().promise();
            }

            if (this.config.guestToken) {
                return $.Deferred().resolve().promise();
            }

            return this.fetchGuestToken();
        },

        fetchGuestToken: function() {
            if (this.guestTokenPromise) {
                return this.guestTokenPromise;
            }

            this.guestTokenPromise = $.ajax({
                url: this.config.restUrl + 'guest-token',
                method: 'GET',
                xhrFields: {
                    withCredentials: true
                }
            })
                .done((response) => {
                    if (response?.token) {
                        this.config.guestToken = response.token;
                        this.config.guestId = response.guest_id || null;
                        localStorage.setItem('sacga_guest_token', response.token);
                        if (response.guest_id) {
                            localStorage.setItem('sacga_guest_id', response.guest_id);
                        }
                        if (console && console.info) {
                            console.info('[SACGA] Guest token issued by server ✓');
                        }
                    }
                })
                .fail(() => {
                    if (console && console.error) {
                        console.error('[SACGA] Failed to fetch guest token');
                    }
                })
                .always(() => {
                    this.guestTokenPromise = null;
                });

            return this.guestTokenPromise;
        },

        escapeHtml: function(text) {
            if (text === null || text === undefined) {
                return '';
            }
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        bindEvents: function() {
            $('#sacga-create-room').on('click', () => this.createRoom());
            $('#sacga-join-room').on('click', () => this.joinRoomFromInput());
            $('#sacga-room-code-input').on('keypress', (e) => {
                if (e.key === 'Enter') this.joinRoomFromInput();
            });
            $('#sacga-add-ai').on('click', () => this.addAI());
            $('#sacga-start-game').on('click', () => this.startGame());
            $('#sacga-leave-room').on('click', () => this.leaveRoom());
            $('#sacga-copy-code').on('click', () => this.copyRoomCode());
            $('#sacga-play-again').on('click', () => this.createRoom());
            $('#sacga-back-to-lobby').on('click', () => this.backToLobby());
            $('#sacga-back-to-arcade').on('click', () => this.backToArcade());
            $('#sacga-exit-to-arcade').on('click', () => this.backToArcade());
            $('#sacga-forfeit-game').on('click', () => this.forfeitGame());
        },

        showView: function(view) {
            $('.sacga-view').removeClass('sacga-view-active');
            $('#sacga-' + view).addClass('sacga-view-active');
            $('#sacga-game-container').toggleClass('sacga-game-active', view === 'game');
        },

        showLoading: function(show) {
            $('#sacga-loading').toggle(show !== false);
        },

        showError: function(message) {
            alert(message);
        },

        api: function(endpoint, method, data) {
            method = method || 'GET';
            const options = {
                url: this.config.restUrl + endpoint,
                method: method,
                headers: {
                    'X-WP-Nonce': this.config.nonce
                },
                xhrFields: {
                    withCredentials: true  // Send cookies with requests
                }
            };

            // Add guest token header if available
            if (this.config.guestToken) {
                options.headers['X-SACGA-Guest-Token'] = this.config.guestToken;
            }

            if (data) {
                options.contentType = 'application/json';
                options.data = JSON.stringify(data);
            }
            return $.ajax(options);
        },

        createRoom: function() {
            this.showLoading();
            this.waitForGuestToken()
                .done(() => {
                    this.api('room', 'POST', { game_id: this.gameId })
                        .done((response) => {
                            if (response.success) {
                                this.room = response.room;
                                this.roomCode = response.room.room_code;
                                this.mySeat = 0;
                                this.showRoomView();
                                this.startRoomPolling();
                                this.updateURL();
                            }
                        })
                        .fail((xhr) => this.showError(xhr.responseJSON?.message || __( 'Failed to create room', 'shortcode-arcade' )))
                        .always(() => this.showLoading(false));
                })
                .fail(() => {
                    this.showError(__( 'Unable to verify guest identity.', 'shortcode-arcade' ));
                    this.showLoading(false);
                });
        },

        joinRoomFromInput: function() {
            const code = $('#sacga-room-code-input').val().toUpperCase().trim();
            if (code.length !== 6) {
                this.showError(__( 'Room code must be 6 characters', 'shortcode-arcade' ));
                return;
            }
            this.joinRoom(code);
        },

        joinRoom: function(code) {
            this.showLoading();
            this.waitForGuestToken()
                .done(() => {
                    this.api('room/' + code + '/join', 'POST')
                        .done((response) => {
                            if (response.success) {
                                this.roomCode = code;
                                this.mySeat = response.player.seat_position;
                                this.loadRoom();
                                this.updateURL();
                            }
                        })
                        .fail((xhr) => {
                            this.showError(xhr.responseJSON?.message || __( 'Failed to join room', 'shortcode-arcade' ));
                            this.showView('lobby');
                        })
                        .always(() => this.showLoading(false));
                })
                .fail(() => {
                    this.showError(__( 'Unable to verify guest identity.', 'shortcode-arcade' ));
                    this.showLoading(false);
                });
        },

        loadRoom: function() {
            this.api('room/' + this.roomCode)
                .done((response) => {
                    this.room = response;
                    this.findMySeat();
                    if (this.room.status === 'active') {
                        this.loadGameState();
                    } else if (this.room.status === 'completed') {
                        this.showGameOver();
                    } else {
                        this.showRoomView();
                        this.startRoomPolling();
                    }
                })
                .fail(() => {
                    this.showError(__( 'Room not found', 'shortcode-arcade' ));
                    this.showView('lobby');
                });
        },

        findMySeat: function() {
            if (!this.room?.players) return;
            const userId = this.config.userId;
            const guestId = this.config.guestId;
            for (const player of this.room.players) {
                if (userId && player.user_id == userId) {
                    this.mySeat = parseInt(player.seat_position);
                    return;
                }
                if (guestId && player.guest_id === guestId) {
                    this.mySeat = parseInt(player.seat_position);
                    return;
                }
            }
        },

        showRoomView: function() {
            $('#sacga-room-code-display').text(this.roomCode);
            this.updatePlayersList();
            this.updateStartButton();
            this.showView('room');
        },

        updatePlayersList: function() {
            const list = $('#sacga-players');
            list.empty();
            if (!this.room?.players) return;
            const maxPlayers = this.room.game_meta?.max_players || 2;
            for (let i = 0; i < maxPlayers; i++) {
                const player = this.room.players.find(p => parseInt(p.seat_position) === i);
                if (player) {
                    const isMe = parseInt(player.seat_position) === this.mySeat;
                    const aiTag = player.is_ai == 1 ? ' <span class="sacga-ai-tag">[AI]</span>' : '';
                    const youTag = isMe ? ' <span class="sacga-you-tag">' + __( '(You)', 'shortcode-arcade' ) + '</span>' : '';
                    list.append('<li class="sacga-player-slot sacga-player-filled"><span class="sacga-seat">' + __( 'Seat', 'shortcode-arcade' ) + ' ' + (i + 1) + ':</span> ' + this.escapeHtml(player.display_name) + aiTag + youTag + '</li>');
                } else {
                    list.append('<li class="sacga-player-slot sacga-player-empty"><span class="sacga-seat">' + __( 'Seat', 'shortcode-arcade' ) + ' ' + (i + 1) + ':</span> <em>' + __( 'Empty', 'shortcode-arcade' ) + '</em></li>');
                }
            }
        },

        updateStartButton: function() {
            if (!this.room?.game_meta) return;
            const minPlayers = this.room.game_meta.min_players || 2;
            const currentPlayers = this.room.players?.length || 0;
            const canStart = currentPlayers >= minPlayers;
            $('#sacga-start-game').prop('disabled', !canStart).text(canStart ? __( 'Start Game', 'shortcode-arcade' ) : __( 'Need', 'shortcode-arcade' ) + ' ' + (minPlayers - currentPlayers) + ' ' + __( 'more player(s)', 'shortcode-arcade' ));
        },

        addAI: function() {
            this.waitForGuestToken()
                .done(() => {
                    this.api('room/' + this.roomCode + '/ai', 'POST', { difficulty: 'beginner' })
                        .done(() => this.loadRoom())
                        .fail((xhr) => this.showError(xhr.responseJSON?.message || __( 'Failed to add AI', 'shortcode-arcade' )));
                })
                .fail(() => this.showError(__( 'Unable to verify guest identity.', 'shortcode-arcade' )));
        },

        leaveRoom: function() {
            this.stopPolling();
            this.waitForGuestToken()
                .done(() => {
                    this.api('room/' + this.roomCode + '/leave', 'POST')
                        .always(() => {
                            this.room = null;
                            this.roomCode = null;
                            this.mySeat = null;
                            this.clearURL();
                            this.showView('lobby');
                        });
                })
                .fail(() => {
                    this.room = null;
                    this.roomCode = null;
                    this.mySeat = null;
                    this.clearURL();
                    this.showView('lobby');
                });
        },

        backToLobby: function() {
            this.stopPolling();
            this.room = null;
            this.roomCode = null;
            this.mySeat = null;
            this.state = null;
            this.clearURL();
            this.showView('lobby');
        },

        backToArcade: function() {
            this.stopPolling();
            const url = new URL(window.location.href);
            url.searchParams.delete('game');
            url.searchParams.delete('room');
            window.location.href = url.toString();
        },

        copyRoomCode: function() {
            navigator.clipboard.writeText(this.roomCode).then(() => {
                const btn = $('#sacga-copy-code');
                btn.html('<span class="dashicons dashicons-yes"></span>');
                setTimeout(() => btn.html('<span class="dashicons dashicons-clipboard"></span>'), 1500);
            });
        },

        updateURL: function() {
            const url = new URL(window.location);
            url.searchParams.set('room', this.roomCode);
            window.history.pushState({}, '', url);
        },

        clearURL: function() {
            const url = new URL(window.location);
            url.searchParams.delete('room');
            window.history.pushState({}, '', url);
        },

        // Animation Queue System
        processAnimationQueue: function() {
            if (this.animationQueue.length === 0) {
                this.isAnimating = false;
                return;
            }

            this.isAnimating = true;
            const animation = this.animationQueue.shift();

            // Execute animation
            animation.execute(() => {
                // On complete, process next after delay
                setTimeout(() => this.processAnimationQueue(), animation.delay || 300);
            });
        },

        queueAnimation: function(animation) {
            this.animationQueue.push(animation);
            if (!this.isAnimating) {
                this.processAnimationQueue();
            }
        },

        startGame: function() {
            this.showLoading();
            this.stopPolling();
            this.waitForGuestToken()
                .done(() => {
                    this.api('room/' + this.roomCode + '/start', 'POST')
                        .done((response) => {
                            if (response.success) {
                                this.state = response.state;
                                this.showGameView();
                                this.startGamePolling();
                            }
                        })
                        .fail((xhr) => {
                            this.showError(xhr.responseJSON?.message || __( 'Failed to start game', 'shortcode-arcade' ));
                            this.startRoomPolling();
                        })
                        .always(() => this.showLoading(false));
                })
                .fail(() => {
                    this.showError(__( 'Unable to verify guest identity.', 'shortcode-arcade' ));
                    this.showLoading(false);
                    this.startRoomPolling();
                });
        },

        loadGameState: function() {
            this.api('game/state/' + this.roomCode)
                .done((response) => {
                    if (response.state) {
                        this.state = response.state;
                        this.showGameView();
                        this.startGamePolling();
                    } else if (!response.started) {
                        this.room = response.room;
                        this.showRoomView();
                        this.startRoomPolling();
                    }
                })
                .fail(() => this.showError(__( 'Failed to load game state', 'shortcode-arcade' )));
        },

        showGameView: function() {
            this.showView('game');
            this.renderGame();
        },

        renderGame: function() {
            if (!this.state) return;

            const currentTurn = this.state.state.current_turn;
            const isMyTurn = currentTurn === this.mySeat;
            const playerName = this.room?.players?.find(p => parseInt(p.seat_position) === currentTurn)?.display_name || __( 'Player', 'shortcode-arcade' ) + ' ' + (currentTurn + 1);
            const safePlayerName = this.escapeHtml(playerName);

            $('#sacga-current-turn').html(isMyTurn ? '<strong>' + __( 'Your turn!', 'shortcode-arcade' ) + '</strong>' : __( 'Waiting for', 'shortcode-arcade' ) + ' ' + safePlayerName + '...');

            // Use game-specific renderer
            if (window.SACGAGames && window.SACGAGames[this.gameId]) {
                window.SACGAGames[this.gameId].render(this.state, this.mySeat, (move) => this.makeMove(move));
            } else {
                $('#sacga-game-board').html('<pre>' + JSON.stringify(this.state.state, null, 2) + '</pre>');
            }

            if (this.state.state.game_over) {
                this.showGameOver();
            }
        },

        makeMove: function(move) {
            // Skip turn check for simultaneous move phases (e.g., Hearts passing, Cribbage discard, Overcut rolloff)
            const state = this.state.state;
            const isSimultaneousPhase = state.phase && ['passing', 'discard', 'rolloff'].includes(state.phase);
            const isWaitingPhase = state.phase === 'waiting';
            const isGateAction = move && ['begin_game', 'continue'].includes(move.action);
            const isMyTurn = state.current_turn === this.mySeat;

            // Gate actions and waiting phase bypass turn checks (gates are session controls, not turns)
            if (!isGateAction && !isSimultaneousPhase && !isWaitingPhase && !isMyTurn) {
                this.showError(__( 'Not your turn!', 'shortcode-arcade' ));
                return;
            }

            this.waitForGuestToken()
                .done(() => {
                    this.api('game/move/' + this.roomCode, 'POST', { move: move, etag: this.state.etag })
                        .done((response) => {
                            if (response.success) {
                                this.state = response.state;
                                this.renderGame();
                            }
                        })
                        .fail((xhr) => {
                            const error = xhr.responseJSON;
                            if (error?.code === 'stale_state') {
                                this.pollGameState();
                            } else {
                                this.showError(error?.message || __( 'Invalid move', 'shortcode-arcade' ));
                            }
                        });
                })
                .fail(() => this.showError(__( 'Unable to verify guest identity.', 'shortcode-arcade' )));
        },

        forfeitGame: function() {
            if (!confirm(__( 'Are you sure you want to forfeit? This will count as a loss.', 'shortcode-arcade' ))) {
                return;
            }

            this.showLoading();
            this.waitForGuestToken()
                .done(() => {
                    this.api('game/forfeit/' + this.roomCode, 'POST')
                        .done((response) => {
                            if (response.success) {
                                this.state = response.state;
                                this.showGameOver();
                            }
                        })
                        .fail((xhr) => {
                            this.showError(xhr.responseJSON?.message || __( 'Failed to forfeit game', 'shortcode-arcade' ));
                        })
                        .always(() => this.showLoading(false));
                })
                .fail(() => {
                    this.showError(__( 'Unable to verify guest identity.', 'shortcode-arcade' ));
                    this.showLoading(false);
                });
        },

        showGameOver: function() {
            this.stopPolling();
            const state = this.state?.state || {};
            const winners = state.winners || [];

            let title = __( 'Game Over', 'shortcode-arcade' );
            if (winners.includes(this.mySeat)) {
                title = __( 'You Won!', 'shortcode-arcade' );
            } else if (winners.length > 0) {
                title = __( 'You Lost', 'shortcode-arcade' );
            }

            $('#sacga-gameover-title').text(title);

            let scoresHtml = '';
            if (state.captured) {
                scoresHtml = '<p>' + __( 'Pieces captured:', 'shortcode-arcade' ) + '</p><ul>';
                for (const [seat, count] of Object.entries(state.captured)) {
                    const player = this.room?.players?.find(p => parseInt(p.seat_position) === parseInt(seat));
                    const playerLabel = player?.display_name || __( 'Player', 'shortcode-arcade' ) + ' ' + (parseInt(seat) + 1);
                    scoresHtml += '<li>' + this.escapeHtml(playerLabel) + ': ' + count + '</li>';
                }
                scoresHtml += '</ul>';
            }
            $('#sacga-final-scores').html(scoresHtml);

            this.showView('gameover');
        },

        // Polling
        startRoomPolling: function() {
            this.stopPolling();
            this.pollTimer = setInterval(() => this.pollRoom(), this.config.pollInterval || 2000);
        },

        startGamePolling: function() {
            this.stopPolling();
            // Poll every 800ms during active game to catch AI move animations
            this.pollTimer = setInterval(() => this.pollGameState(), 800);
        },

        stopPolling: function() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        pollRoom: function() {
            this.api('room/' + this.roomCode)
                .done((response) => {
                    this.room = response;
                    if (response.status === 'active') {
                        this.stopPolling();
                        this.loadGameState();
                    } else {
                        this.updatePlayersList();
                        this.updateStartButton();
                    }
                });
        },

        pollGameState: function() {
            const etag = this.state?.etag || '';

            this.api('game/state/' + this.roomCode + '?etag=' + etag)
                .done((response) => {
                    if (response.changed && response.state) {
                        // If animation is in progress, store as pending update
                        if (this.isAnimating) {
                            this.pendingStateUpdate = response;
                            return;
                        }

                        this.applyStateUpdate(response);
                    }
                });
        },

        applyStateUpdate: function(response) {
            // If animations are disabled, just update immediately
            if (!this.enableAnimations) {
                this.state = response.state;
                if (response.room) {
                    this.room = { ...this.room, ...response.room };
                }
                this.renderGame();
                return;
            }

            // Detect if an AI just moved
            const aiMove = this.detectAIMove(this.state, response.state);

            if (aiMove) {
                // AI moved - animate it
                this.animateAIMove(aiMove, () => {
                    // Update state after animation
                    this.state = response.state;
                    if (response.room) {
                        this.room = { ...this.room, ...response.room };
                    }
                    this.renderGame();

                    // Note: checkPendingUpdates() is called by completeAnimation(), not here
                    // Calling it here would be too early (isAnimating still true)
                });
            } else {
                // No AI move - update immediately (e.g., trick cleared)
                this.state = response.state;
                if (response.room) {
                    this.room = { ...this.room, ...response.room };
                }
                this.renderGame();
            }
        },

        checkPendingUpdates: function() {
            if (this.pendingStateUpdate && !this.isAnimating) {
                const pending = this.pendingStateUpdate;
                this.pendingStateUpdate = null;
                this.applyStateUpdate(pending);
            }
        },

        detectAIMove: function(oldState, newState) {
            try {
                if (!oldState || !newState || !this.room || !this.room.players) {
                    return null;
                }

                const oldGameState = oldState.state;
                const newGameState = newState.state;

                if (!oldGameState || !newGameState) {
                    return null;
                }

                // Only check for trick changes if the game has tricks
                if (!oldGameState.trick && !newGameState.trick) {
                    return null;
                }

                // Check if trick changed (card was played)
                const oldTrick = oldGameState.trick || [];
                const newTrick = newGameState.trick || [];

                if (newTrick.length > oldTrick.length) {
                    // A card was played - get the new card
                    const newCard = newTrick[newTrick.length - 1];

                    if (!newCard || typeof newCard.seat === 'undefined') {
                        return null;
                    }

                    const seat = newCard.seat;

                    // Check if it was an AI player
                    const player = this.room.players.find(p => parseInt(p.seat_position) === seat);

                    if (player && player.is_ai) {
                        return {
                            seat: seat,
                            card: newCard.card,
                            player: player,
                            oldState: oldGameState,
                            newState: newGameState
                        };
                    }
                }

                return null;
            } catch (error) {
                return null;
            }
        },

        animateAIMove: function(aiMove, callback) {
            this.isAnimating = true;
            const self = this;
            let animationCompleted = false;

            // Safety timeout - force complete after 1.5 seconds max (reduced for faster recovery)
            const safetyTimeout = setTimeout(() => {
                if (!animationCompleted) {
                    animationCompleted = true;
                    self.isAnimating = false;
                    // Check for pending updates after timeout
                    setTimeout(() => self.checkPendingUpdates(), 50);
                }
            }, 1500);

            const completeAnimation = () => {
                if (animationCompleted) {
                    return; // Already completed
                }
                animationCompleted = true;
                clearTimeout(safetyTimeout);
                self.isAnimating = false;

                // Process any pending state updates that came in during animation
                setTimeout(() => self.checkPendingUpdates(), 50);
            };

            try {
                // Show AI thinking for a moment
                this.showAIThinking(aiMove.seat);

                // Thinking delay based on difficulty (if available)
                const thinkingTime = this.getAIThinkingTime(aiMove.player);

                setTimeout(() => {
                    try {
                        self.hideAIThinking(aiMove.seat);

                        // Call callback to render the card first
                        if (callback) {
                            callback();
                        }

                        // Then animate the newly rendered card
                        setTimeout(() => {
                            try {
                                // Try game-specific animation first
                                if (window.SACGAGames && window.SACGAGames[self.gameId] &&
                                    typeof window.SACGAGames[self.gameId].animateAICardPlay === 'function') {
                                    // Use game-specific animation
                                    window.SACGAGames[self.gameId].animateAICardPlay(
                                        aiMove.seat,
                                        aiMove.card,
                                        completeAnimation
                                    );
                                } else {
                                    // Use default animation
                                    const trickCards = $('.sacga-play-area .sacga-card');
                                    const lastCard = trickCards.last();
                                    if (lastCard.length) {
                                        lastCard.addClass('sacga-card-animating sacga-card-just-played');

                                        // Remove animation classes after animation completes
                                        setTimeout(() => {
                                            lastCard.removeClass('sacga-card-animating sacga-card-just-played');
                                            completeAnimation();
                                        }, 600);
                                    } else {
                                        // No card found, just complete
                                        completeAnimation();
                                    }
                                }
                            } catch (error) {
                                completeAnimation();
                            }
                        }, 50);
                    } catch (error) {
                        completeAnimation();
                    }
                }, thinkingTime);
            } catch (error) {
                completeAnimation();
            }
        },

        getAIThinkingTime: function(player) {
            if (!player || !player.ai_difficulty) {
                return 400; // Default
            }

            switch (player.ai_difficulty) {
                case 'expert':
                    return 800; // Expert "thinks" longer
                case 'intermediate':
                    return 600;
                case 'beginner':
                default:
                    return 400;
            }
        },

        showAIThinking: function(seat) {
            const positions = ['bottom', 'left', 'top', 'right'];
            const myPos = this.mySeat || 0;
            const relativePos = (seat - myPos + 4) % 4;
            const position = positions[relativePos];

            $(`.sacga-seat-${position} .sacga-player-info`).addClass('ai-thinking');
        },

        hideAIThinking: function(seat) {
            const positions = ['bottom', 'left', 'top', 'right'];
            const myPos = this.mySeat || 0;
            const relativePos = (seat - myPos + 4) % 4;
            const position = positions[relativePos];

            $(`.sacga-seat-${position} .sacga-player-info`).removeClass('ai-thinking');
        },

        /**
         * Diagnostic function to check guest token status
         * Call from console: SACGA.checkGuestToken()
         */
        checkGuestToken: function() {
            console.group('[SACGA] Guest Token Diagnostics');

            console.log('User logged in:', this.config.userId > 0 ? 'Yes (user ID: ' + this.config.userId + ')' : 'No');

            if (this.config.userId > 0) {
                console.log('Status: ✓ Logged in users don\'t need guest tokens');
                console.groupEnd();
                return;
            }

            console.log('Cookie token:', this.config.guestToken ? 'Present ✓' : 'Not set');

            const storageToken = localStorage.getItem('sacga_guest_token');
            const storageGuestId = localStorage.getItem('sacga_guest_id');
            console.log('localStorage token:', storageToken ? 'Present ✓' : 'Not set');

            const currentToken = this.config.guestToken || storageToken;
            console.log('Active token:', currentToken ? currentToken.substring(0, 8) + '...' : 'None');
            console.log('Guest ID:', this.config.guestId || storageGuestId || 'None');

            console.log('Token source:',
                this.config.guestToken ? 'Cookie (preferred)' :
                storageToken ? 'localStorage (fallback)' :
                'None (ERROR)'
            );

            // Check if we can make API calls
            if (currentToken) {
                console.log('Status: ✓ Guest authentication working correctly');
                console.log('Note: Using localStorage is normal if cookies are blocked by browser settings');
            } else {
                console.error('Status: ✗ No guest token available - room creation will fail');
                console.log('Troubleshooting: Try clearing localStorage and refreshing the page');
            }

            // Show document cookies (for debugging)
            console.log('Document cookies:', document.cookie || '(empty)');

            console.groupEnd();
            return {
                userId: this.config.userId,
                cookieToken: this.config.guestToken,
                storageToken: storageToken,
                guestId: this.config.guestId || storageGuestId,
                activeToken: currentToken,
                status: currentToken ? 'OK' : 'ERROR'
            };
        }
    };

    // Game renderers namespace
    window.SACGAGames = window.SACGAGames || {};

    // Initialize
    $(document).ready(() => SACGA.init());

    // Expose for game renderers
    window.SACGA = SACGA;

})(jQuery);
