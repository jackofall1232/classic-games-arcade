/**
 * Overcut Dice Game Renderer
 *
 * A strategic dice game where you bid on your roll total.
 * Features animated dice reveals, AI transparency, and action feed.
 */
(function($) {
    'use strict';

    const { __, _n, sprintf } = wp.i18n;

    // Timing constants (ms) - per spec timing_policy
    const TIMING = {
        MESSAGE_DELAY: 1500,         // Every narrated line stays visible 1500ms
        DICE_REVEAL: 250,            // dice_reveal_interval_ms
        POST_DICE: 800,              // post_dice_pause_ms
        SCOREBOARD_TALLY: 1500,      // scoreboard_tally_delay_ms
        AI_STEP: 1500,               // ai_step_delay_ms
        MIN_ANIMATION_STEP: 1500,    // minimum_animation_step_ms
    };

    const OvercutRenderer = {
        mySeat: null,
        onMove: null,
        state: null,
        currentBid: 21,
        isAnimating: false,
        actionFeed: [],
        maxFeedItems: 2,  // Keep minimal - only last 2 actions to prevent container growth
        lastAnimatedRound: 0,        // Track which round we've animated
        lastAnimatedRolloff: null,   // Track rolloff state we've animated
        lastStagedAIRound: 0,        // Track which AI rounds we've staged intro for

        // UI step tracking for gated flow
        uiStep: null,                // Current UI substep
        rolloffComplete: false,      // Rolloff resolution shown, waiting for Begin Game
        scoringComplete: false,      // Scoring shown, waiting for Continue
        waitingForUserGate: false,   // True when a gate button is shown

        /**
         * Validate result object against scoring invariants (dev mode only)
         */
        validateResult: function(result) {
            // Only validate in dev mode
            if (!window.console || !console.warn) return;

            const bid = result.bid;
            const rollTotal = result.roll_total;
            const resultType = result.result_type;
            const playerScore = result.player_score;
            const opponentScore = result.opponent_score;

            let warnings = [];

            // Check result_type invariants
            if (resultType === 'exact_hit') {
                if (rollTotal !== bid) {
                    warnings.push('exact_hit: roll_total should equal bid');
                }
                if (playerScore !== bid * 2) {
                    warnings.push('exact_hit: player_score should be bid × 2 (expected ' + (bid * 2) + ', got ' + playerScore + ')');
                }
                if (opponentScore !== 0) {
                    warnings.push('exact_hit: opponent_score should be 0 (got ' + opponentScore + ')');
                }
            } else if (resultType === 'undercut') {
                if (rollTotal >= bid) {
                    warnings.push('undercut: roll_total should be less than bid');
                }
                if (playerScore !== 0) {
                    warnings.push('undercut: player_score should be 0 (got ' + playerScore + ')');
                }
                if (opponentScore !== rollTotal) {
                    warnings.push('undercut: opponent_score should equal roll_total (expected ' + rollTotal + ', got ' + opponentScore + ')');
                }
            } else if (resultType === 'overcut') {
                if (rollTotal <= bid) {
                    warnings.push('overcut: roll_total should be greater than bid');
                }
                if (playerScore !== bid) {
                    warnings.push('overcut: player_score should equal bid (expected ' + bid + ', got ' + playerScore + ')');
                }
                if (opponentScore !== rollTotal - bid) {
                    warnings.push('overcut: opponent_score should equal roll_total - bid (expected ' + (rollTotal - bid) + ', got ' + opponentScore + ')');
                }
            } else if (resultType === 'null_roll') {
                if (playerScore !== 0) {
                    warnings.push('null_roll: player_score should be 0 (got ' + playerScore + ')');
                }
                if (opponentScore !== 0) {
                    warnings.push('null_roll: opponent_score should be 0 (got ' + opponentScore + ')');
                }
            } else {
                warnings.push('Unknown result_type: ' + resultType);
            }

            // Log all warnings
            if (warnings.length > 0) {
                console.warn('[Overcut] Scoring invariant violations detected:');
                warnings.forEach(function(warning) {
                    console.warn('  - ' + warning);
                });
                console.warn('Result object:', result);
            }
        },

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            // Validate result object if present (dev mode only)
            if (this.state.last_result) {
                this.validateResult(this.state.last_result);
            }

            // Don't interrupt ongoing animation
            if (this.isAnimating) {
                return;
            }

            // GATE: If rolloff complete but user hasn't clicked Begin Game
            if (this.state.phase !== 'rolloff' && this.rolloffComplete && this.waitingForUserGate) {
                // Stay on rolloff completion screen with Begin Game button
                return;
            }

            // GATE: If scoring complete but user hasn't clicked Continue
            if (this.scoringComplete && this.waitingForUserGate) {
                // Stay on scoring screen with Continue button
                return;
            }

            // Check if we need to animate AI turn (full staging)
            if (this.shouldAnimateAITurn()) {
                this.animateAITurn();
                return;
            }

            // Check if we need to animate dice reveal (player turns)
            if (this.shouldAnimateDiceReveal()) {
                this.animateDiceReveal();
                return;
            }

            // Check if we need to animate rolloff
            if (this.shouldAnimateRolloff()) {
                this.animateRolloff();
                return;
            }

            this.renderFullUI();
        },

        shouldAnimateAITurn: function() {
            // Check if there's a new AI result we haven't staged the intro for
            const result = this.state.last_result;
            if (!result) return false;

            // Only for opponent (AI) results
            if (result.player === this.mySeat) return false;

            // Check if this AI round needs staging
            if (result.round > this.lastStagedAIRound) {
                return true;
            }

            return false;
        },

        shouldAnimateDiceReveal: function() {
            // Animate if there's a new result we haven't animated yet
            const result = this.state.last_result;
            if (!result) return false;

            // Check if this is a new round we haven't animated
            if (result.round > this.lastAnimatedRound) {
                return true;
            }

            return false;
        },

        shouldAnimateRolloff: function() {
            if (this.state.phase !== 'rolloff') return false;

            const rolloff = this.state.rolloff;

            // Check each seat for new dice
            for (let seat = 0; seat < 2; seat++) {
                const dice = rolloff.dice[seat];
                if (dice && dice.length > 0) {
                    // Check if we've already animated this
                    const key = seat + ':' + dice.join(',');
                    if (this.lastAnimatedRolloff !== key) {
                        return true;
                    }
                }
            }

            return false;
        },

        /**
         * Animate a full AI turn with staged phases per spec:
         * 1. bot_turn_start - "Alpha's turn" indicator (500ms)
         * 2. bot_thinking - "Alpha is thinking..." (700ms)
         * 3. bot_bidding - "Alpha bids: X" (700ms)
         * 4. bot_rolling_announcement - "Alpha is rolling..." (500ms)
         * 5. dice_reveal_animation - one-by-one
         * 6. scoring_reveal (900ms)
         * 7. scoreboard_update (500ms)
         * 8. advance_to_next_turn - "Your turn" (500ms)
         */
        animateAITurn: function() {
            const self = this;
            const result = this.state.last_result;

            this.isAnimating = true;
            this.lastStagedAIRound = result.round;

            const aiName = this.state.players[result.player]?.name || __('Opponent', 'shortcode-arcade');

            // Step 1: bot_turn_start - Show "Alpha's turn" indicator
            this.renderAITurnStart(result.round, aiName);

            setTimeout(function() {
                // Step 2: bot_thinking - Show thinking indicator
                self.renderAIThinking(result.round, aiName);

                setTimeout(function() {
                    // Step 3: bot_bidding - Reveal bid
                    self.addToFeed(sprintf(__('%s is thinking...', 'shortcode-arcade'), self.escapeHtml(aiName)));
                    self.renderAIBidReveal(result.round, aiName, result.bid);

                    setTimeout(function() {
                        // Step 4: bot_rolling_announcement
                        self.addToFeed(sprintf(__('%s bid %d', 'shortcode-arcade'), self.escapeHtml(aiName), result.bid));
                        self.renderAIRolling(result.round, aiName, result.bid);

                        setTimeout(function() {
                            // Step 5: dice_reveal_animation - hand off to animateDiceReveal
                            // Note: animateDiceReveal will handle steps 6-8
                            self.animateDiceReveal();
                        }, TIMING.AI_STEP);
                    }, TIMING.AI_STEP);
                }, TIMING.AI_STEP);
            }, TIMING.MESSAGE_DELAY);
        },

        renderAITurnStart: function(round, aiName) {
            let html = '<div class="overcut-game">';
            html += this.renderScoreboard(true);
            html += '<div class="overcut-ai-turn">';
            html += '<h3 class="phase-title">' + sprintf(__('Round %d', 'shortcode-arcade'), round) + '</h3>';

            html += '<div class="ai-stage turn-start">';
            html += '<div class="turn-indicator opponent-turn">';
            html += '<span class="turn-icon">🤖</span>';
            html += '<span class="turn-text">' + sprintf(__("%s's Turn", 'shortcode-arcade'), this.escapeHtml(aiName)) + '</span>';
            html += '</div>';
            html += '</div>';

            html += '</div>';
            html += this.renderActionFeed();
            html += '</div>';

            $('#sacga-game-board').html(html);
        },

        renderAIThinking: function(round, aiName) {
            let html = '<div class="overcut-game">';
            html += this.renderScoreboard(true);
            html += '<div class="overcut-ai-turn">';
            html += '<h3 class="phase-title">' + sprintf(__('Round %d', 'shortcode-arcade'), round) + '</h3>';

            html += '<div class="ai-stage thinking">';
            html += '<div class="ai-avatar">';
            html += '<span class="ai-icon">🤖</span>';
            html += '</div>';
            html += '<div class="thinking-indicator large">';
            html += '<div class="thinking-dots"><span></span><span></span><span></span></div>';
            html += '<span class="thinking-text">' + sprintf(__('%s is thinking...', 'shortcode-arcade'), this.escapeHtml(aiName)) + '</span>';
            html += '</div>';
            html += '</div>';

            html += '</div>';
            html += this.renderActionFeed();
            html += '</div>';

            $('#sacga-game-board').html(html);
        },

        renderAIBidReveal: function(round, aiName, bid) {
            let html = '<div class="overcut-game">';
            html += this.renderScoreboard(true);
            html += '<div class="overcut-ai-turn">';
            html += '<h3 class="phase-title">' + sprintf(__('Round %d', 'shortcode-arcade'), round) + '</h3>';

            html += '<div class="ai-stage bid-reveal">';
            html += '<div class="ai-avatar">';
            html += '<span class="ai-icon">🤖</span>';
            html += '</div>';
            html += '<div class="ai-bid-announcement">';
            html += '<span class="ai-name">' + this.escapeHtml(aiName) + '</span>';
            html += '<span class="bid-label">' + __('bids', 'shortcode-arcade') + '</span>';
            html += '<span class="bid-value pop-in">' + bid + '</span>';
            html += '</div>';
            html += '</div>';

            html += '</div>';
            html += this.renderActionFeed();
            html += '</div>';

            $('#sacga-game-board').html(html);
        },

        renderAIRolling: function(round, aiName, bid) {
            let html = '<div class="overcut-game">';
            html += this.renderScoreboard(true);
            html += '<div class="overcut-ai-turn">';
            html += '<h3 class="phase-title">' + sprintf(__('Round %d', 'shortcode-arcade'), round) + '</h3>';

            // Show bid
            html += '<div class="current-bid-display">';
            html += '<span>' + sprintf(__('%s bid:', 'shortcode-arcade'), this.escapeHtml(aiName)) + '</span>';
            html += '<span class="bid-amount">' + bid + '</span>';
            html += '</div>';

            // Empty dice tray
            html += '<div class="dice-tray">';
            for (let i = 0; i < 6; i++) {
                html += '<div class="dice-slot empty"></div>';
            }
            html += '</div>';

            // Rolling indicator
            html += '<div class="ai-rolling-indicator">';
            html += '<div class="waiting-spinner"></div>';
            html += '<span>' + sprintf(__('%s is rolling...', 'shortcode-arcade'), this.escapeHtml(aiName)) + '</span>';
            html += '</div>';

            html += '</div>';
            html += this.renderActionFeed();
            html += '</div>';

            $('#sacga-game-board').html(html);
        },

        animateDiceReveal: function() {
            const result = this.state.last_result;
            this.isAnimating = true;
            this.lastAnimatedRound = result.round;

            const wasMe = result.player === this.mySeat;
            const playerName = wasMe ? __('You', 'shortcode-arcade') : (this.state.players[result.player]?.name || __('Opponent', 'shortcode-arcade'));

            // Add bid to action feed
            this.addToFeed(sprintf(__('%s bid %d', 'shortcode-arcade'), this.escapeHtml(playerName), result.bid));

            this.renderDiceRevealUI(result, wasMe, playerName);
        },

        renderDiceRevealUI: function(result, wasMe, playerName) {
            const self = this;

            let html = '<div class="overcut-game">';
            html += this.renderScoreboard(true); // Use scores before this round
            html += '<div class="overcut-scoring animating">';
            html += '<h3 class="phase-title">' + sprintf(__('Round %d', 'shortcode-arcade'), result.round) + '</h3>';

            // Show who is rolling
            html += '<div class="rolling-announcement">';
            html += '<span class="roller-name">' + this.escapeHtml(playerName) + '</span>';
            html += '<span class="rolling-label">' + __('rolled...', 'shortcode-arcade') + '</span>';
            html += '</div>';

            // Bid display
            html += '<div class="current-bid-display animated">';
            html += '<span>' + __('Bid:', 'shortcode-arcade') + '</span>';
            html += '<span class="bid-amount">' + result.bid + '</span>';
            html += '</div>';

            // Dice tray - starts empty
            html += '<div class="dice-tray" id="overcut-dice-tray">';
            for (let i = 0; i < 6; i++) {
                html += '<div class="dice-slot empty" data-slot="' + i + '"></div>';
            }
            html += '</div>';

            // Running total
            html += '<div class="running-total" id="overcut-running-total">';
            html += '<span class="total-label">' + __('Total:', 'shortcode-arcade') + '</span>';
            html += '<span class="total-value">0</span>';
            html += '</div>';

            // Result area (hidden initially)
            html += '<div class="result-area" id="overcut-result-area" style="display:none;"></div>';

            html += '</div>'; // .overcut-scoring
            html += this.renderActionFeed();
            html += '</div>'; // .overcut-game

            $('#sacga-game-board').html(html);

            // Small delay to ensure DOM is ready, then start animation
            setTimeout(function() {
                self.revealDiceSequentially(result.dice, function(total) {
                    self.addToFeed(sprintf(__('Rolled %d', 'shortcode-arcade'), total));
                    self.showScoringResult(result, wasMe);
                });
            }, 100);
        },

        revealDiceSequentially: function(dice, onComplete) {
            const self = this;
            let currentIndex = 0;
            let runningTotal = 0;

            function revealNext() {
                if (currentIndex >= dice.length) {
                    // All dice revealed - pause then show result
                    setTimeout(function() {
                        onComplete(runningTotal);
                    }, TIMING.POST_DICE);
                    return;
                }

                const dieValue = dice[currentIndex];
                runningTotal += dieValue;

                const $slot = $('#overcut-dice-tray .dice-slot[data-slot="' + currentIndex + '"]');

                if ($slot.length === 0) {
                    // DOM not ready, skip animation
                    console.warn('Dice slot not found, skipping animation');
                    currentIndex++;
                    setTimeout(revealNext, 50);
                    return;
                }

                $slot.removeClass('empty').addClass('revealing');

                // Create die element with Unicode dice face
                const dieFace = self.getDieFace(dieValue);
                const dieHtml = '<div class="sacga-die"><span class="die-face">' + dieFace + '</span></div>';
                $slot.html(dieHtml);

                // Update running total
                $('#overcut-running-total .total-value').text(runningTotal);

                // Shake effect
                $slot.addClass('shake');
                setTimeout(function() {
                    $slot.removeClass('shake revealing').addClass('revealed');
                }, 150);

                currentIndex++;
                setTimeout(revealNext, TIMING.DICE_REVEAL);
            }

            revealNext();
        },

        getDieFace: function(value) {
            // Unicode dice faces: ⚀ ⚁ ⚂ ⚃ ⚄ ⚅
            const faces = ['', '\u2680', '\u2681', '\u2682', '\u2683', '\u2684', '\u2685'];
            return faces[value] || value.toString();
        },

        showScoringResult: function(result, wasMe) {
            const self = this;

            // Determine result type and text
            let resultClass = 'result-' + result.result_type;
            let resultText = '';

            switch (result.result_type) {
                case 'exact_hit':
                    resultText = __('EXACT HIT!', 'shortcode-arcade');
                    break;
                case 'overcut':
                    resultText = __('Overcut', 'shortcode-arcade');
                    break;
                case 'undercut':
                    resultText = __('Undercut', 'shortcode-arcade');
                    break;
                case 'null_roll':
                    resultText = __('NULL ROLL!', 'shortcode-arcade');
                    break;
            }

            let html = '';

            // Result banner
            html += '<div class="result-banner ' + resultClass + ' animate-in">';
            html += '<span class="result-text">' + resultText + '</span>';
            html += '</div>';

            // Comparison
            html += '<div class="scoring-comparison animate-in">';
            html += '<div class="comparison-item">';
            html += '<span class="comparison-label">' + __('Bid', 'shortcode-arcade') + '</span>';
            html += '<span class="comparison-value">' + result.bid + '</span>';
            html += '</div>';
            html += '<div class="comparison-vs">vs</div>';
            html += '<div class="comparison-item">';
            html += '<span class="comparison-label">' + __('Rolled', 'shortcode-arcade') + '</span>';
            html += '<span class="comparison-value">' + result.roll_total + '</span>';
            html += '</div>';
            html += '</div>';

            // Points awarded
            if (result.result_type === 'null_roll') {
                html += '<div class="scoring-points null-roll animate-in">';
                html += '<p>' + __('Both players would win - no points awarded!', 'shortcode-arcade') + '</p>';
                html += '</div>';
                this.addToFeed(__('Null roll - no points!', 'shortcode-arcade'));
            } else {
                const myPoints = wasMe ? result.player_score : result.opponent_score;
                const oppPoints = wasMe ? result.opponent_score : result.player_score;

                html += '<div class="scoring-points animate-in">';
                html += '<div class="points-row mine">';
                html += '<span class="points-label">' + __('You:', 'shortcode-arcade') + '</span>';
                html += '<span class="points-value ' + (myPoints > 0 ? 'positive' : '') + '">+' + myPoints + '</span>';
                html += '</div>';
                html += '<div class="points-row opponent">';
                html += '<span class="points-label">' + __('Opponent:', 'shortcode-arcade') + '</span>';
                html += '<span class="points-value ' + (oppPoints > 0 ? 'positive' : '') + '">+' + oppPoints + '</span>';
                html += '</div>';
                html += '</div>';

                this.addToFeed(sprintf(__('You +%1$d / Opponent +%2$d', 'shortcode-arcade'), myPoints, oppPoints));
            }

            $('#overcut-result-area').html(html).fadeIn(300);

            // Update scoreboard after delay
            setTimeout(function() {
                self.animateScoreUpdate();
            }, TIMING.SCOREBOARD_TALLY);
        },

        animateScoreUpdate: function() {
            const self = this;

            // Flash the score values
            const $myScore = $('.overcut-score-row.mine .score-value');
            const $oppScore = $('.overcut-score-row.opponent .score-value');

            $myScore.addClass('updating');
            $oppScore.addClass('updating');

            setTimeout(function() {
                // Update to new scores
                $myScore.text(self.state.scores[self.mySeat]).removeClass('updating');
                $oppScore.text(self.state.scores[self.mySeat === 0 ? 1 : 0]).removeClass('updating');

                // Update progress bars
                const target = self.state.target_score;
                const myProgress = Math.min(100, (self.state.scores[self.mySeat] / target) * 100);
                const oppProgress = Math.min(100, (self.state.scores[self.mySeat === 0 ? 1 : 0] / target) * 100);

                $('.overcut-score-row.mine .score-bar').css('width', myProgress + '%');
                $('.overcut-score-row.opponent .score-bar').css('width', oppProgress + '%');

                // Step 7: scoreboard_update complete, now advance to next turn
                setTimeout(function() {
                    self.showAdvanceToNextTurn();
                }, TIMING.SCOREBOARD_TALLY);
            }, 300);
        },

        showAdvanceToNextTurn: function() {
            const self = this;
            const nextTurn = this.state.current_turn;
            const isMyTurn = nextTurn === this.mySeat;

            // Skip if game is over
            if (this.state.phase === 'game_over') {
                this.isAnimating = false;
                this.renderFullUI();
                return;
            }

            // GATE: Show "Next Round" button instead of auto-advancing
            let html = '<div class="overcut-game">';
            html += this.renderScoreboard();
            html += '<div class="overcut-turn-advance">';

            // Show round complete message
            html += '<div class="round-complete-message">';
            html += '<p style="font-size: 18px; color: var(--overcut-text-muted); margin-bottom: 16px;">';
            html += __('Round scoring complete.', 'shortcode-arcade');
            html += '</p>';
            html += '</div>';

            // Show next turn info
            if (isMyTurn) {
                html += '<div class="turn-indicator your-turn">';
                html += '<span class="turn-icon">👤</span>';
                html += '<span class="turn-text">' + __('Your Turn Next', 'shortcode-arcade') + '</span>';
                html += '</div>';
            } else {
                const oppName = this.state.players[nextTurn]?.name || __('Opponent', 'shortcode-arcade');
                html += '<div class="turn-indicator opponent-turn">';
                html += '<span class="turn-icon">🤖</span>';
                html += '<span class="turn-text">' + sprintf(__("%s's Turn Next", 'shortcode-arcade'), this.escapeHtml(oppName)) + '</span>';
                html += '</div>';
            }

            // Add Next Round button
            const nextRoundNumber = this.state.gate?.next_round
                ?? (this.state.round_number + (nextTurn === this.state.starting_player ? 1 : 0));
            html += '<div class="next-round-gate" style="margin-top: 24px; text-align: center;">';
            html += '<button class="overcut-btn overcut-btn-primary" data-action="next-round">';
            html += sprintf(__('Continue to Round %d', 'shortcode-arcade'), nextRoundNumber);
            html += '</button>';
            html += '</div>';

            html += '</div>';
            html += this.renderActionFeed();
            html += '</div>';

            $('#sacga-game-board').html(html);

            // Set gate flags
            this.scoringComplete = true;
            this.waitingForUserGate = true;
            this.isAnimating = false;

            // Rebind events for the new button
            this.bindEvents();
        },

        animateRolloff: function() {
            const self = this;
            const rolloff = this.state.rolloff;
            this.isAnimating = true;

            // Find which seat has new dice to animate
            let seatToAnimate = null;
            let diceToAnimate = null;
            for (let seat = 0; seat < 2; seat++) {
                const dice = rolloff.dice[seat];
                if (dice && dice.length > 0) {
                    const key = seat + ':' + dice.join(',');
                    if (this.lastAnimatedRolloff !== key) {
                        seatToAnimate = seat;
                        diceToAnimate = dice;
                        this.lastAnimatedRolloff = key;
                        break;
                    }
                }
            }

            if (seatToAnimate === null || !diceToAnimate) {
                this.isAnimating = false;
                this.renderFullUI();
                return;
            }

            // Render rolloff UI with EMPTY slots for the seat being animated
            this.renderRolloffWithEmptySlots(seatToAnimate);

            // Find the container with empty slots
            const isMe = seatToAnimate === this.mySeat;
            const containerClass = isMe ? '.rolloff-player.mine' : '.rolloff-player.opponent';
            const $container = $(containerClass + ' .rolloff-dice-slots');

            if ($container.length === 0) {
                this.isAnimating = false;
                this.renderFullUI();
                return;
            }

            // Animate dice into slots sequentially
            let currentIndex = 0;
            let runningTotal = 0;

            function revealNext() {
                if (currentIndex >= diceToAnimate.length) {
                    // All dice revealed - show total and unlock
                    $(containerClass + ' .rolloff-total').fadeIn(200);
                    setTimeout(function() {
                        self.isAnimating = false;
                        // Check if there's more to animate or render final state
                        if (self.shouldAnimateRolloff()) {
                            self.animateRolloff();
                        } else {
                            self.renderFullUI();
                        }
                    }, 300);
                    return;
                }

                const dieValue = diceToAnimate[currentIndex];
                runningTotal += dieValue;

                const $slot = $container.find('.dice-slot[data-slot="' + currentIndex + '"]');

                if ($slot.length === 0) {
                    currentIndex++;
                    setTimeout(revealNext, 50);
                    return;
                }

                $slot.removeClass('empty').addClass('revealing');

                // Create die element
                const dieFace = self.getDieFace(dieValue);
                const dieHtml = '<div class="sacga-die roll-in"><span class="die-face">' + dieFace + '</span></div>';
                $slot.html(dieHtml);

                // Remove revealing class after animation
                setTimeout(function() {
                    $slot.removeClass('revealing').addClass('revealed');
                }, 150);

                currentIndex++;
                setTimeout(revealNext, TIMING.DICE_REVEAL);
            }

            // Start revealing after brief pause
            setTimeout(revealNext, 100);
        },

        renderRolloffWithEmptySlots: function(animatingSeat) {
            const rolloff = this.state.rolloff;
            const oppSeat = this.mySeat === 0 ? 1 : 0;
            const tieCount = rolloff.tie_count || 0;

            let html = '<div class="overcut-game">';
            html += this.renderScoreboard();
            html += '<div class="overcut-rolloff">';
            html += '<h3 class="phase-title">' + __('Starting Roll-Off', 'shortcode-arcade') + '</h3>';

            if (tieCount > 0) {
                html += '<div class="rolloff-tie-notice">';
                html += sprintf(
                    _n('Tie! Roll again. (%d tie so far)', 'Tie! Roll again. (%d ties so far)', tieCount, 'shortcode-arcade'),
                    tieCount
                );
                html += '</div>';
            }

            html += '<p class="rolloff-instruction">' + __('Both players roll 3 dice. Higher total goes first!', 'shortcode-arcade') + '</p>';
            html += '<div class="rolloff-players">';

            // My roll
            html += '<div class="rolloff-player mine">';
            html += '<span class="rolloff-label">' + __('Your Roll', 'shortcode-arcade') + '</span>';
            html += '<div class="rolloff-dice-area">';
            html += this.renderRolloffSeatContent(this.mySeat, animatingSeat, rolloff);
            html += '</div>';
            html += '</div>';

            html += '<div class="rolloff-vs">VS</div>';

            // Opponent roll
            html += '<div class="rolloff-player opponent">';
            html += '<span class="rolloff-label">' + __('Opponent', 'shortcode-arcade') + '</span>';
            html += '<div class="rolloff-dice-area">';
            html += this.renderRolloffSeatContent(oppSeat, animatingSeat, rolloff);
            html += '</div>';
            html += '</div>';

            html += '</div>';
            html += '</div>';
            html += this.renderActionFeed();
            html += '</div>';

            $('#sacga-game-board').html(html);
            this.bindEvents();
        },

        renderRolloffSeatContent: function(seat, animatingSeat, rolloff) {
            const dice = rolloff.dice[seat] || [];
            const total = rolloff.totals[seat] || 0;
            const waiting = rolloff.waiting[seat];
            const isMe = seat === this.mySeat;

            // If this is the seat being animated, render empty slots
            if (seat === animatingSeat) {
                let html = '<div class="rolloff-dice-slots">';
                for (let i = 0; i < 3; i++) {
                    html += '<div class="dice-slot empty" data-slot="' + i + '"></div>';
                }
                html += '</div>';
                // Total hidden initially, will be shown after animation
                html += '<span class="rolloff-total" style="display:none;">' + __('Total:', 'shortcode-arcade') + ' <strong>' + total + '</strong></span>';
                return html;
            }

            // Otherwise render normally
            if (dice.length > 0) {
                let html = this.renderDiceRow(dice);
                html += '<span class="rolloff-total">' + __('Total:', 'shortcode-arcade') + ' <strong>' + total + '</strong></span>';
                return html;
            } else if (waiting) {
                if (isMe) {
                    return '<button class="overcut-btn overcut-btn-roll" data-action="rolloff">' + __('Roll for Start', 'shortcode-arcade') + '</button>';
                } else {
                    return '<div class="waiting-text">' + __('Waiting...', 'shortcode-arcade') + '</div>';
                }
            } else {
                return '<div class="waiting-indicator"><div class="waiting-spinner"></div></div>';
            }
        },

        renderFullUI: function() {
            const phase = this.state.phase;
            let html = '<div class="overcut-game">';

            html += this.renderScoreboard();

            switch (phase) {
                case 'rolloff':
                    html += this.renderRolloff();
                    break;
                case 'waiting':
                    // Waiting phase - show gate UI based on awaiting_gate
                    if (this.state.awaiting_gate === 'start_game') {
                        // Show rolloff results with Begin Game button
                        html += this.renderRolloff();
                    } else if (this.state.awaiting_gate === 'next_turn') {
                        // Show scoring results with Continue button
                        html += this.renderScoring();
                    } else {
                        // Fallback - shouldn't happen
                        html += '<div class="overcut-waiting"><p>' + __('Waiting...', 'shortcode-arcade') + '</p></div>';
                    }
                    break;
                case 'bidding':
                    html += this.renderBidding();
                    break;
                case 'rolling':
                    html += this.renderRolling();
                    break;
                case 'scoring':
                    html += this.renderScoring();
                    break;
                case 'game_over':
                    html += this.renderGameOver();
                    break;
            }

            html += this.renderActionFeed();
            html += '</div>';

            $('#sacga-game-board').html(html);
            this.bindEvents();
        },

        renderScoreboard: function(usePrevScores) {
            const target = this.state.target_score;

            // If usePrevScores, calculate what scores were before last_result
            let myScore = this.state.scores[this.mySeat];
            let oppScore = this.state.scores[this.mySeat === 0 ? 1 : 0];

            if (usePrevScores && this.state.last_result) {
                const result = this.state.last_result;
                const wasMe = result.player === this.mySeat;
                // Subtract the points that were just scored
                if (result.result_type !== 'null_roll') {
                    if (wasMe) {
                        myScore -= result.player_score;
                        oppScore -= result.opponent_score;
                    } else {
                        myScore -= result.opponent_score;
                        oppScore -= result.player_score;
                    }
                }
            }

            const myName = this.state.players[this.mySeat]?.name || __('You', 'shortcode-arcade');
            const oppName = this.state.players[this.mySeat === 0 ? 1 : 0]?.name || __('Opponent', 'shortcode-arcade');

            const myProgress = Math.min(100, (myScore / target) * 100);
            const oppProgress = Math.min(100, (oppScore / target) * 100);

            let html = '<div class="overcut-scoreboard">';

            html += '<div class="overcut-target">';
            html += '<span class="target-label">' + __('Target', 'shortcode-arcade') + '</span>';
            html += '<span class="target-value">' + target + '</span>';
            html += '</div>';

            html += '<div class="overcut-scores">';

            const isMyTurn = this.state.current_turn === this.mySeat;
            const isOppTurn = this.state.current_turn === (this.mySeat === 0 ? 1 : 0);

            html += '<div class="overcut-score-row mine' + (isMyTurn ? ' active' : '') + '">';
            html += '<span class="player-name">' + this.escapeHtml(myName) + '</span>';
            html += '<div class="score-bar-container">';
            html += '<div class="score-bar" style="width: ' + myProgress + '%"></div>';
            html += '</div>';
            html += '<span class="score-value">' + myScore + '</span>';
            html += '</div>';

            html += '<div class="overcut-score-row opponent' + (isOppTurn ? ' active' : '') + '">';
            html += '<span class="player-name">' + this.escapeHtml(oppName) + '</span>';
            html += '<div class="score-bar-container">';
            html += '<div class="score-bar" style="width: ' + oppProgress + '%"></div>';
            html += '</div>';
            html += '<span class="score-value">' + oppScore + '</span>';
            html += '</div>';

            html += '</div>';
            html += '</div>';

            return html;
        },

        renderRolloff: function() {
            const rolloff = this.state.rolloff;
            const myDice = rolloff.dice[this.mySeat] || [];
            const oppSeat = this.mySeat === 0 ? 1 : 0;
            const oppDice = rolloff.dice[oppSeat] || [];
            const myTotal = rolloff.totals[this.mySeat] || 0;
            const oppTotal = rolloff.totals[oppSeat] || 0;
            const myWaiting = rolloff.waiting[this.mySeat];
            const oppWaiting = rolloff.waiting[oppSeat];
            const winner = rolloff.winner;
            const tieCount = rolloff.tie_count || 0;

            let html = '<div class="overcut-rolloff">';
            html += '<h3 class="phase-title">' + __('Starting Roll-Off', 'shortcode-arcade') + '</h3>';

            if (tieCount > 0) {
                html += '<div class="rolloff-tie-notice">';
                html += sprintf(
                    _n('Tie! Roll again. (%d tie so far)', 'Tie! Roll again. (%d ties so far)', tieCount, 'shortcode-arcade'),
                    tieCount
                );
                html += '</div>';
            }

            html += '<p class="rolloff-instruction">' + __('Both players roll 3 dice. Higher total goes first!', 'shortcode-arcade') + '</p>';

            html += '<div class="rolloff-players">';

            // My roll
            html += '<div class="rolloff-player mine">';
            html += '<span class="rolloff-label">' + __('Your Roll', 'shortcode-arcade') + '</span>';
            html += '<div class="rolloff-dice-area">';
            if (myDice.length > 0) {
                html += this.renderDiceRow(myDice);
                html += '<span class="rolloff-total">' + __('Total:', 'shortcode-arcade') + ' <strong>' + myTotal + '</strong></span>';
            } else if (myWaiting) {
                html += '<button class="overcut-btn overcut-btn-roll" data-action="rolloff">' + __('Roll for Start', 'shortcode-arcade') + '</button>';
            } else {
                html += '<div class="waiting-indicator"><div class="waiting-spinner"></div></div>';
            }
            html += '</div>';
            html += '</div>';

            html += '<div class="rolloff-vs">VS</div>';

            // Opponent roll
            html += '<div class="rolloff-player opponent">';
            html += '<span class="rolloff-label">' + __('Opponent', 'shortcode-arcade') + '</span>';
            html += '<div class="rolloff-dice-area">';
            if (oppDice.length > 0) {
                html += this.renderDiceRow(oppDice);
                html += '<span class="rolloff-total">' + __('Total:', 'shortcode-arcade') + ' <strong>' + oppTotal + '</strong></span>';
            } else if (oppWaiting) {
                html += '<div class="waiting-text">' + __('Waiting...', 'shortcode-arcade') + '</div>';
            } else {
                html += '<div class="waiting-indicator"><div class="waiting-spinner"></div></div>';
            }
            html += '</div>';
            html += '</div>';

            html += '</div>';

            if (winner !== null) {
                const winnerName = winner === this.mySeat ? __('You', 'shortcode-arcade') : this.state.players[winner]?.name || __('Opponent', 'shortcode-arcade');
                html += '<div class="rolloff-winner">';
                html += '<span class="winner-text">' + sprintf(__('%s won the roll-off and goes first!', 'shortcode-arcade'), this.escapeHtml(winnerName)) + '</span>';
                html += '</div>';

                // GATE: Add Begin Game button
                html += '<div class="rolloff-gate" style="margin-top: 24px; text-align: center;">';
                html += '<button class="overcut-btn overcut-btn-primary" data-action="begin-game">' + __('Begin Game', 'shortcode-arcade') + '</button>';
                html += '</div>';

                // Mark rolloff as complete and waiting for user gate
                this.rolloffComplete = true;
                this.waitingForUserGate = true;
            }

            html += '</div>';
            return html;
        },

        renderDiceRow: function(dice) {
            let html = '<div class="dice-row">';
            for (let i = 0; i < dice.length; i++) {
                html += '<div class="sacga-die"><span class="die-face">' + this.getDieFace(dice[i]) + '</span></div>';
            }
            html += '</div>';
            return html;
        },

        renderBidding: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;
            const currentPlayer = this.state.players[this.state.current_turn]?.name || __('Opponent', 'shortcode-arcade');

            let html = '<div class="overcut-bidding">';
            html += '<h3 class="phase-title">' + sprintf(__('Round %d', 'shortcode-arcade'), this.state.round_number) + '</h3>';

            if (isMyTurn) {
                html += '<p class="bid-instruction">' + __('Choose your bid (6-36), then roll 6 dice!', 'shortcode-arcade') + '</p>';

                html += '<div class="bid-controls">';
                html += '<div class="bid-slider-row">';
                html += '<span class="bid-min">6</span>';
                html += '<input type="range" class="bid-slider" min="6" max="36" value="' + this.currentBid + '" id="overcut-bid-slider">';
                html += '<span class="bid-max">36</span>';
                html += '</div>';

                html += '<div class="bid-display">';
                html += '<span class="bid-label">' + __('Your Bid:', 'shortcode-arcade') + '</span>';
                html += '<span class="bid-value" id="overcut-bid-value">' + this.currentBid + '</span>';
                html += '</div>';

                html += '<div class="bid-hint">';
                html += '<span class="hint-text">' + __('Expected roll: ~21', 'shortcode-arcade') + '</span>';
                html += '</div>';

                html += '<button class="overcut-btn overcut-btn-bid" data-action="bid">' + __('Lock in Bid', 'shortcode-arcade') + '</button>';
                html += '</div>';
            } else {
                html += '<div class="opponent-turn-display">';
                html += '<div class="thinking-indicator">';
                html += '<div class="thinking-dots"><span></span><span></span><span></span></div>';
                html += '<span class="thinking-text">' + sprintf(__('%s is thinking...', 'shortcode-arcade'), this.escapeHtml(currentPlayer)) + '</span>';
                html += '</div>';
                html += '</div>';
            }

            html += '</div>';
            return html;
        },

        renderRolling: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;
            const bid = this.state.current_bid;
            const currentPlayer = this.state.players[this.state.current_turn]?.name || __('Opponent', 'shortcode-arcade');

            let html = '<div class="overcut-rolling">';
            html += '<h3 class="phase-title">' + sprintf(__('Round %d', 'shortcode-arcade'), this.state.round_number) + '</h3>';

            // Always show the bid
            html += '<div class="current-bid-display">';
            if (isMyTurn) {
                html += '<span>' + __('Your bid:', 'shortcode-arcade') + '</span>';
            } else {
                html += '<span>' + sprintf(__('%s bid:', 'shortcode-arcade'), this.escapeHtml(currentPlayer)) + '</span>';
            }
            html += '<span class="bid-amount">' + bid + '</span>';
            html += '</div>';

            if (isMyTurn) {
                // Empty dice tray
                html += '<div class="dice-tray">';
                for (let i = 0; i < 6; i++) {
                    html += '<div class="dice-slot empty"></div>';
                }
                html += '</div>';

                html += '<button class="overcut-btn overcut-btn-roll pulse" data-action="roll">' + __('Roll the Dice!', 'shortcode-arcade') + '</button>';
            } else {
                html += '<div class="opponent-turn-display">';
                html += '<div class="dice-tray">';
                for (let i = 0; i < 6; i++) {
                    html += '<div class="dice-slot empty"></div>';
                }
                html += '</div>';
                html += '<div class="rolling-indicator">';
                html += '<div class="waiting-spinner"></div>';
                html += '<span>' + sprintf(__('%s is rolling...', 'shortcode-arcade'), this.escapeHtml(currentPlayer)) + '</span>';
                html += '</div>';
                html += '</div>';
            }

            html += '</div>';
            return html;
        },

        renderScoring: function() {
            const result = this.state.last_result;
            if (!result) {
                return '<div class="overcut-scoring"><p>' + __('Calculating...', 'shortcode-arcade') + '</p></div>';
            }

            const wasMe = result.player === this.mySeat;
            const playerName = wasMe ? __('You', 'shortcode-arcade') : (this.state.players[result.player]?.name || __('Opponent', 'shortcode-arcade'));

            let html = '<div class="overcut-scoring">';
            html += '<h3 class="phase-title">' + sprintf(__('Round %d Result', 'shortcode-arcade'), result.round) + '</h3>';

            // Show who rolled and what they bid
            html += '<div class="roller-info">';
            html += sprintf(__('%s bid %d', 'shortcode-arcade'), this.escapeHtml(playerName), result.bid);
            html += '</div>';

            // Result banner
            let resultClass = 'result-' + result.result_type;
            let resultText = '';

            switch (result.result_type) {
                case 'exact_hit':
                    resultText = __('EXACT HIT!', 'shortcode-arcade');
                    break;
                case 'overcut':
                    resultText = __('Overcut', 'shortcode-arcade');
                    break;
                case 'undercut':
                    resultText = __('Undercut', 'shortcode-arcade');
                    break;
                case 'null_roll':
                    resultText = __('NULL ROLL!', 'shortcode-arcade');
                    break;
            }

            html += '<div class="result-banner ' + resultClass + '">';
            html += '<span class="result-text">' + resultText + '</span>';
            html += '</div>';

            // Dice display
            html += '<div class="scoring-dice">';
            html += this.renderDiceRow(result.dice);
            html += '</div>';

            // Comparison
            html += '<div class="scoring-comparison">';
            html += '<div class="comparison-item">';
            html += '<span class="comparison-label">' + __('Bid', 'shortcode-arcade') + '</span>';
            html += '<span class="comparison-value">' + result.bid + '</span>';
            html += '</div>';
            html += '<div class="comparison-vs">vs</div>';
            html += '<div class="comparison-item">';
            html += '<span class="comparison-label">' + __('Rolled', 'shortcode-arcade') + '</span>';
            html += '<span class="comparison-value">' + result.roll_total + '</span>';
            html += '</div>';
            html += '</div>';

            // Points
            if (result.result_type === 'null_roll') {
                html += '<div class="scoring-points null-roll">';
                html += '<p>' + __('Both players would win - no points awarded!', 'shortcode-arcade') + '</p>';
                html += '</div>';
            } else {
                html += '<div class="scoring-points">';
                const myPoints = wasMe ? result.player_score : result.opponent_score;
                const oppPoints = wasMe ? result.opponent_score : result.player_score;

                html += '<div class="points-row mine">';
                html += '<span class="points-label">' + __('You:', 'shortcode-arcade') + '</span>';
                html += '<span class="points-value ' + (myPoints > 0 ? 'positive' : '') + '">+' + myPoints + '</span>';
                html += '</div>';
                html += '<div class="points-row opponent">';
                html += '<span class="points-label">' + __('Opponent:', 'shortcode-arcade') + '</span>';
                html += '<span class="points-value ' + (oppPoints > 0 ? 'positive' : '') + '">+' + oppPoints + '</span>';
                html += '</div>';
                html += '</div>';
            }

            // Add Continue button if we're in waiting phase awaiting next turn
            if (this.state.phase === 'waiting' && this.state.awaiting_gate === 'next_turn') {
                // Get next turn from gate (current_turn is null during gates!)
                const nextTurn = this.state.gate?.next_turn ?? 0;

                // Compute next round locally for display
                // Round increments when starting player gets control
                const nextRoundNumber = this.state.gate?.next_round
                    ?? ((nextTurn === this.state.starting_player)
                        ? this.state.round_number + 1
                        : this.state.round_number);

                const isMyTurn = nextTurn === this.mySeat;

                if (isMyTurn) {
                    html += '<div class="turn-indicator your-turn" style="margin-top: 16px;">';
                    html += '<span class="turn-icon">👤</span>';
                    html += '<span class="turn-text">' + __('Your Turn Next', 'shortcode-arcade') + '</span>';
                    html += '</div>';
                } else {
                    const oppName = this.state.players[nextTurn]?.name || __('Opponent', 'shortcode-arcade');
                    html += '<div class="turn-indicator opponent-turn" style="margin-top: 16px;">';
                    html += '<span class="turn-icon">🤖</span>';
                    html += '<span class="turn-text">' + sprintf(__("%s's Turn Next", 'shortcode-arcade'), this.escapeHtml(oppName)) + '</span>';
                    html += '</div>';
                }

                // Add Next Round button
                html += '<div class="next-round-gate" style="margin-top: 24px; text-align: center;">';
                html += '<button class="overcut-btn overcut-btn-primary" data-action="next-round">';
                html += sprintf(__('Continue to Round %d', 'shortcode-arcade'), nextRoundNumber);
                html += '</button>';
                html += '</div>';

                // Mark scoring as complete
                this.scoringComplete = true;
                this.waitingForUserGate = true;
            }

            html += '</div>';
            return html;
        },

        renderGameOver: function() {
            const winner = this.state.winner;
            const isWinner = winner === this.mySeat;
            const winnerName = this.state.players[winner]?.name || __('Player', 'shortcode-arcade');

            let html = '<div class="overcut-game-over ' + (isWinner ? 'victory' : 'defeat') + '">';

            if (isWinner) {
                html += '<h2 class="game-over-title victory-title">' + __('Victory!', 'shortcode-arcade') + '</h2>';
                html += '<p class="game-over-message">' + __('You reached the target score!', 'shortcode-arcade') + '</p>';
            } else {
                html += '<h2 class="game-over-title defeat-title">' + __('Defeat', 'shortcode-arcade') + '</h2>';
                html += '<p class="game-over-message">' + sprintf(__('%s reached the target score.', 'shortcode-arcade'), this.escapeHtml(winnerName)) + '</p>';
            }

            html += '<div class="final-scores">';
            html += '<div class="final-score mine">';
            html += '<span class="final-label">' + __('Your Score', 'shortcode-arcade') + '</span>';
            html += '<span class="final-value">' + this.state.scores[this.mySeat] + '</span>';
            html += '</div>';
            html += '<div class="final-score opponent">';
            html += '<span class="final-label">' + __('Opponent', 'shortcode-arcade') + '</span>';
            html += '<span class="final-value">' + this.state.scores[this.mySeat === 0 ? 1 : 0] + '</span>';
            html += '</div>';
            html += '</div>';

            html += '<p class="rounds-played">' + sprintf(__('Rounds played: %d', 'shortcode-arcade'), this.state.round_number) + '</p>';

            html += '</div>';
            return html;
        },

        renderActionFeed: function() {
            if (this.actionFeed.length === 0) return '';

            let html = '<div class="overcut-action-feed">';
            html += '<div class="feed-items">';

            this.actionFeed.forEach(function(item) {
                html += '<div class="feed-item">' + item + '</div>';
            });

            html += '</div>';
            html += '</div>';

            return html;
        },

        addToFeed: function(message) {
            this.actionFeed.push(message);
            if (this.actionFeed.length > this.maxFeedItems) {
                this.actionFeed.shift();
            }
        },

        /**
         * Show bid confirmation animation (human turn)
         * Displays the locked-in bid prominently before transitioning to rolling phase
         */
        showBidConfirmation: function(bid) {
            let html = '<div class="overcut-game">';
            html += this.renderScoreboard();
            html += '<div class="overcut-bidding">';
            html += '<h3 class="phase-title">' + sprintf(__('Round %d', 'shortcode-arcade'), this.state.round_number) + '</h3>';

            // Bid confirmation display
            html += '<div class="bid-confirmation animate-in">';
            html += '<div class="confirmation-icon">✓</div>';
            html += '<div class="confirmation-text">' + __('Bid Locked', 'shortcode-arcade') + '</div>';
            html += '<div class="confirmation-value">' + bid + '</div>';
            html += '<div class="confirmation-hint">' + __('Preparing to roll...', 'shortcode-arcade') + '</div>';
            html += '</div>';

            html += '</div>';
            html += this.renderActionFeed();
            html += '</div>';

            $('#sacga-game-board').html(html);
        },

        /**
         * Show rolling announcement (human turn)
         * Displays rolling message before dice appear
         */
        showRollingAnnouncement: function() {
            const bid = this.state.current_bid;

            let html = '<div class="overcut-game">';
            html += this.renderScoreboard();
            html += '<div class="overcut-rolling">';
            html += '<h3 class="phase-title">' + sprintf(__('Round %d', 'shortcode-arcade'), this.state.round_number) + '</h3>';

            // Show bid
            html += '<div class="current-bid-display">';
            html += '<span>' + __('Your bid:', 'shortcode-arcade') + '</span>';
            html += '<span class="bid-amount">' + bid + '</span>';
            html += '</div>';

            // Empty dice tray
            html += '<div class="dice-tray">';
            for (let i = 0; i < 6; i++) {
                html += '<div class="dice-slot empty"></div>';
            }
            html += '</div>';

            // Rolling announcement
            html += '<div class="rolling-announcement animate-in">';
            html += '<div class="rolling-icon">🎲</div>';
            html += '<div class="rolling-text">' + __('Rolling the dice...', 'shortcode-arcade') + '</div>';
            html += '</div>';

            html += '</div>';
            html += this.renderActionFeed();
            html += '</div>';

            $('#sacga-game-board').html(html);
        },

        bindEvents: function() {
            const self = this;

            // Begin Game button (after rolloff)
            $('[data-action="begin-game"]').on('click', function() {
                $(this).prop('disabled', true);
                // Clear rolloff gate flags
                self.rolloffComplete = false;
                self.waitingForUserGate = false;
                // Send begin_game action to backend
                if (self.onMove) {
                    self.onMove({ action: 'begin_game' });
                }
            });

            // Next Round button (after scoring)
            $('[data-action="next-round"]').on('click', function() {
                $(this).prop('disabled', true);
                // Clear scoring gate flags
                self.scoringComplete = false;
                self.waitingForUserGate = false;
                // Send continue action to backend
                if (self.onMove) {
                    self.onMove({ action: 'continue' });
                }
            });

            $('.overcut-btn-roll[data-action="rolloff"]').on('click', function() {
                $(this).prop('disabled', true).text(__('Rolling...', 'shortcode-arcade'));
                if (self.onMove) {
                    self.onMove({ action: 'rolloff' });
                }
            });

            $('#overcut-bid-slider').on('input', function() {
                self.currentBid = parseInt($(this).val(), 10);
                $('#overcut-bid-value').text(self.currentBid);
            });

            $('.overcut-btn-bid').on('click', function() {
                $(this).prop('disabled', true);
                const bidValue = self.currentBid;

                // Show bid confirmation animation BEFORE sending move
                self.showBidConfirmation(bidValue);

                // Wait 1500ms to let user see the confirmation, then submit
                setTimeout(function() {
                    self.addToFeed(sprintf(__('You bid %d', 'shortcode-arcade'), bidValue));
                    if (self.onMove) {
                        self.onMove({ action: 'bid', bid: bidValue });
                    }
                }, TIMING.MESSAGE_DELAY);
            });

            $('.overcut-btn-roll[data-action="roll"]').on('click', function() {
                $(this).prop('disabled', true).text(__('Rolling...', 'shortcode-arcade'));

                // Show rolling announcement BEFORE sending move
                self.showRollingAnnouncement();

                // Wait 1500ms to build suspense, then submit
                setTimeout(function() {
                    self.addToFeed(__('Rolling...', 'shortcode-arcade'));
                    if (self.onMove) {
                        self.onMove({ action: 'roll' });
                    }
                }, TIMING.MESSAGE_DELAY);
            });
        },

        escapeHtml: function(text) {
            if (window.SACGA && typeof window.SACGA.escapeHtml === 'function') {
                return window.SACGA.escapeHtml(text);
            }
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    window.SACGAGames = window.SACGAGames || {};
    window.SACGAGames.overcut = OvercutRenderer;

})(jQuery);
