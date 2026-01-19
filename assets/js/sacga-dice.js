/**
 * Classic Games Arcade – Shared Dice Utilities
 * Stateless helper used by all dice games (Pig, High/Low, Greed, Yacht, etc.)
 */
(function($) {
    'use strict';

    const { __, _n, _x } = wp.i18n;

    window.SACGADice = {
        /**
         * Roll a single die (client-side preview only - server is authoritative)
         * @param {number} sides - Number of sides on the die (default: 6)
         * @returns {number} - Result from 1 to sides
         */
        roll: function(sides = 6) {
            return Math.floor(Math.random() * sides) + 1;
        },

        /**
         * Roll multiple dice (client-side preview only - server is authoritative)
         * @param {number} count - Number of dice to roll
         * @param {number} sides - Number of sides per die
         * @returns {number[]} - Array of roll results
         */
        rollMany: function(count = 1, sides = 6) {
            const results = [];
            for (let i = 0; i < count; i++) {
                results.push(this.roll(sides));
            }
            return results;
        },

        /**
         * Calculate sum of dice values
         * @param {number[]} dice - Array of dice values
         * @returns {number} - Total sum
         */
        sum: function(dice = []) {
            return dice.reduce((a, b) => a + b, 0);
        },

        /**
         * Count occurrences of each value
         * @param {number[]} dice - Array of dice values
         * @returns {Object} - Map of value to count
         */
        counts: function(dice = []) {
            return dice.reduce((acc, val) => {
                acc[val] = (acc[val] || 0) + 1;
                return acc;
            }, {});
        },

        /**
         * Check if a specific value exists in dice array
         * @param {number[]} dice - Array of dice values
         * @param {number} value - Value to check for
         * @returns {boolean}
         */
        hasValue: function(dice = [], value) {
            return dice.includes(value);
        },

        /**
         * Get max value in dice array
         * @param {number[]} dice - Array of dice values
         * @returns {number|null}
         */
        max: function(dice = []) {
            return dice.length > 0 ? Math.max(...dice) : null;
        },

        /**
         * Get min value in dice array
         * @param {number[]} dice - Array of dice values
         * @returns {number|null}
         */
        min: function(dice = []) {
            return dice.length > 0 ? Math.min(...dice) : null;
        },

        /**
         * Check if all dice show the same value
         * @param {number[]} dice - Array of dice values
         * @returns {boolean}
         */
        allSame: function(dice = []) {
            if (dice.length === 0) return false;
            return dice.every(val => val === dice[0]);
        },

        /**
         * Render dice display HTML
         * @param {number[]} dice - Array of dice values
         * @param {Object} options - Rendering options
         * @returns {string} - HTML string
         */
        renderDice: function(dice = [], options = {}) {
            const defaults = {
                size: 'normal',  // 'small', 'normal', 'large'
                locked: [],      // Indices of locked dice
                selectable: [],  // Indices of selectable dice
                selected: [],    // Indices of currently selected dice
                disabled: false  // Whether all dice are disabled
            };
            const opts = { ...defaults, ...options };

            const diceHtml = dice.map((val, i) => {
                const classes = ['sacga-die'];
                if (opts.locked.includes(i)) classes.push('locked');
                if (opts.selected.includes(i)) classes.push('selected');
                if (opts.selectable.includes(i)) classes.push('selectable');
                if (opts.disabled) classes.push('disabled');

                return `
                    <div class="${classes.join(' ')}" data-index="${i}" data-value="${val}">
                        <span class="die-face">${val}</span>
                    </div>
                `;
            }).join('');

            return `
                <div class="sacga-dice-row sacga-dice-${opts.size}">
                    ${diceHtml}
                </div>
            `;
        },

        /**
         * Bind click handlers for dice selection
         * @param {jQuery|Element} container - Container element
         * @param {Function} callback - Called with (index, value) when die clicked
         */
        bindDiceClicks: function(container, callback) {
            $(container).on('click', '.sacga-die.selectable', function() {
                const $die = $(this);
                const index = parseInt($die.data('index'), 10);
                const value = parseInt($die.data('value'), 10);
                callback(index, value);
            });
        },

        /**
         * Unbind dice click handlers
         * @param {jQuery|Element} container - Container element
         */
        unbindDiceClicks: function(container) {
            $(container).off('click', '.sacga-die.selectable');
        }
    };

})(jQuery);
