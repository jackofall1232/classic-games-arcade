/**
 * Classic Games Arcade - Available Rooms Auto-Refresh
 * Provides AJAX-based refresh for the rooms shortcode
 */

(function($) {
    'use strict';

    /**
     * Available Rooms Manager
     */
    var SACGARooms = {
        containers: [],
        refreshInterval: null,

        /**
         * Initialize the rooms manager
         */
        init: function() {
            var self = this;

            // Find all room containers with refresh enabled
            $('.sacga-available-rooms[data-refresh]').each(function() {
                var $container = $(this);
                var refreshRate = parseInt($container.data('refresh'), 10);

                if (refreshRate > 0) {
                    self.containers.push({
                        $el: $container,
                        game: $container.data('game') || '',
                        limit: parseInt($container.data('limit'), 10) || 10,
                        refreshRate: refreshRate
                    });
                }
            });

            // Start refresh if we have containers
            if (this.containers.length > 0) {
                this.startRefresh();
            }
        },

        /**
         * Start the refresh interval
         */
        startRefresh: function() {
            var self = this;

            // Use the shortest refresh interval
            var minInterval = Math.min.apply(null, this.containers.map(function(c) {
                return c.refreshRate;
            }));

            this.refreshInterval = setInterval(function() {
                self.refreshAll();
            }, minInterval * 1000);
        },

        /**
         * Refresh all containers
         */
        refreshAll: function() {
            var self = this;

            this.containers.forEach(function(container) {
                self.refreshContainer(container);
            });
        },

        /**
         * Refresh a single container
         */
        refreshContainer: function(container) {
            var self = this;

            // Add loading state
            container.$el.addClass('sacga-loading');

            $.ajax({
                url: sacgaRoomsConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sacga_refresh_rooms',
                    nonce: sacgaRoomsConfig.nonce,
                    game: container.game,
                    limit: container.limit
                },
                success: function(response) {
                    if (response.success) {
                        self.updateContainer(container, response.data);
                    }
                },
                error: function() {
                    // Silently fail - rooms will just stay as they are
                    console.warn('Failed to refresh rooms');
                },
                complete: function() {
                    container.$el.removeClass('sacga-loading');
                }
            });
        },

        /**
         * Update container with new rooms data
         */
        updateContainer: function(container, rooms) {
            var $container = container.$el;
            var currentPage = window.location.href;

            if (rooms.length === 0) {
                // Show empty state
                $container.html(this.renderEmptyState());
            } else {
                // Render rooms grid
                $container.html(this.renderRoomsGrid(rooms, currentPage));
            }

            // Re-add refresh indicator if needed
            if (container.refreshRate > 0) {
                $container.append(this.renderRefreshIndicator(container.refreshRate));
            }
        },

        /**
         * Render empty state HTML
         */
        renderEmptyState: function() {
            return '<div class="sacga-rooms-empty">' +
                '<div class="sacga-rooms-empty-icon">' +
                '<span class="dashicons dashicons-groups"></span>' +
                '</div>' +
                '<p class="sacga-rooms-empty-message">' + this.translate('No rooms available right now.') + '</p>' +
                '<p class="sacga-rooms-empty-hint">' + this.translate('Start a new game to create a room!') + '</p>' +
                '</div>';
        },

        /**
         * Render rooms grid HTML
         */
        renderRoomsGrid: function(rooms, currentPage) {
            var self = this;
            var html = '<div class="sacga-rooms-grid">';

            rooms.forEach(function(room) {
                html += self.renderRoomCard(room, currentPage);
            });

            html += '</div>';
            return html;
        },

        /**
         * Render a single room card
         */
        renderRoomCard: function(room, currentPage) {
            var seatsAvailable = room.max_players - room.player_count;
            var joinUrl = this.buildJoinUrl(currentPage, room.game_id, room.room_code);
            var seatsClass = seatsAvailable <= 1 ? 'sacga-room-seats-low' : '';

            return '<div class="sacga-room-card sacga-room-status-' + room.status + '">' +
                '<div class="sacga-room-header">' +
                '<span class="sacga-room-game">' + this.escapeHtml(room.game_name) + '</span>' +
                (room.game_type ? '<span class="sacga-room-type sacga-room-type-' + room.game_type + '">' + this.capitalize(room.game_type) + '</span>' : '') +
                '</div>' +
                '<div class="sacga-room-body">' +
                '<div class="sacga-room-code">' +
                '<span class="sacga-room-code-label">' + this.translate('Room Code') + '</span>' +
                '<span class="sacga-room-code-value">' + this.escapeHtml(room.room_code) + '</span>' +
                '</div>' +
                '<div class="sacga-room-info">' +
                '<div class="sacga-room-players">' +
                '<span class="dashicons dashicons-groups"></span>' +
                '<span class="sacga-room-players-count">' + room.player_count + ' / ' + room.max_players + ' ' + this.translate('players') + '</span>' +
                '</div>' +
                '<div class="sacga-room-seats ' + seatsClass + '">' +
                '<span class="dashicons dashicons-admin-users"></span>' +
                '<span class="sacga-room-seats-count">' + seatsAvailable + ' ' + (seatsAvailable === 1 ? this.translate('seat open') : this.translate('seats open')) + '</span>' +
                '</div>' +
                '<div class="sacga-room-created">' +
                '<span class="dashicons dashicons-clock"></span>' +
                '<span class="sacga-room-created-time">' + room.created_ago + ' ' + this.translate('ago') + '</span>' +
                '</div>' +
                '</div>' +
                '<div class="sacga-room-status">' +
                this.renderStatusBadge(room.status) +
                '</div>' +
                '</div>' +
                '<div class="sacga-room-footer">' +
                '<a href="' + joinUrl + '" class="sacga-btn sacga-btn-join">' +
                '<span class="dashicons dashicons-migrate"></span>' +
                this.translate('Join Room') +
                '</a>' +
                '</div>' +
                '</div>';
        },

        /**
         * Render status badge
         */
        renderStatusBadge: function(status) {
            if (status === 'lobby') {
                return '<span class="sacga-status-badge sacga-status-lobby">' +
                    '<span class="dashicons dashicons-clock"></span>' +
                    this.translate('Waiting') +
                    '</span>';
            } else {
                return '<span class="sacga-status-badge sacga-status-active">' +
                    '<span class="dashicons dashicons-controls-play"></span>' +
                    this.translate('In Progress') +
                    '</span>';
            }
        },

        /**
         * Render refresh indicator
         */
        renderRefreshIndicator: function(seconds) {
            return '<div class="sacga-rooms-refresh-indicator">' +
                '<span class="dashicons dashicons-update"></span>' +
                this.translate('Auto-refreshing every') + ' ' + seconds + ' ' + this.translate('seconds') +
                '</div>';
        },

        /**
         * Build join URL
         */
        buildJoinUrl: function(baseUrl, gameId, roomCode) {
            var url = new URL(baseUrl);
            url.searchParams.set('game', gameId);
            url.searchParams.set('room', roomCode);
            return url.toString();
        },

        /**
         * Simple translation helper (uses wp.i18n if available)
         */
        translate: function(text) {
            if (typeof wp !== 'undefined' && wp.i18n && wp.i18n.__) {
                return wp.i18n.__(text, 'shortcode-arcade');
            }
            return text;
        },

        /**
         * Escape HTML entities
         */
        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Capitalize first letter
         */
        capitalize: function(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        SACGARooms.init();
    });

})(jQuery);
