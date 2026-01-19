/**
 * Classic Games Arcade - Admin JavaScript
 * Copy to clipboard functionality and UI interactions
 */

(function() {
    'use strict';

    /**
     * Show a toast notification
     * @param {string} message - The message to display
     * @param {string} type - The type of toast ('success' or 'error')
     */
    function showToast(message, type) {
        // Remove any existing toasts
        var existingToast = document.querySelector('.sacga-toast');
        if (existingToast) {
            existingToast.remove();
        }

        // Create toast element
        var toast = document.createElement('div');
        toast.className = 'sacga-toast' + (type === 'success' ? ' sacga-success' : '');
        toast.textContent = message;
        document.body.appendChild(toast);

        // Remove toast after 2 seconds
        setTimeout(function() {
            toast.style.animation = 'sacga-toast-out 0.3s ease forwards';
            setTimeout(function() {
                toast.remove();
            }, 300);
        }, 2000);
    }

    /**
     * Copy text to clipboard
     * @param {string} text - The text to copy
     * @returns {Promise<boolean>} - Whether the copy was successful
     */
    function copyToClipboard(text) {
        // Modern clipboard API
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text)
                .then(function() {
                    return true;
                })
                .catch(function() {
                    return fallbackCopy(text);
                });
        }

        // Fallback for older browsers
        return Promise.resolve(fallbackCopy(text));
    }

    /**
     * Fallback copy method using textarea
     * @param {string} text - The text to copy
     * @returns {boolean} - Whether the copy was successful
     */
    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '-9999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();

        var success = false;
        try {
            success = document.execCommand('copy');
        } catch (err) {
            console.error('Failed to copy:', err);
        }

        document.body.removeChild(textarea);
        return success;
    }

    /**
     * Handle copy button click
     * @param {Event} event - The click event
     */
    function handleCopyClick(event) {
        var button = event.currentTarget;
        var shortcode = button.getAttribute('data-shortcode');

        if (!shortcode) {
            return;
        }

        copyToClipboard(shortcode).then(function(success) {
            if (success) {
                // Add visual feedback
                button.classList.add('sacga-copied');

                // Change icon temporarily
                var icon = button.querySelector('.dashicons');
                if (icon) {
                    icon.classList.remove('dashicons-clipboard');
                    icon.classList.add('dashicons-yes');
                }

                // Show toast
                showToast('Shortcode copied!', 'success');

                // Reset button after 1.5 seconds
                setTimeout(function() {
                    button.classList.remove('sacga-copied');
                    if (icon) {
                        icon.classList.remove('dashicons-yes');
                        icon.classList.add('dashicons-clipboard');
                    }
                }, 1500);
            } else {
                showToast('Failed to copy shortcode', 'error');
            }
        });
    }

    /**
     * Initialize copy buttons
     */
    function initCopyButtons() {
        var copyButtons = document.querySelectorAll('.sacga-copy-btn');

        copyButtons.forEach(function(button) {
            button.addEventListener('click', handleCopyClick);
        });
    }

    /**
     * Initialize when DOM is ready
     */
    function init() {
        initCopyButtons();
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
