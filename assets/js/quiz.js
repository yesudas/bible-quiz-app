document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('quizApp');
    if (!root) return;

    const state = {
        lang: root.dataset.lang,
        type: root.dataset.type,
        setIndex: parseInt(root.dataset.set, 10) || 0,
        setSize: Math.min(100, Math.max(1, parseInt(root.dataset.setSize, 10) || 10)),
        sets: [],
        books: (root.dataset.books || '').split(',').filter(Boolean),
        chapters: (root.dataset.chapters || '').split(',').filter(Boolean),
        topic: root.dataset.topic,
        character: root.dataset.character,
        place: root.dataset.place,
        difficulty: (root.dataset.difficulty || '').split(',').filter(Boolean),
        timed: root.dataset.timed === '1',
        timeUnit: root.dataset.timeUnit,
        timeValue: parseInt(root.dataset.timeValue, 10) || 0,
        indexData: null,
        questions: [],
        answers: [],
        current: 0,
        timerId: null,
        secondsLeft: 0,
        playingAsLabel: root.dataset.playingAs || 'Name',
        showNumberLabel: root.dataset.showNumber || 'Show number',
        hideNumberLabel: root.dataset.hideNumber || 'Hide number',
        congratsTop100Label: root.dataset.congratsTop100 || "Congratulations! You're in the Top 100 on the leaderboard — rank #{rank}!",
        pdfHeading: root.dataset.pdfHeading || 'Bible Quiz Results',
        pdfPoweredByLabel: root.dataset.pdfPoweredBy || 'Powered by:',
        pdfReviewHeading: root.dataset.pdfReviewHeading || 'Questions & Answers',
    };

    const els = {
        loading: document.getElementById('quizLoading'),
        error: document.getElementById('quizError'),
        play: document.getElementById('quizPlay'),
        setResults: document.getElementById('quizSetResults'),
        review: document.getElementById('quizReview'),
        progressBar: document.getElementById('progressBar'),
        progressText: document.getElementById('progressText'),
        timerRing: document.getElementById('timerRing'),
        timerRingProgress: document.getElementById('timerRingProgress'),
        timerText: document.getElementById('timerText'),
        questionCard: document.getElementById('questionCard'),
        nextBtn: document.getElementById('nextBtn'),
        setScoreSummary: document.getElementById('setScoreSummary'),
        setScoreChart: document.getElementById('setScoreChart'),
        setEncouragement: document.getElementById('setEncouragement'),
        reviewScoreChart: document.getElementById('reviewScoreChart'),
        reviewList: document.getElementById('reviewList'),
        nextSetBtn: document.getElementById('nextSetBtn'),
        nextSetFromReviewBtn: document.getElementById('nextSetFromReviewBtn'),
        reviewBtn: document.getElementById('reviewBtn'),
        backFromReviewBtn: document.getElementById('backFromReviewBtn'),
        leaderboardModal: document.getElementById('leaderboardModal'),
        leaderboardForm: document.getElementById('leaderboardForm'),
        leaderboardName: document.getElementById('leaderboardName'),
        leaderboardCountry: document.getElementById('leaderboardCountry'),
        leaderboardMobile: document.getElementById('leaderboardMobile'),
        leaderboardFormError: document.getElementById('leaderboardFormError'),
        leaderboardSkipBtn: document.getElementById('leaderboardSkipBtn'),
        leaderboardPlayerInfo: document.getElementById('leaderboardPlayerInfo'),
        leaderboardSaveStatus: document.getElementById('leaderboardSaveStatus'),
        leaderboardCongratsModal: document.getElementById('leaderboardCongratsModal'),
        leaderboardCongratsText: document.getElementById('leaderboardCongratsText'),
        leaderboardCongratsOkBtn: document.getElementById('leaderboardCongratsOkBtn'),
        downloadPdfBtn: document.getElementById('downloadPdfBtn'),
    };

    function chunkPaths(paths, size) {
        const sets = [];
        for (let i = 0; i < paths.length; i += size) {
            sets.push(paths.slice(i, i + size));
        }
        return sets;
    }

    function shuffle(array) {
        const result = array.slice();
        for (let i = result.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [result[i], result[j]] = [result[j], result[i]];
        }
        return result;
    }

    function sameMembers(a, b) {
        if (!Array.isArray(a) || !Array.isArray(b) || a.length !== b.length) return false;
        const as = [...a].sort();
        const bs = [...b].sort();
        return as.every((v, i) => v === bs[i]);
    }

    // Identifies one quiz attempt (this exact selection), so a fresh "Start Quiz"
    // always reshuffles, while moving on to the next set of the SAME attempt
    // reuses that attempt's shuffle order — every question appears exactly once
    // across the whole attempt, with no repeats or gaps between sets.
    function quizAttemptSignature() {
        return [
            state.lang, state.type, state.setSize,
            state.books.slice().sort().join(','),
            state.chapters.slice().sort().join(','),
            state.topic || '', state.character || '', state.place || '',
            state.difficulty.slice().sort().join(','),
        ].join('|');
    }

    function getShuffledPaths(allPaths) {
        const storageKey = 'quizShuffleOrder';
        const sig = quizAttemptSignature();

        if (state.setIndex > 0) {
            try {
                const stored = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
                if (stored && stored.sig === sig && sameMembers(stored.paths, allPaths)) {
                    return stored.paths;
                }
            } catch (err) {
                // sessionStorage unavailable/corrupted — fall through to a fresh shuffle.
            }
        }

        const shuffled = shuffle(allPaths);
        try {
            sessionStorage.setItem(storageKey, JSON.stringify({ sig, paths: shuffled }));
        } catch (err) {
            // Private browsing / storage quota — the shuffle still works for this load,
            // it just won't be remembered for the next set's page load.
        }
        return shuffled;
    }

    function indexUrl() {
        const params = new URLSearchParams({
            lang: state.lang,
            type: state.type,
        });
        if (state.type === 'book' || state.type === 'chapter') {
            state.books.forEach((b) => params.append('books[]', b));
        }
        if (state.type === 'chapter' && state.chapters.length) {
            state.chapters.forEach((ch) => params.append('chapters[]', ch));
        }
        if (state.type === 'topic' && state.topic) params.set('topic', state.topic);
        if (state.type === 'character' && state.character) params.set('character', state.character);
        if (state.type === 'place' && state.place) params.set('place', state.place);
        state.difficulty.forEach((d) => params.append('difficulty[]', d));
        params.set('v', window.QUIZ_VERSION || '');
        return `api/index.php?${params.toString()}`;
    }

    function showError(message) {
        els.loading.hidden = true;
        els.error.hidden = false;
        els.error.textContent = message;
    }

    function updateProgress() {
        const total = state.questions.length;
        const answered = state.current;
        const pct = total ? Math.round((answered / total) * 100) : 0;
        els.progressBar.style.width = `${pct}%`;
        els.progressText.textContent = `Question ${Math.min(answered + 1, total)} of ${total}`;
    }

    function clearTimer() {
        if (state.timerId) {
            clearInterval(state.timerId);
            state.timerId = null;
        }
    }

    const TIMER_RING_CIRCUMFERENCE = 2 * Math.PI * 45;

    function formatTimeLeft(seconds) {
        if (seconds >= 60) {
            const m = Math.floor(seconds / 60);
            const s = seconds % 60;
            return `${m}:${String(s).padStart(2, '0')}`;
        }
        return `${seconds}s`;
    }

    function updateTimerRing(secondsLeft, totalSeconds) {
        if (!els.timerRingProgress) return;
        const fraction = totalSeconds > 0 ? Math.max(0, Math.min(1, secondsLeft / totalSeconds)) : 0;
        const offset = TIMER_RING_CIRCUMFERENCE * (1 - fraction);
        els.timerRingProgress.style.strokeDasharray = `${TIMER_RING_CIRCUMFERENCE}`;
        els.timerRingProgress.style.strokeDashoffset = `${offset}`;
        if (els.timerRing) {
            els.timerRing.classList.toggle('is-low', totalSeconds > 0 && fraction <= 0.2);
        }
    }

    function startTimer() {
        if (!state.timed || state.timeValue < 1) {
            if (els.timerRing) els.timerRing.hidden = true;
            return;
        }
        const totalSeconds = state.timeUnit === 'minutes'
            ? state.timeValue * 60
            : state.timeValue;
        state.secondsLeft = totalSeconds;
        if (els.timerRing) els.timerRing.hidden = false;
        const tick = () => {
            els.timerText.textContent = formatTimeLeft(state.secondsLeft);
            updateTimerRing(state.secondsLeft, totalSeconds);
            if (state.secondsLeft <= 0) {
                clearTimer();
                finishSet();
                return;
            }
            state.secondsLeft -= 1;
        };
        tick();
        state.timerId = setInterval(tick, 1000);
    }

    // Shuffles a question's options in place (once per question instance) so the
    // correct answer isn't always shown in the same position on repeat attempts,
    // remapping correct_option to match so scoring stays correct.
    function shuffleQuestionOptions(q) {
        if (q.__shuffled || !Array.isArray(q.options) || q.options.length < 2) {
            q.__shuffled = true;
            return;
        }

        const originalOptions = q.options;
        const correctIndex = parseInt(q.correct_option, 10);

        const order = originalOptions.map((_, i) => i);
        for (let i = order.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [order[i], order[j]] = [order[j], order[i]];
        }

        q.options = order.map((originalIndex) => originalOptions[originalIndex]);
        q.correct_option = order.indexOf(correctIndex);
        q.__shuffled = true;
    }

    function renderQuestion() {
        const q = state.questions[state.current];
        if (!q) return;

        shuffleQuestionOptions(q);
        updateProgress();
        els.nextBtn.disabled = true;

        const optionsHtml = (q.options || []).map((opt, idx) => (
            `<button type="button" class="option-btn" data-index="${idx}">${escapeHtml(opt)}</button>`
        )).join('');

        els.questionCard.innerHTML = `
            <p class="q-meta">${escapeHtml(q.verse_reference || '')} · ${escapeHtml(q.difficulty || '')}</p>
            <h2 class="q-text">${escapeHtml(q.question || '')}</h2>
            <div class="options" role="group" aria-label="Answer choices">${optionsHtml}</div>
            ${q._path ? reportMistakeHtml() : ''}
        `;

        els.questionCard.querySelectorAll('.option-btn').forEach((btn) => {
            btn.addEventListener('click', () => selectAnswer(q, parseInt(btn.dataset.index, 10), btn));
        });

        if (q._path) bindReportMistake(q);
    }

    const REPORT_TYPES = [
        ['question', 'Mistake in the question'],
        ['answer', 'Mistake in the answer'],
        ['both', 'Mistake in question and answer'],
    ];

    function reportMistakeHtml() {
        const radios = REPORT_TYPES.map(([value, label]) => (
            `<label class="report-option"><input type="radio" name="reportType" value="${value}"> ${label}</label>`
        )).join('');
        return `
            <div class="report-mistake">
                <button type="button" class="btn-link report-toggle">Report Mistake</button>
                <form class="report-form" hidden>
                    <fieldset>
                        <legend>What is wrong with this question?</legend>
                        ${radios}
                    </fieldset>
                    <p class="report-status" role="status" aria-live="polite"></p>
                    <div class="report-actions">
                        <button type="submit" class="btn btn-primary" disabled>Submit</button>
                        <button type="button" class="btn btn-link report-cancel">Cancel</button>
                    </div>
                </form>
            </div>
        `;
    }

    function bindReportMistake(q) {
        const wrap = els.questionCard.querySelector('.report-mistake');
        const toggle = wrap.querySelector('.report-toggle');
        const form = wrap.querySelector('.report-form');
        const status = wrap.querySelector('.report-status');
        const submit = form.querySelector('button[type="submit"]');

        toggle.addEventListener('click', () => {
            form.hidden = !form.hidden;
        });
        wrap.querySelector('.report-cancel').addEventListener('click', () => {
            form.hidden = true;
        });
        form.addEventListener('change', () => {
            submit.disabled = !form.querySelector('input[name="reportType"]:checked');
        });
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const checked = form.querySelector('input[name="reportType"]:checked');
            if (!checked) return;
            submit.disabled = true;
            status.textContent = 'Sending…';
            try {
                const res = await fetch('api/report.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ path: q._path, type: checked.value }),
                });
                if (!res.ok) throw new Error();
                form.hidden = true;
                toggle.disabled = true;
                toggle.textContent = 'Thanks — mistake reported';
            } catch (err) {
                status.textContent = 'Could not send your report. Please try again.';
                submit.disabled = false;
            }
        });
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function selectAnswer(q, chosen, btn) {
        if (state.answers[state.current] !== undefined) return;

        const correct = parseInt(q.correct_option, 10);
        state.answers[state.current] = chosen;

        els.questionCard.querySelectorAll('.option-btn').forEach((b) => {
            b.disabled = true;
            const idx = parseInt(b.dataset.index, 10);
            if (idx === correct) b.classList.add('is-correct');
            if (idx === chosen && chosen !== correct) b.classList.add('is-wrong');
        });

        els.nextBtn.disabled = false;
        if (state.current >= state.questions.length - 1) {
            els.nextBtn.textContent = 'Finish set';
        }
    }

    function getScoreStats() {
        let correct = 0;
        let wrong = 0;
        let unanswered = 0;
        state.questions.forEach((q, i) => {
            const chosen = state.answers[i];
            if (chosen === undefined) {
                unanswered += 1;
                return;
            }
            if (chosen === parseInt(q.correct_option, 10)) {
                correct += 1;
            } else {
                wrong += 1;
            }
        });
        const total = state.questions.length;
        const pct = total ? Math.round((correct / total) * 100) : 0;
        return { correct, wrong, unanswered, total, pct };
    }

    // Ring radius/circumference matching the timer ring's own SVG geometry
    // (assets/css/style.css .timer-ring-svg uses the same r=45 viewBox).
    const SCORE_DONUT_CIRCUMFERENCE = 2 * Math.PI * 45;

    function buildScoreChartHtml(stats) {
        const { correct, wrong, unanswered, total, pct } = stats;
        if (!total) {
            return '';
        }
        const correctPct = (correct / total) * 100;
        const wrongPct = (wrong / total) * 100;
        const unansweredPct = (unanswered / total) * 100;

        // Three stacked circle strokes (correct, wrong, unanswered) placed back
        // to back around the ring via stroke-dasharray/stroke-dashoffset — an
        // SVG multi-segment progress ring, same technique as the quiz timer
        // ring above. Drawn with real strokes (not a CSS conic-gradient)
        // because conic-gradient isn't reliably captured by html2canvas when
        // exporting the PDF.
        const c = SCORE_DONUT_CIRCUMFERENCE;
        const correctLen = (correct / total) * c;
        const wrongLen = (wrong / total) * c;
        const unansweredLen = (unanswered / total) * c;

        return `
            <div class="score-chart" role="img" aria-label="${correct} correct, ${wrong} wrong, ${unanswered} unanswered out of ${total}">
                <div class="score-donut">
                    <svg class="score-donut-svg" viewBox="0 0 100 100" aria-hidden="true">
                        <circle class="score-donut-track" cx="50" cy="50" r="45"></circle>
                        <circle class="score-donut-seg is-correct" cx="50" cy="50" r="45"
                            stroke-dasharray="${correctLen} ${c - correctLen}" stroke-dashoffset="0"></circle>
                        <circle class="score-donut-seg is-wrong" cx="50" cy="50" r="45"
                            stroke-dasharray="${wrongLen} ${c - wrongLen}" stroke-dashoffset="${-correctLen}"></circle>
                        <circle class="score-donut-seg is-skipped" cx="50" cy="50" r="45"
                            stroke-dasharray="${unansweredLen} ${c - unansweredLen}" stroke-dashoffset="${-(correctLen + wrongLen)}"></circle>
                    </svg>
                    <div class="score-donut-hole">
                        <span class="score-donut-fraction">${correct}/${total}</span>
                        <span class="score-donut-label">Correct</span>
                    </div>
                </div>
                <div class="score-summary-block">
                    <span class="score-summary-label">Score</span>
                    <span class="score-summary-value">${pct}%</span>
                </div>
                <div class="score-bar-chart">
                    <div class="score-bar-row">
                        <span class="score-bar-name">Correct</span>
                        <div class="score-bar-track" aria-hidden="true">
                            <div class="score-bar-fill is-correct" style="width:${correctPct}%"></div>
                        </div>
                        <span class="score-bar-count">${correct}</span>
                    </div>
                    <div class="score-bar-row">
                        <span class="score-bar-name">Wrong</span>
                        <div class="score-bar-track" aria-hidden="true">
                            <div class="score-bar-fill is-wrong" style="width:${wrongPct}%"></div>
                        </div>
                        <span class="score-bar-count">${wrong}</span>
                    </div>
                    ${unanswered > 0 ? `
                    <div class="score-bar-row">
                        <span class="score-bar-name">Unanswered</span>
                        <div class="score-bar-track" aria-hidden="true">
                            <div class="score-bar-fill is-skipped" style="width:${unansweredPct}%"></div>
                        </div>
                        <span class="score-bar-count">${unanswered}</span>
                    </div>` : ''}
                </div>
            </div>
        `;
    }

    function renderScoreCharts(stats) {
        const html = buildScoreChartHtml(stats);
        if (els.setScoreChart) els.setScoreChart.innerHTML = html;
        if (els.reviewScoreChart) els.reviewScoreChart.innerHTML = html;
    }

    function readPlayerInfo() {
        try {
            return JSON.parse(localStorage.getItem('bibleQuizPlayerInfo') || 'null') || {};
        } catch (err) {
            return {};
        }
    }

    function savePlayerInfo(info) {
        try {
            localStorage.setItem('bibleQuizPlayerInfo', JSON.stringify(info));
        } catch (err) {
            // ignore — not critical if this can't persist
        }
    }

    // Remembers, per quiz attempt (sessionStorage, keyed by quizAttemptSignature),
    // whether the player already decided to join or skip the leaderboard —
    // so the popup only appears once per attempt (right after the FIRST set,
    // not the whole quiz, since long quizzes may never be finished in one
    // sitting) and every later set either silently resubmits that set's
    // score (joined) or just re-shows their name (skipped), with no repeat nagging.
    function readAttemptLeaderboardState() {
        // Same fresh-start rule as getShuffledPaths() above: a brand new
        // attempt (setIndex 0) never inherits a prior attempt's join/skip
        // decision, even if it happens to share the same signature. Without
        // this, a "Skip" from an earlier identical-parameter attempt would
        // silently suppress the popup — and every submission — on the next
        // attempt too.
        if (state.setIndex === 0) {
            return null;
        }
        try {
            const stored = JSON.parse(sessionStorage.getItem('quizLeaderboardAttempt') || 'null');
            return stored && stored.sig === quizAttemptSignature() ? stored : null;
        } catch (err) {
            return null;
        }
    }

    function saveAttemptLeaderboardState(stateToSave) {
        try {
            sessionStorage.setItem('quizLeaderboardAttempt', JSON.stringify({
                sig: quizAttemptSignature(),
                ...stateToSave,
            }));
        } catch (err) {
            // ignore — worst case the popup asks again on the next set
        }
    }

    // Returns the player's 1-based leaderboard rank on success, or null if the
    // submission failed (network error, or the server rejected it) — a
    // failure here should never block the player from seeing their results,
    // it just means no congrats banner this time.
    // correctCount/totalQuestions here are for ONE finished set only — the
    // server adds them to that mobile number's lifetime running total and
    // returns the updated total plus rank. Submitting an attempt-cumulative
    // total instead would double-count it on every later set of the same
    // attempt.
    async function submitLeaderboardScore(displayName, countryCode, mobile, correctCount, totalQuestions) {
        try {
            const res = await fetch('api/leaderboard.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    displayName, countryCode, mobile,
                    correctCount, totalQuestions,
                }),
            });
            const data = await res.json().catch(() => null);
            if (!res.ok) {
                console.error('[leaderboard] submit failed', res.status, data);
                return null;
            }
            if (!data || typeof data.rank !== 'number') {
                console.error('[leaderboard] unexpected response', data);
                return null;
            }
            return data;
        } catch (err) {
            console.error('[leaderboard] network error submitting score', err);
            return null;
        }
    }

    // correctCount/totalQuestions are shown on screen (not just logged) so
    // it's immediately visible whether the cumulative score being SENT is
    // actually growing attempt over attempt — rather than having to guess
    // whether a "stuck" leaderboard number is a submission bug or just the
    // "we only keep your best score" rule correctly leaving an older,
    // higher score in place.
    // `result` is the {rank, correctCount, totalQuestions} object returned by
    // submitLeaderboardScore() (correctCount/totalQuestions there are the
    // LIFETIME totals after this submission, not just this one set's score)
    // — or null if the submission failed.
    function showSaveStatus(result) {
        if (!els.leaderboardSaveStatus) return;
        els.leaderboardSaveStatus.textContent = result
            ? `✓ Added to leaderboard — lifetime total: ${result.correctCount}/${result.totalQuestions} (rank #${result.rank})`
            : '⚠ Could not save to the leaderboard — check your connection and try the next set.';
        els.leaderboardSaveStatus.classList.toggle('is-error', !result);
        els.leaderboardSaveStatus.hidden = false;
    }

    function maskMobile(countryCode, mobile) {
        const digits = mobile.replace(/\D/g, '');
        const visible = digits.slice(-2);
        const masked = '•'.repeat(Math.max(0, digits.length - 2)) + visible;
        return `${countryCode} ${masked}`;
    }

    function showPlayerInfo(displayName, countryCode, mobile) {
        if (!displayName) {
            els.leaderboardPlayerInfo.hidden = true;
            return;
        }
        let html = `${escapeHtml(state.playingAsLabel)}: <strong>${escapeHtml(displayName)}</strong>`;
        if (mobile) {
            const masked = maskMobile(countryCode, mobile);
            html += ` <span class="player-mobile"><span class="player-mobile-value" data-masked="${escapeHtml(masked)}" data-full="${escapeHtml(countryCode + ' ' + mobile)}">${escapeHtml(masked)}</span> `
                + `<button type="button" class="btn-link player-mobile-toggle" data-shown="0">${escapeHtml(state.showNumberLabel)}</button></span>`;
        }
        els.leaderboardPlayerInfo.innerHTML = html;
        els.leaderboardPlayerInfo.hidden = false;

        const toggle = els.leaderboardPlayerInfo.querySelector('.player-mobile-toggle');
        const valueEl = els.leaderboardPlayerInfo.querySelector('.player-mobile-value');
        if (toggle && valueEl) {
            toggle.addEventListener('click', () => {
                const shown = toggle.dataset.shown === '1';
                valueEl.textContent = shown ? valueEl.dataset.masked : valueEl.dataset.full;
                toggle.textContent = shown ? state.showNumberLabel : state.hideNumberLabel;
                toggle.dataset.shown = shown ? '0' : '1';
            });
        }
    }

    // Shows the "you're in the Top 100" popup at most ONCE per attempt. With
    // few leaderboard entries, almost every submission ranks <= 100, so
    // without this guard the popup — a full-screen overlay — would reopen
    // after every single set, blocking "Next set" each time until the OK
    // button is clicked. That looked exactly like "the score stopped
    // updating": the player got stuck behind a popup they didn't notice and
    // never played (or submitted) another set.
    function maybeShowCongratsOnce(attemptStateSoFar, rank) {
        if (typeof rank !== 'number' || rank > 100) return;
        if (attemptStateSoFar && attemptStateSoFar.congratsShown) return;
        els.leaderboardCongratsText.textContent = state.congratsTop100Label.replace('{rank}', rank);
        els.leaderboardCongratsModal.hidden = false;
        saveAttemptLeaderboardState({ ...attemptStateSoFar, congratsShown: true });
    }

    if (els.leaderboardCongratsOkBtn) {
        els.leaderboardCongratsOkBtn.addEventListener('click', () => {
            els.leaderboardCongratsModal.hidden = true;
        });
    }
    if (els.leaderboardCongratsModal) {
        // Click on the backdrop itself (not the box inside it) also closes
        // it, so there's no way to get stuck behind this popup.
        els.leaderboardCongratsModal.addEventListener('click', (e) => {
            if (e.target === els.leaderboardCongratsModal) {
                els.leaderboardCongratsModal.hidden = true;
            }
        });
    }

    function revealResults(displayName, countryCode, mobile) {
        showPlayerInfo(displayName, countryCode, mobile);
        els.setResults.hidden = false;
    }

    function openLeaderboardModal(correctCount, totalQuestions) {
        const info = readPlayerInfo();
        els.leaderboardName.value = info.displayName || '';
        els.leaderboardMobile.value = info.mobile || '';
        if (info.countryCode) els.leaderboardCountry.value = info.countryCode;
        els.leaderboardFormError.hidden = true;
        els.leaderboardModal.hidden = false;

        const closeWith = (displayName, countryCode, mobile) => {
            els.leaderboardModal.hidden = true;
            revealResults(displayName, countryCode, mobile);
        };

        els.leaderboardForm.onsubmit = async (e) => {
            e.preventDefault();
            const displayName = els.leaderboardName.value.trim();
            const countryCode = els.leaderboardCountry.value;
            const mobile = els.leaderboardMobile.value.trim();

            if (!displayName || !mobile) {
                els.leaderboardFormError.hidden = false;
                return;
            }
            els.leaderboardFormError.hidden = true;

            savePlayerInfo({ displayName, countryCode, mobile });
            const joinedState = { status: 'joined', displayName, countryCode, mobile };
            saveAttemptLeaderboardState(joinedState);

            const result = await submitLeaderboardScore(displayName, countryCode, mobile, correctCount, totalQuestions);

            closeWith(displayName, countryCode, mobile);
            showSaveStatus(result);
            maybeShowCongratsOnce(joinedState, result && result.rank);
        };

        els.leaderboardSkipBtn.onclick = () => {
            const displayName = els.leaderboardName.value.trim();
            saveAttemptLeaderboardState({ status: 'skipped', displayName });
            closeWith(displayName, '', '');
        };
    }

    // Tallies this set's questions by book number, read from each question's
    // `_path` (added by api/questions.php as "<Language>/<bookNo>/<file>.json")
    // — the same path regardless of quiz type, so topic/character/place sets
    // that span several books still get a correct per-book breakdown.
    function getBookCountsForSet() {
        const counts = {};
        state.questions.forEach((q) => {
            if (!q._path) return;
            const bookNo = parseInt(q._path.split('/')[1], 10);
            if (bookNo >= 1 && bookNo <= 66) {
                counts[bookNo] = (counts[bookNo] || 0) + 1;
            }
        });
        return counts;
    }

    // Fire-and-forget: records that this set was finished toward the
    // site-wide "quizzes completed" / "questions answered" / "questions by
    // book" counters shown in the footer and admin screen. Never blocks or
    // affects the player's own results on failure.
    function submitQuizStats(totalQuestions) {
        try {
            fetch('api/stats.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    lang: state.lang,
                    totalQuestions,
                    bookCounts: getBookCountsForSet(),
                }),
            }).catch(() => {});
        } catch (err) {
            // ignore — stats are best-effort
        }
    }

    function finishSet() {
        clearTimer();
        els.play.hidden = true;

        const stats = getScoreStats();
        const { correct: correctCount, total, pct } = stats;

        submitQuizStats(total);

        renderScoreCharts(stats);
        els.setScoreSummary.textContent = `You answered ${correctCount} out of ${total} correctly in this set.`;
        if (pct >= 80) {
            els.setEncouragement.textContent = 'Excellent work! Keep going with the next set.';
        } else if (pct >= 50) {
            els.setEncouragement.textContent = 'Good effort! Review the verses and try the next set.';
        } else {
            els.setEncouragement.textContent = 'Keep studying — every question helps you learn Scripture.';
        }

        const hasNext = state.sets.length > 0 && state.setIndex + 1 < state.sets.length;
        els.nextSetBtn.hidden = !hasNext;
        els.nextSetFromReviewBtn.hidden = !hasNext;

        const attemptState = readAttemptLeaderboardState();

        if (attemptState && attemptState.status === 'joined') {
            // Already joined earlier in this attempt — keep adding this
            // set's score to the leaderboard's lifetime total, without
            // asking again. correctCount/total here are THIS set's own
            // numbers (not an attempt-cumulative total) — the server does
            // the accumulating.
            submitLeaderboardScore(
                attemptState.displayName, attemptState.countryCode, attemptState.mobile,
                correctCount, total
            ).then((result) => {
                revealResults(attemptState.displayName, attemptState.countryCode, attemptState.mobile);
                showSaveStatus(result);
                maybeShowCongratsOnce(attemptState, result && result.rank);
            });
            return;
        }

        if (attemptState && attemptState.status === 'skipped') {
            revealResults(attemptState.displayName || '', '', '');
            return;
        }

        // First set of this attempt and no decision made yet — ask now,
        // rather than waiting for the whole quiz (which, for a long book
        // like Genesis split into many 10-question sets, may never finish
        // in one sitting).
        openLeaderboardModal(correctCount, total);
    }

    function buildReviewListHtml() {
        return state.questions.map((q, i) => {
            const chosen = state.answers[i];
            const correct = parseInt(q.correct_option, 10);
            const isRight = chosen === correct;
            const chosenText = chosen !== undefined ? q.options[chosen] : '(no answer)';
            const correctText = q.options[correct];
            const statusClass = isRight ? 'review-correct' : 'review-wrong';
            const statusLabel = isRight ? 'Correct' : 'Wrong';
            const iconMarkup = isRight
                ? '<span class="review-status-icon is-correct" aria-hidden="true">✓</span>'
                : '<span class="review-status-icon is-wrong" aria-hidden="true">✕</span>';

            return `
                <article class="review-item ${statusClass}">
                    <div class="review-item-layout">
                        ${iconMarkup}
                        <div class="review-item-body">
                            <p class="review-status-text">${statusLabel}</p>
                            <h3>${escapeHtml(q.question)}</h3>
                            <p><strong>Your answer:</strong> <span class="your-answer">${escapeHtml(chosenText)}</span></p>
                            <p><strong>Correct:</strong> ${escapeHtml(correctText)} <span class="verse-ref">(${escapeHtml(q.verse_reference || '')})</span></p>
                            <p class="explanation">${escapeHtml(q.explanation || '')}</p>
                        </div>
                    </div>
                </article>
            `;
        }).join('');
    }

    function renderReview() {
        const stats = getScoreStats();
        renderScoreCharts(stats);
        els.reviewList.innerHTML = buildReviewListHtml();
    }

    function nextSetUrl() {
        const params = new URLSearchParams(window.location.search);
        params.set('set', String(state.setIndex + 1));
        return `quiz.php?${params.toString()}`;
    }

    els.nextBtn.addEventListener('click', () => {
        if (state.current < state.questions.length - 1) {
            state.current += 1;
            renderQuestion();
            els.nextBtn.disabled = true;
            els.nextBtn.textContent = state.current >= state.questions.length - 1
                ? 'Finish set'
                : 'Next question';
        } else {
            finishSet();
        }
    });

    els.reviewBtn.addEventListener('click', () => {
        renderReview();
        els.setResults.hidden = true;
        els.review.hidden = false;
    });

    els.backFromReviewBtn.addEventListener('click', () => {
        els.review.hidden = true;
        els.setResults.hidden = false;
    });

    function loadScriptOnce(src) {
        return new Promise((resolve, reject) => {
            if (document.querySelector(`script[src="${src}"]`)) {
                resolve();
                return;
            }
            const script = document.createElement('script');
            script.src = src;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Could not load ' + src));
            document.head.appendChild(script);
        });
    }

    // Native-text PDF generation (jsPDF only, no html2canvas/rasterizing) —
    // keeps the file a few KB instead of tens of MB of embedded page images,
    // and lets the "Powered by" credit be a real clickable link via
    // pdf.textWithLink() instead of a flattened pixel of text. Colors/badges
    // below are drawn with jsPDF's own fill/rect/circle primitives, so they
    // stay crisp vector shapes rather than screenshots.
    const PDF_MARGIN = 36;
    const PDF_LINE_HEIGHT = 14;
    const PDF_BRAND = [109, 40, 217]; // purple, matches the site's theme
    const PDF_BRAND_LIGHT = [243, 238, 255];

    function pdfScoreColor(pct) {
        if (pct >= 80) return [22, 163, 74]; // green
        if (pct >= 50) return [217, 119, 6]; // amber
        return [220, 38, 38]; // red
    }

    function pdfAddFooterLink(pdf, label) {
        const pageWidth = pdf.internal.pageSize.getWidth();
        const pageHeight = pdf.internal.pageSize.getHeight();
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(9);
        pdf.setTextColor(120, 120, 120);
        const y = pageHeight - 16;
        pdf.text(`${label} `, PDF_MARGIN, y);
        const labelWidth = pdf.getTextWidth(`${label} `);
        pdf.setFont('helvetica', 'bold');
        pdf.setTextColor(PDF_BRAND[0], PDF_BRAND[1], PDF_BRAND[2]);
        pdf.textWithLink('Bible Quiz', PDF_MARGIN + labelWidth, y, { url: 'https://wordofgod.in/bible-quiz' });
        pdf.setFont('helvetica', 'normal');
        pdf.setTextColor(0, 0, 0);
    }

    // Tracks the current write position and starts a new page (with margin
    // reset) whenever the next line would run past the bottom margin.
    function makePdfCursor(pdf) {
        const pageHeight = pdf.internal.pageSize.getHeight();
        const bottomLimit = pageHeight - PDF_MARGIN - 20;
        let y = PDF_MARGIN;
        return {
            ensure(lines) {
                if (y + lines * PDF_LINE_HEIGHT > bottomLimit) {
                    pdf.addPage();
                    y = PDF_MARGIN;
                }
            },
            lineHeight(size) {
                return Math.max(PDF_LINE_HEIGHT, size * 1.4);
            },
            writeLines(linesArr, size, color) {
                const lh = this.lineHeight(size);
                pdf.setFontSize(size);
                if (color) pdf.setTextColor(color[0], color[1], color[2]);
                linesArr.forEach((line) => {
                    this.ensure(1);
                    pdf.text(line, PDF_MARGIN, y);
                    y += lh;
                });
                pdf.setTextColor(0, 0, 0);
            },
            writeCentered(linesArr, size, color) {
                const pageWidth = pdf.internal.pageSize.getWidth();
                const lh = this.lineHeight(size);
                pdf.setFontSize(size);
                if (color) pdf.setTextColor(color[0], color[1], color[2]);
                linesArr.forEach((line) => {
                    this.ensure(1);
                    const w = pdf.getTextWidth(line);
                    pdf.text(line, (pageWidth - w) / 2, y);
                    y += lh;
                });
                pdf.setTextColor(0, 0, 0);
            },
            addGap(amount) {
                y += amount;
            },
            get y() { return y; },
            set y(value) { y = value; },
        };
    }

    function pdfWrapped(pdf, text, size, maxWidth) {
        pdf.setFontSize(size);
        return pdf.splitTextToSize(text || '', maxWidth);
    }

    // Strips the "Show number"/"Hide number" toggle button text out of the
    // player-info line before it goes in the PDF — that control only makes
    // sense on screen, not in a static document.
    function pdfPlayerInfoText() {
        if (!els.leaderboardPlayerInfo || els.leaderboardPlayerInfo.hidden) return '';
        const clone = els.leaderboardPlayerInfo.cloneNode(true);
        const toggle = clone.querySelector('.player-mobile-toggle');
        if (toggle) toggle.remove();
        return clone.textContent.replace(/\s+/g, ' ').trim();
    }

    function buildResultsPdf(pdf, stats) {
        const pageWidth = pdf.internal.pageSize.getWidth();
        const maxWidth = pageWidth - PDF_MARGIN * 2;
        const { correct, wrong, unanswered, total, pct } = stats;
        const scoreColor = pdfScoreColor(pct);

        // Brand header banner
        const bannerHeight = 58;
        pdf.setFillColor(PDF_BRAND[0], PDF_BRAND[1], PDF_BRAND[2]);
        pdf.rect(0, 0, pageWidth, bannerHeight, 'F');
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(20);
        pdf.setTextColor(255, 255, 255);
        const titleLines = pdf.splitTextToSize(state.pdfHeading, maxWidth);
        let bannerY = 26;
        titleLines.forEach((line) => {
            const w = pdf.getTextWidth(line);
            pdf.text(line, (pageWidth - w) / 2, bannerY);
            bannerY += 20;
        });
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(10);
        pdf.setTextColor(230, 220, 255);
        const sub = 'wordofgod.in/bible-quiz';
        pdf.text(sub, (pageWidth - pdf.getTextWidth(sub)) / 2, bannerHeight - 8);
        pdf.setTextColor(0, 0, 0);

        const cursor = makePdfCursor(pdf);
        cursor.y = bannerHeight + 26;

        const playerInfo = pdfPlayerInfoText();
        if (playerInfo) {
            pdf.setFont('helvetica', 'normal');
            cursor.writeCentered(pdfWrapped(pdf, playerInfo, 11, maxWidth), 11, [70, 70, 70]);
            cursor.addGap(10);
        }

        // Quiz details card — padded on all sides so the text never touches
        // the card's border.
        const CARD_PAD_X = 14;
        const CARD_PAD_Y = 12;
        const CARD_GAP_AFTER = 18;
        const detailRows = els.setResults.querySelectorAll('.quiz-details .quiz-detail-row');
        if (detailRows.length) {
            const lines = [];
            detailRows.forEach((row) => {
                const dt = row.querySelector('dt');
                const dd = row.querySelector('dd');
                if (!dt || !dd) return;
                lines.push(`${dt.textContent.trim()}: ${dd.textContent.trim()}`);
            });
            pdf.setFontSize(10.5);
            const innerWidth = maxWidth - CARD_PAD_X * 2;
            const wrappedLines = lines.flatMap((line) => pdf.splitTextToSize(line, innerWidth));
            const cardLineHeight = cursor.lineHeight(10.5);
            const cardHeight = wrappedLines.length * cardLineHeight + CARD_PAD_Y * 2;
            cursor.ensure(Math.ceil(cardHeight / PDF_LINE_HEIGHT) + 1);
            const cardTop = cursor.y;
            pdf.setFillColor(PDF_BRAND_LIGHT[0], PDF_BRAND_LIGHT[1], PDF_BRAND_LIGHT[2]);
            pdf.roundedRect(PDF_MARGIN, cardTop, maxWidth, cardHeight, 6, 6, 'F');

            pdf.setFont('helvetica', 'normal');
            pdf.setTextColor(60, 60, 60);
            let lineY = cardTop + CARD_PAD_Y + 8;
            wrappedLines.forEach((line) => {
                pdf.text(line, PDF_MARGIN + CARD_PAD_X, lineY);
                lineY += cardLineHeight;
            });
            pdf.setTextColor(0, 0, 0);

            cursor.y = cardTop + cardHeight;
            cursor.addGap(CARD_GAP_AFTER);
        }

        // Big colored score card
        const cardWidth = maxWidth;
        const cardHeight = 86;
        cursor.ensure(Math.ceil(cardHeight / PDF_LINE_HEIGHT) + 1);
        const cardTop = cursor.y;
        pdf.setDrawColor(scoreColor[0], scoreColor[1], scoreColor[2]);
        pdf.setLineWidth(1.5);
        pdf.setFillColor(255, 255, 255);
        pdf.roundedRect(PDF_MARGIN, cardTop, cardWidth, cardHeight, 8, 8, 'FD');

        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(30);
        pdf.setTextColor(scoreColor[0], scoreColor[1], scoreColor[2]);
        const pctText = `${pct}%`;
        pdf.text(pctText, (pageWidth - pdf.getTextWidth(pctText)) / 2, cardTop + 38);

        pdf.setFontSize(14);
        const fractionText = `${correct} / ${total} correct`;
        pdf.text(fractionText, (pageWidth - pdf.getTextWidth(fractionText)) / 2, cardTop + 58);

        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(9.5);
        pdf.setTextColor(110, 110, 110);
        const breakdown = `Wrong: ${wrong}   Unanswered: ${unanswered}`;
        pdf.text(breakdown, (pageWidth - pdf.getTextWidth(breakdown)) / 2, cardTop + 74);
        pdf.setTextColor(0, 0, 0);

        cursor.y = cardTop + cardHeight + 20;

        if (els.setEncouragement && els.setEncouragement.textContent.trim()) {
            pdf.setFont('helvetica', 'bold');
            cursor.writeCentered(
                pdfWrapped(pdf, els.setEncouragement.textContent.trim(), 11.5, maxWidth),
                11.5,
                scoreColor
            );
            pdf.setFont('helvetica', 'normal');
        }

        return cursor;
    }

    function pdfDrawBadge(pdf, cursor, text, color) {
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(9.5);
        const textWidth = pdf.getTextWidth(text);
        const paddingX = 10;
        const paddingY = 5;
        const badgeWidth = textWidth + paddingX * 2;
        const badgeHeight = 9.5 + paddingY * 2;
        cursor.ensure(1);
        pdf.setFillColor(color[0], color[1], color[2]);
        pdf.roundedRect(PDF_MARGIN, cursor.y - badgeHeight + paddingY + 2, badgeWidth, badgeHeight, 4, 4, 'F');
        pdf.setTextColor(255, 255, 255);
        pdf.text(text, PDF_MARGIN + paddingX, cursor.y);
        pdf.setTextColor(0, 0, 0);
        cursor.addGap(cursor.lineHeight(9.5) + 6);
        pdf.setFont('helvetica', 'normal');
    }

    function buildReviewPdf(pdf, stats) {
        if (!state.questions.length) return;

        pdf.addPage();
        const cursor = makePdfCursor(pdf);
        const pageWidth = pdf.internal.pageSize.getWidth();
        const maxWidth = pageWidth - PDF_MARGIN * 2;

        pdf.setFont('helvetica', 'bold');
        cursor.writeLines(pdfWrapped(pdf, state.pdfReviewHeading, 16, maxWidth), 16, PDF_BRAND);
        cursor.addGap(2);
        pdf.setFont('helvetica', 'normal');
        cursor.writeLines([`${stats.correct} / ${stats.total} correct (${stats.pct}%)`], 10.5, [100, 100, 100]);
        cursor.addGap(10);

        state.questions.forEach((q, i) => {
            const chosen = state.answers[i];
            const correct = parseInt(q.correct_option, 10);
            const isRight = chosen === correct;
            const chosenText = chosen !== undefined ? q.options[chosen] : '(no answer)';
            const correctText = q.options[correct];
            const statusColor = isRight ? [22, 163, 74] : [220, 38, 38];

            pdfDrawBadge(pdf, cursor, `${i + 1}  ${isRight ? 'CORRECT' : 'WRONG'}`, statusColor);

            pdf.setFont('helvetica', 'bold');
            cursor.writeLines(pdfWrapped(pdf, q.question, 12, maxWidth), 12);

            pdf.setFont('helvetica', 'normal');
            cursor.writeLines(pdfWrapped(pdf, `Your answer: ${chosenText}`, 10.5, maxWidth), 10.5, statusColor);

            const correctLine = q.verse_reference
                ? `Correct: ${correctText}  (${q.verse_reference})`
                : `Correct: ${correctText}`;
            cursor.writeLines(pdfWrapped(pdf, correctLine, 10.5, maxWidth), 10.5, [22, 163, 74]);

            if (q.explanation) {
                pdf.setFont('helvetica', 'italic');
                cursor.writeLines(pdfWrapped(pdf, q.explanation, 9.5, maxWidth), 9.5, [110, 110, 110]);
                pdf.setFont('helvetica', 'normal');
            }

            cursor.addGap(6);
            cursor.ensure(1);
            pdf.setDrawColor(225, 220, 235);
            pdf.setLineWidth(0.5);
            pdf.line(PDF_MARGIN, cursor.y - 8, pageWidth - PDF_MARGIN, cursor.y - 8);
            cursor.addGap(6);
        });
    }

    if (els.downloadPdfBtn) {
        els.downloadPdfBtn.addEventListener('click', async () => {
            els.downloadPdfBtn.disabled = true;
            const originalText = els.downloadPdfBtn.textContent;
            els.downloadPdfBtn.textContent = 'Preparing PDF…';
            try {
                await loadScriptOnce('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js');

                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a5' });
                const stats = getScoreStats();

                buildResultsPdf(pdf, stats);
                buildReviewPdf(pdf, stats);

                const totalPages = pdf.internal.getNumberOfPages();
                for (let p = 1; p <= totalPages; p += 1) {
                    pdf.setPage(p);
                    pdfAddFooterLink(pdf, state.pdfPoweredByLabel);
                }

                pdf.save('quiz-results-from-wordofgod.in.pdf');
            } catch (err) {
                window.alert('Could not generate the PDF. Please try again.');
            } finally {
                els.downloadPdfBtn.disabled = false;
                els.downloadPdfBtn.textContent = originalText;
            }
        });
    }

    els.nextSetBtn.addEventListener('click', () => {
        window.location.href = nextSetUrl();
    });
    els.nextSetFromReviewBtn.addEventListener('click', () => {
        window.location.href = nextSetUrl();
    });

    async function init() {
        try {
            const indexRes = await fetch(indexUrl());
            if (!indexRes.ok) throw new Error('Quiz not found. Ask the admin to add quiz.');
            state.indexData = await indexRes.json();
            const allPaths = state.indexData.paths
                || (state.indexData.sets || []).flat();
            if (!allPaths.length) throw new Error('No questions available for this quiz selection.');

            state.sets = chunkPaths(getShuffledPaths(allPaths), state.setSize);
            if (!state.sets.length) throw new Error('No questions available for this quiz selection.');

            const setIdx = Math.min(state.setIndex, state.sets.length - 1);
            const paths = state.sets[setIdx];
            if (!paths || !paths.length) throw new Error('This quiz set is empty.');

            const qRes = await fetch('api/questions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ paths }),
            });
            if (!qRes.ok) throw new Error('Could not load questions.');
            const payload = await qRes.json();
            state.questions = payload.questions || [];
            if (!state.questions.length) throw new Error('No questions loaded.');

            els.loading.hidden = true;
            els.play.hidden = false;
            state.current = 0;
            state.answers = [];
            renderQuestion();
            startTimer();
        } catch (err) {
            showError(err.message || 'Something went wrong.');
        }
    }

    init();
});
