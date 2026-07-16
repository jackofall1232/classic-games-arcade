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
        clientId: null, // Persistent client ID for auto-rejoin
        audioCtx: null,
        isMuted: localStorage.getItem('sacga_muted') === 'true',

        init: function() {
            const container = $('#sacga-game-container');
            if (!container.length) return;

            this.gameId = container.data('game-id');
            this.roomCode = container.data('room-code');

            // Initialize persistent client ID for auto-rejoin
            this.ensureClientId();

            // Ensure guest token exists (fallback if cookie failed)
            this.ensureGuestToken();

            this.bindEvents();

            // Load persistent nickname from local storage if any
            const savedNickname = localStorage.getItem('sacga_nickname');
            if (savedNickname) {
                $('#sacga-nickname-input').val(savedNickname);
            }

            const initialRoomCode = this.normalizeRoomCode(this.roomCode);
            if (initialRoomCode) {
                this.roomCode = initialRoomCode;
                this.joinRoom(initialRoomCode);
            } else {
                // No room code in URL - check for auto-rejoin
                this.checkAutoRejoin();
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
         * Ensure persistent client ID exists for auto-rejoin functionality
         * Uses localStorage to persist across browser sessions
         */
        ensureClientId: function() {
            const storageKey = 'sacga_client_id';
            let clientId = localStorage.getItem(storageKey);

            if (!clientId) {
                // Generate a new UUID for this client
                if (typeof crypto !== 'undefined' && crypto.randomUUID) {
                    clientId = crypto.randomUUID();
                } else {
                    // Fallback for older browsers
                    clientId = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                        const r = Math.random() * 16 | 0;
                        const v = c === 'x' ? r : (r & 0x3 | 0x8);
                        return v.toString(16);
                    });
                }
                localStorage.setItem(storageKey, clientId);
                if (console && console.debug) {
                    console.debug('[SACGA] Generated new client ID:', clientId.substring(0, 8) + '...');
                }
            } else {
                if (console && console.debug) {
                    console.debug('[SACGA] Client ID loaded from storage:', clientId.substring(0, 8) + '...');
                }
            }

            this.clientId = clientId;
        },

        /**
         * Check if we can auto-rejoin a previous room
         */
        checkAutoRejoin: function() {
            if (!this.clientId || !this.gameId) {
                return;
            }

            if (console && console.debug) {
                console.debug('[SACGA] Checking for auto-rejoin...');
            }

            $.ajax({
                url: this.config.restUrl + 'rejoin-check',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    client_id: this.clientId,
                    game_id: this.gameId
                }),
                headers: {
                    'X-WP-Nonce': this.config.nonce,
                    'X-SACGA-Client-ID': this.clientId
                }
            })
            .done((response) => {
                if (response.status === 'found' && response.room_code) {
                    if (console && console.info) {
                        console.info('[SACGA] Auto-rejoining room:', response.room_code);
                    }

                    this.roomCode = response.room_code;
                    this.mySeat = response.seat;
                    this.room = response.room;

                    // Update URL to reflect the room
                    this.updateURL();

                    if (response.room_status === 'active' && response.game_state) {
                        // Game is in progress - go directly to game view
                        this.state = response.game_state;
                        this.showGameView();
                        this.startGamePolling();
                    } else if (response.room_status === 'lobby') {
                        // Game in lobby - show room view
                        this.showRoomView();
                        this.startRoomPolling();
                    } else {
                        // Room completed or other status
                        this.showView('lobby');
                    }
                } else {
                    if (console && console.debug) {
                        console.debug('[SACGA] No room to rejoin');
                    }
                }
            })
            .fail(() => {
                if (console && console.debug) {
                    console.debug('[SACGA] Auto-rejoin check failed');
                }
            });
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

            // Visibility-aware catch-up polling when returning to tab
            $(document).on('visibilitychange', () => {
                if (!document.hidden && this.roomCode) {
                    if (this.room && this.room.status === 'active') {
                        this.pollDelay = 800; // Reset to snappy base
                        this.pollGameState();
                    } else {
                        this.pollDelay = this.config.pollInterval || 2000;
                        this.pollRoom();
                    }
                }
            });
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

            // Add client ID header for auto-rejoin support
            if (this.clientId) {
                options.headers['X-SACGA-Client-ID'] = this.clientId;
            }

            if (data) {
                options.contentType = 'application/json';
                options.data = JSON.stringify(data);
            }
            return $.ajax(options);
        },

        getNickname: function() {
            const nickname = $('#sacga-nickname-input').val()?.trim() || '';
            if (nickname) {
                localStorage.setItem('sacga_nickname', nickname);
            }
            return nickname;
        },

        createRoom: function() {
            const nickname = this.getNickname();
            this.showLoading();
            this.waitForGuestToken()
                .done(() => {
                    const postData = { game_id: this.gameId };
                    if (nickname) {
                        postData.display_name = nickname;
                    }
                    this.api('room', 'POST', postData)
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
            const nickname = this.getNickname();
            this.showLoading();
            this.waitForGuestToken()
                .done(() => {
                    const postData = {};
                    if (nickname) {
                        postData.display_name = nickname;
                    }
                    this.api('room/' + code + '/join', 'POST', postData)
                        .done((response) => {
                            if (response.success) {
                                this.roomCode = code;
                                this.mySeat = response.player.seat_position;
                                this.loadRoom();
                                this.updateURL();
                            }
                        })
                        .fail((xhr) => {
                            const errCode = xhr.responseJSON?.code;
                            if (errCode === 'room_full' || errCode === 'game_started') {
                                if (confirm(xhr.responseJSON?.message + '\n\n' + __( 'Would you like to spectate the match live instead?', 'shortcode-arcade' ))) {
                                    this.roomCode = code;
                                    this.mySeat = null;
                                    this.isSpectating = true;
                                    this.loadRoomForSpectator();
                                    this.updateURL();
                                    return;
                                }
                            }
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

                    if (this.mySeat === null) {
                        this.stopPolling();
                        this.showError(__( 'You have been kicked from this room by the host.', 'shortcode-arcade' ));
                        this.backToLobby();
                        return;
                    }

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

        loadRoomForSpectator: function() {
            this.api('room/' + this.roomCode)
                .done((response) => {
                    this.room = response;
                    this.mySeat = null; // Ensure null seat for spectator
                    this.isSpectating = true;

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
                    this.backToLobby();
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

        getHostSeat: function() {
            if (!this.room?.players || this.room.players.length === 0) {
                return 0;
            }
            const seats = this.room.players.map(p => parseInt(p.seat_position));
            return Math.min(...seats);
        },

        isHost: function() {
            return this.mySeat === this.getHostSeat();
        },

        updatePlayersList: function() {
            const list = $('#sacga-players');
            list.empty();
            if (!this.room?.players) return;
            const maxPlayers = this.room.game_meta?.max_players || 2;
            const isHost = this.isHost();

            for (let i = 0; i < maxPlayers; i++) {
                const player = this.room.players.find(p => parseInt(p.seat_position) === i);
                if (player) {
                    const isMe = parseInt(player.seat_position) === this.mySeat;
                    const aiTag = player.is_ai == 1 ? ' <span class="sacga-ai-tag">[AI]</span>' : '';
                    const youTag = isMe ? ' <span class="sacga-you-tag">' + __( '(You)', 'shortcode-arcade' ) + '</span>' : '';
                    const isDisconnected = parseInt(player.connected) === 0 && !player.is_ai;
                    const disconnectTag = isDisconnected ? ' <span class="sacga-disconnected-badge">' + __( 'Offline', 'shortcode-arcade' ) + '</span>' : '';
                    
                    let kickBtn = '';
                    if (isHost && !isMe) {
                        kickBtn = ' <button type="button" class="sacga-kick-btn sacga-btn-text sacga-btn-danger" data-seat="' + i + '">' + __( 'Kick', 'shortcode-arcade' ) + '</button>';
                    }

                    list.append('<li class="sacga-player-slot sacga-player-filled"><span class="sacga-seat">' + __( 'Seat', 'shortcode-arcade' ) + ' ' + (i + 1) + ':</span> ' + this.escapeHtml(player.display_name) + aiTag + youTag + disconnectTag + kickBtn + '</li>');
                } else {
                    list.append('<li class="sacga-player-slot sacga-player-empty"><span class="sacga-seat">' + __( 'Seat', 'shortcode-arcade' ) + ' ' + (i + 1) + ':</span> <em>' + __( 'Empty', 'shortcode-arcade' ) + '</em></li>');
                }
            }

            // Bind kick actions
            list.find('.sacga-kick-btn').on('click', (e) => {
                const seat = $(e.target).data('seat');
                this.kickPlayer(seat);
            });
        },

        updateStartButton: function() {
            if (!this.room?.game_meta) return;
            const minPlayers = this.room.game_meta.min_players || 2;
            const currentPlayers = this.room.players?.length || 0;
            const canStart = currentPlayers >= minPlayers;
            
            if (this.isSpectating) {
                // Spectating view: hide add AI and show spectating label
                $('#sacga-start-game').show().prop('disabled', true).text(__( 'Spectating match live...', 'shortcode-arcade' ));
                $('#sacga-add-ai').hide();
            } else if (this.isHost()) {
                $('#sacga-start-game').show().prop('disabled', !canStart).text(canStart ? __( 'Start Game', 'shortcode-arcade' ) : __( 'Need', 'shortcode-arcade' ) + ' ' + (minPlayers - currentPlayers) + ' ' + __( 'more player(s)', 'shortcode-arcade' ));
                $('#sacga-add-ai').show();
            } else {
                // Non-host: disable button and inform them to wait
                $('#sacga-start-game').show().prop('disabled', true).text(__( 'Waiting for host to start...', 'shortcode-arcade' ));
                $('#sacga-add-ai').hide();
            }
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

        kickPlayer: function(seat) {
            this.waitForGuestToken()
                .done(() => {
                    this.api('room/' + this.roomCode + '/kick/' + seat, 'POST')
                        .done(() => this.loadRoom())
                        .fail((xhr) => this.showError(xhr.responseJSON?.message || __( 'Failed to kick player', 'shortcode-arcade' )));
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
            this.isSpectating = false;
            this.clearURL();
            this.showView('lobby');
        },

        backToArcade: function() {
            this.stopPolling();
            // Use the arcade URL from config if available (set by PHP)
            if (this.config.arcadeUrl) {
                window.location.href = this.config.arcadeUrl;
            } else {
                // Fallback: remove game/room params from current URL
                const url = new URL(window.location.href);
                url.searchParams.delete('game');
                url.searchParams.delete('room');
                window.location.href = url.toString();
            }
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
            this.setupGameControls(); // Inject Emotes & Volume/Mute controls (P5.3/5.4)

            // Deal-in animation: only on entering the game view (start/rejoin),
            // never on poll re-renders (cards-base.css .sacga-dealing).
            const $container = $('#sacga-game-container');
            $container.addClass('sacga-dealing');
            setTimeout(() => $container.removeClass('sacga-dealing'), 1800);
        },

        renderGame: function() {
            if (!this.state) return;

            const currentTurn = this.state.state.current_turn;
            const isMyTurn = currentTurn === this.mySeat;
            const playerName = this.room?.players?.find(p => parseInt(p.seat_position) === currentTurn)?.display_name || __( 'Player', 'shortcode-arcade' ) + ' ' + (currentTurn + 1);
            const safePlayerName = this.escapeHtml(playerName);

            if (this.isSpectating) {
                $('#sacga-current-turn').html('<span class="sacga-spectator-badge"><span class="dashicons dashicons-visibility"></span> ' + __( 'Spectating Match Live', 'shortcode-arcade' ) + '</span> — ' + __( 'Turn:', 'shortcode-arcade' ) + ' ' + safePlayerName);
            } else {
                $('#sacga-current-turn').html(isMyTurn ? '<strong>' + __( 'Your turn!', 'shortcode-arcade' ) + '</strong>' : __( 'Waiting for', 'shortcode-arcade' ) + ' ' + safePlayerName + '...');
            }

            // Use game-specific renderer
            if (window.SACGAGames && window.SACGAGames[this.gameId]) {
                window.SACGAGames[this.gameId].render(this.state, this.mySeat, (move) => this.makeMove(move));
            } else {
                $('#sacga-game-board').html('<pre>' + JSON.stringify(this.state.state, null, 2) + '</pre>');
            }

            if (this.state.state.game_over) {
                this.showGameOver();
                if (!this.playedVictorySound) {
                    this.playSynthSound('victory');
                    this.playedVictorySound = true;
                }
            } else {
                this.playedVictorySound = false;
            }

            // Render Gemini bot comments if any are active (P2.17)
            this.renderBotComments();
        },

        renderBotComments: function() {
            // Clear any existing comments bubbles first
            $('.sacga-bot-bubble').remove();

            if (!this.state?.state?.bot_comments) return;

            const comments = this.state.state.bot_comments;
            for (const seat in comments) {
                const commentText = comments[seat];
                if (!commentText) continue;

                const player = this.room?.players?.find(p => parseInt(p.seat_position) === parseInt(seat));
                const displayName = player ? player.display_name : __( 'AI Bot', 'shortcode-arcade' );

                // Create modern speech bubble HTML overlay
                const bubbleHtml = `
                    <div class="sacga-bot-bubble sacga-bubble-seat-${seat}" style="display: none;">
                        <div class="sacga-bubble-header">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <strong>${this.escapeHtml(displayName)}</strong>
                        </div>
                        <div class="sacga-bubble-content">"${this.escapeHtml(commentText)}"</div>
                    </div>
                `;

                $('#sacga-game-container').append(bubbleHtml);
                
                // Animate showing and auto-fading after 6 seconds
                const bubble = $(`.sacga-bubble-seat-${seat}`);
                bubble.fadeIn(400);
                setTimeout(() => {
                    bubble.fadeOut(400, () => bubble.remove());
                }, 6000);
            }
        },

        makeMove: function(move) {
            if (this.isSpectating) return;

            // Trigger sound effects based on actions (P5.4)
            if (move && move.action) {
                if (move.action === 'roll') {
                    this.playSynthSound('dice_roll');
                } else if (move.action === 'move' || move.action === 'slide') {
                    this.playSynthSound('piece_slide');
                } else if (move.action === 'discard' || move.action === 'play') {
                    this.playSynthSound('card_deal');
                }
            }

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

                                // Pop-in for the player's own card play, mirroring the
                                // default AI play animation (cards-base.css).
                                if (move && (move.action === 'play' || move.action === 'discard')) {
                                    const lastCard = $('.sacga-play-area .sacga-card').last();
                                    if (lastCard.length) {
                                        lastCard.addClass('sacga-card-animating sacga-card-just-played');
                                        setTimeout(() => {
                                            lastCard.removeClass('sacga-card-animating sacga-card-just-played');
                                        }, 1000);
                                    }
                                }
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
            this.pollDelay = this.config.pollInterval || 2000;
            this.scheduleNextRoomPoll();
        },

        scheduleNextRoomPoll: function() {
            if (this.pollTimer) clearTimeout(this.pollTimer);
            this.pollTimer = setTimeout(() => {
                this.pollRoom();
            }, this.pollDelay);
        },

        startGamePolling: function() {
            this.stopPolling();
            this.pollDelay = 800; // Snappy default active game interval
            this.scheduleNextGamePoll();
        },

        scheduleNextGamePoll: function() {
            if (this.pollTimer) clearTimeout(this.pollTimer);
            this.pollTimer = setTimeout(() => {
                this.pollGameState();
            }, this.pollDelay);
        },

        stopPolling: function() {
            if (this.pollTimer) {
                clearTimeout(this.pollTimer);
                this.pollTimer = null;
            }
        },

        pollRoom: function() {
            if (document.hidden) {
                this.scheduleNextRoomPoll();
                return;
            }

            this.api('room/' + this.roomCode)
                .done((response) => {
                    this.room = response;
                    if (response.status === 'active') {
                        this.stopPolling();
                        this.loadGameState();
                    } else {
                        // Check if players count changed
                        const oldPlayersCount = this.room?.players?.length || 0;
                        const newPlayersCount = response.players?.length || 0;

                        if (oldPlayersCount !== newPlayersCount) {
                            // Players changed! Reset delay
                            this.pollDelay = this.config.pollInterval || 2000;
                        } else {
                            // No change - scale up to 8000ms
                            this.pollDelay = Math.min(this.pollDelay * 1.3, 8000);
                        }

                        this.updatePlayersList();
                        this.updateStartButton();
                        this.scheduleNextRoomPoll();
                    }
                })
                .fail(() => {
                    this.pollDelay = Math.min(this.pollDelay * 1.3, 8000);
                    this.scheduleNextRoomPoll();
                });
        },

        pollGameState: function() {
            if (document.hidden) {
                this.scheduleNextGamePoll();
                return;
            }

            const etag = this.state?.etag || '';

            this.api('game/state/' + this.roomCode + '?etag=' + etag)
                .done((response) => {
                    if (response.changed && response.state) {
                        // Reset poll delay on state change!
                        this.pollDelay = 800;

                        // If animation is in progress, store as pending update
                        if (this.isAnimating) {
                            this.pendingStateUpdate = response;
                            this.scheduleNextGamePoll();
                            return;
                        }

                        this.applyStateUpdate(response);
                    } else {
                        // No change! Exponentially increase poll delay up to a max (e.g. 5000ms)
                        this.pollDelay = Math.min(this.pollDelay * 1.5, 5000);
                    }
                    this.scheduleNextGamePoll();
                })
                .fail(() => {
                    this.pollDelay = Math.min(this.pollDelay * 1.5, 5000);
                    this.scheduleNextGamePoll();
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

            // Client ID for auto-rejoin
            console.log('Client ID (for auto-rejoin):', this.clientId ? this.clientId.substring(0, 8) + '...' : 'Not set');

            console.groupEnd();
            return {
                userId: this.config.userId,
                cookieToken: this.config.guestToken,
                storageToken: storageToken,
                guestId: this.config.guestId || storageGuestId,
                activeToken: currentToken,
                clientId: this.clientId,
                status: currentToken ? 'OK' : 'ERROR'
            };
        },

        setupGameControls: function() {
            // Check if already injected
            if ($('.sacga-game-controls-wrap').length) {
                return;
            }

            // Append Emote Board & Volume Mute toggle
            const muteIcon = this.isMuted ? 'volume-off' : 'volume-alt';
            const muteText = this.isMuted ? __( 'Unmute', 'shortcode-arcade' ) : __( 'Mute', 'shortcode-arcade' );

            const controlsHtml = `
                <div class="sacga-game-controls-wrap" style="margin-top: 15px; display: flex; align-items: center; justify-content: space-between; padding: 10px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <div class="sacga-audio-toggle" title="${muteText}" style="cursor: pointer; display: inline-flex; align-items: center; padding: 6px; background: #fff; border: 1px solid #d1d5db; border-radius: 6px;">
                        <span class="dashicons dashicons-${muteIcon}" style="font-size: 18px; width: 18px; height: 18px; color: #4b5563;"></span>
                    </div>
                    <div class="sacga-emote-board" style="display: inline-flex; align-items: center; gap: 8px;">
                        <span class="sacga-emotes-label" style="font-weight: 600; font-size: 0.9em; color: #4b5563;">${__( 'Say:', 'shortcode-arcade' )}</span>
                        <button type="button" class="sacga-emote-btn sacga-btn-text" data-phrase="Good game!" style="padding: 4px 10px; border-radius: 6px; font-size: 0.85em; font-weight: 600; background: #fff; border: 1px solid #d1d5db; cursor: pointer;">👋 GG!</button>
                        <button type="button" class="sacga-emote-btn sacga-btn-text" data-phrase="Oops!" style="padding: 4px 10px; border-radius: 6px; font-size: 0.85em; font-weight: 600; background: #fff; border: 1px solid #d1d5db; cursor: pointer;">😅 Oops!</button>
                        <button type="button" class="sacga-emote-btn sacga-btn-text" data-phrase="Close one!" style="padding: 4px 10px; border-radius: 6px; font-size: 0.85em; font-weight: 600; background: #fff; border: 1px solid #d1d5db; cursor: pointer;">🔥 Close!</button>
                        <button type="button" class="sacga-emote-btn sacga-btn-text" data-phrase="Wow!" style="padding: 4px 10px; border-radius: 6px; font-size: 0.85em; font-weight: 600; background: #fff; border: 1px solid #d1d5db; cursor: pointer;">🤩 Wow!</button>
                    </div>
                </div>
            `;

            $('#sacga-game-container').append(controlsHtml);

            // Bind Mute Toggle click
            $('.sacga-audio-toggle').on('click', () => {
                this.isMuted = !this.isMuted;
                localStorage.setItem('sacga_muted', this.isMuted ? 'true' : 'false');
                
                const icon = this.isMuted ? 'volume-off' : 'volume-alt';
                const text = this.isMuted ? __( 'Unmute', 'shortcode-arcade' ) : __( 'Mute', 'shortcode-arcade' );
                
                $('.sacga-audio-toggle .dashicons')
                    .removeClass('dashicons-volume-off dashicons-volume-alt')
                    .addClass('dashicons-' + icon);
                $('.sacga-audio-toggle').attr('title', text);
                
                // Beep to acknowledge unmuting
                if (!this.isMuted) {
                    this.playSynthSound('card_deal');
                }
            });

            // Bind Emote clicks
            $('.sacga-emote-btn').on('click', (e) => {
                const phrase = $(e.currentTarget).data('phrase');
                this.sendEmote(phrase);
            });
        },

        sendEmote: function(phrase) {
            if (this.isSpectating) return;

            // Submit emote move action (agnostically handled by server apply_move)
            this.api('game/move/' + this.roomCode, 'POST', {
                move: { action: 'emote', phrase: phrase },
                etag: this.state?.etag
            }).done((response) => {
                if (response.changed && response.state) {
                    this.state = response.state;
                    this.renderGame();
                }
            });
        },

        initAudio: function() {
            // Lazy-initialize audio context on first user interaction to comply with browser autoplay policies
            if (!this.audioCtx) {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (AudioContext) {
                    this.audioCtx = new AudioContext();
                }
            }
        },

        playSynthSound: function(type) {
            this.initAudio();
            if (this.isMuted || !this.audioCtx) return;

            // Resume context if suspended
            if (this.audioCtx.state === 'suspended') {
                this.audioCtx.resume();
            }

            const ctx = this.audioCtx;
            const now = ctx.currentTime;

            if (type === 'card_deal') {
                // Synthesize soft high-pass sweep (fast decay click)
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(1200, now);
                osc.frequency.exponentialRampToValueAtTime(150, now + 0.15);
                
                gain.gain.setValueAtTime(0.15, now);
                gain.gain.exponentialRampToValueAtTime(0.01, now + 0.15);
                
                osc.connect(gain);
                gain.connect(ctx.destination);
                
                osc.start(now);
                osc.stop(now + 0.15);
            } else if (type === 'dice_roll') {
                // Synthesize quick low clicking pulses (rattle)
                for (let i = 0; i < 6; i++) {
                    const clickTime = now + (i * 0.08);
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    
                    osc.type = 'sawtooth';
                    osc.frequency.setValueAtTime(180, clickTime);
                    osc.frequency.exponentialRampToValueAtTime(40, clickTime + 0.05);
                    
                    gain.gain.setValueAtTime(0.12, clickTime);
                    gain.gain.exponentialRampToValueAtTime(0.01, clickTime + 0.05);
                    
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    
                    osc.start(clickTime);
                    osc.stop(clickTime + 0.05);
                }
            } else if (type === 'piece_slide') {
                // Synthesize smooth sliding pitch frequency glide
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                
                osc.type = 'sine';
                osc.frequency.setValueAtTime(320, now);
                osc.frequency.linearRampToValueAtTime(220, now + 0.25);
                
                gain.gain.setValueAtTime(0.15, now);
                gain.gain.exponentialRampToValueAtTime(0.01, now + 0.25);
                
                osc.connect(gain);
                gain.connect(ctx.destination);
                
                osc.start(now);
                osc.stop(now + 0.25);
            } else if (type === 'victory') {
                // Synthesize major triad fan-fare (C4, E4, G4 notes)
                const freqs = [261.63, 329.63, 392.00]; // C4, E4, G4
                freqs.forEach((freq, idx) => {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    const noteTime = now + (idx * 0.15);
                    
                    osc.type = 'triangle';
                    osc.frequency.setValueAtTime(freq, noteTime);
                    
                    gain.gain.setValueAtTime(0.0, now);
                    gain.gain.linearRampToValueAtTime(0.1, noteTime + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.01, noteTime + 0.8);
                    
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    
                    osc.start(noteTime);
                    osc.stop(noteTime + 0.85);
                });
            }
        }
    };

    // Game renderers namespace
    window.SACGAGames = window.SACGAGames || {};

    // Initialize
    $(document).ready(() => SACGA.init());

    // Expose for game renderers
    window.SACGA = SACGA;

})(jQuery);
