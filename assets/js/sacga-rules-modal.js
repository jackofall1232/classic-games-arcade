/**
 * Classic Games Arcade - Rules Modal
 * Accessible, lightweight modal for in-game quick reference
 * Version: 1.0.0
 *
 * Features:
 * - Focus trap for accessibility
 * - ESC key closes modal
 * - Click outside closes modal
 * - Prevents background scroll
 * - ARIA compliant
 */

(function($) {
    'use strict';

    /**
     * Rules Modal Controller
     */
    const SACGARulesModal = {
        // State
        isOpen: false,
        $overlay: null,
        $modal: null,
        $closeBtn: null,
        previouslyFocused: null,
        focusableElements: null,

        /**
         * Initialize the modal system
         */
        init: function() {
            this.createModal();
            this.bindEvents();
        },

        /**
         * Create modal DOM structure
         */
        createModal: function() {
            // Check if modal already exists
            if ($('#sacga-rules-modal-overlay').length) {
                this.$overlay = $('#sacga-rules-modal-overlay');
                this.$modal = this.$overlay.find('.sacga-rules-modal');
                this.$closeBtn = this.$overlay.find('.sacga-rules-close');
                return;
            }

            const closeLabel = (typeof sacgaRulesConfig !== 'undefined' && sacgaRulesConfig.closeLabel)
                ? sacgaRulesConfig.closeLabel
                : 'Close';

            const modalHTML = `
                <div id="sacga-rules-modal-overlay" class="sacga-rules-overlay" role="dialog" aria-modal="true" aria-labelledby="sacga-rules-modal-title">
                    <div class="sacga-rules-modal">
                        <header class="sacga-rules-modal-header">
                            <h2 id="sacga-rules-modal-title" class="sacga-rules-modal-title"></h2>
                            <button type="button" class="sacga-rules-close" aria-label="${closeLabel}">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>
                        </header>
                        <div class="sacga-rules-modal-body" tabindex="0"></div>
                    </div>
                </div>
            `;

            $('body').append(modalHTML);

            this.$overlay = $('#sacga-rules-modal-overlay');
            this.$modal = this.$overlay.find('.sacga-rules-modal');
            this.$closeBtn = this.$overlay.find('.sacga-rules-close');
        },

        /**
         * Bind all event listeners
         */
        bindEvents: function() {
            const self = this;

            // Click on rules button
            $(document).on('click', '.sacga-rules-btn', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const gameId = $btn.data('game-id') || self.getGameIdFromContainer($btn);
                const gameName = $btn.data('game-name') || '';

                if (gameId) {
                    self.open(gameId, gameName);
                }
            });

            // Close button
            this.$closeBtn.on('click', function(e) {
                e.preventDefault();
                self.close();
            });

            // Click on overlay (outside modal)
            this.$overlay.on('click', function(e) {
                if (e.target === this) {
                    self.close();
                }
            });

            // Keyboard events
            $(document).on('keydown', function(e) {
                if (!self.isOpen) return;

                // ESC key closes modal
                if (e.key === 'Escape' || e.keyCode === 27) {
                    e.preventDefault();
                    self.close();
                    return;
                }

                // Tab key for focus trap
                if (e.key === 'Tab' || e.keyCode === 9) {
                    self.handleTabKey(e);
                }
            });
        },

        /**
         * Get game ID from container
         */
        getGameIdFromContainer: function($element) {
            const $container = $element.closest('[data-game-id]');
            return $container.length ? $container.data('game-id') : null;
        },

        /**
         * Open the modal
         */
        open: function(gameId, gameName) {
            const self = this;

            // Store currently focused element
            this.previouslyFocused = document.activeElement;

            // Set title
            const title = gameName
                ? gameName + ' Rules'
                : 'Game Rules';
            this.$overlay.find('.sacga-rules-modal-title').text(title);

            // Get rules content from embedded data or make request
            const rulesHtml = this.getRulesContent(gameId);
            this.$overlay.find('.sacga-rules-modal-body').html(rulesHtml);

            // Show modal
            this.$overlay.addClass('sacga-rules-overlay-active');
            $('body').addClass('sacga-rules-modal-open');
            this.isOpen = true;

            // Update focusable elements
            this.updateFocusableElements();

            // Focus first element (close button)
            setTimeout(function() {
                self.$closeBtn.focus();
            }, 100);

            // Trigger custom event
            $(document).trigger('sacga:rules:opened', { gameId: gameId });
        },

        /**
         * Close the modal
         */
        close: function() {
            this.$overlay.removeClass('sacga-rules-overlay-active');
            $('body').removeClass('sacga-rules-modal-open');
            this.isOpen = false;

            // Restore focus
            if (this.previouslyFocused) {
                this.previouslyFocused.focus();
            }

            // Trigger custom event
            $(document).trigger('sacga:rules:closed');
        },

        /**
         * Get rules content
         * First checks for embedded rules data, then uses inline content
         */
        getRulesContent: function(gameId) {
            // Check for pre-rendered rules in a data attribute or hidden element
            const $rulesData = $('[data-sacga-rules-content="' + gameId + '"]');
            if ($rulesData.length) {
                return $rulesData.html();
            }

            // Check for rules embedded in the page
            const $embeddedRules = $('#sacga-rules-data-' + gameId);
            if ($embeddedRules.length) {
                return $embeddedRules.html();
            }

            // Return placeholder - rules should be pre-rendered by PHP
            return '<p>Loading rules...</p>';
        },

        /**
         * Update list of focusable elements for focus trap
         */
        updateFocusableElements: function() {
            const focusableSelectors = [
                'button:not([disabled])',
                'a[href]',
                'input:not([disabled])',
                'select:not([disabled])',
                'textarea:not([disabled])',
                '[tabindex]:not([tabindex="-1"])'
            ].join(', ');

            this.focusableElements = this.$modal.find(focusableSelectors).filter(':visible');
        },

        /**
         * Handle tab key for focus trap
         */
        handleTabKey: function(e) {
            if (!this.focusableElements || !this.focusableElements.length) {
                e.preventDefault();
                return;
            }

            const firstElement = this.focusableElements.first()[0];
            const lastElement = this.focusableElements.last()[0];
            const activeElement = document.activeElement;

            // Shift+Tab from first element -> go to last
            if (e.shiftKey && activeElement === firstElement) {
                e.preventDefault();
                lastElement.focus();
                return;
            }

            // Tab from last element -> go to first
            if (!e.shiftKey && activeElement === lastElement) {
                e.preventDefault();
                firstElement.focus();
                return;
            }

            // If focus is somehow outside modal, bring it back
            if (!this.$modal[0].contains(activeElement)) {
                e.preventDefault();
                firstElement.focus();
            }
        },

        /**
         * Update modal content (for external use)
         */
        setContent: function(html, title) {
            if (title) {
                this.$overlay.find('.sacga-rules-modal-title').text(title);
            }
            this.$overlay.find('.sacga-rules-modal-body').html(html);
            this.updateFocusableElements();
        },

        /**
         * Check if modal is currently open
         */
        isModalOpen: function() {
            return this.isOpen;
        }
    };

    /**
     * Master Rules Accordion Controller
     */
    const SACGARulesAccordion = {
        /**
         * Initialize accordion controls
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event listeners
         */
        bindEvents: function() {
            // Expand All button
            $(document).on('click', '.sacga-rules-expand-all', function(e) {
                e.preventDefault();
                $('.sacga-rules-panel').attr('open', true);
            });

            // Collapse All button
            $(document).on('click', '.sacga-rules-collapse-all', function(e) {
                e.preventDefault();
                $('.sacga-rules-panel').removeAttr('open');
            });

            // Smooth scroll to panel when clicking nav link (if nav exists)
            $(document).on('click', '.sacga-rules-nav-link', function(e) {
                const targetId = $(this).attr('href');
                const $target = $(targetId);
                if ($target.length) {
                    e.preventDefault();
                    $target.attr('open', true);
                    $('html, body').animate({
                        scrollTop: $target.offset().top - 20
                    }, 300);
                }
            });
        }
    };

    /**
     * Initialize on DOM ready
     */
    $(document).ready(function() {
        SACGARulesModal.init();
        SACGARulesAccordion.init();
    });

    /**
     * Expose to global scope for external access
     */
    window.SACGARulesModal = SACGARulesModal;
    window.SACGARulesAccordion = SACGARulesAccordion;

})(jQuery);
