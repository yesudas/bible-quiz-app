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

    function buildScoreChartHtml(stats) {
        const { correct, wrong, unanswered, total, pct } = stats;
        if (!total) {
            return '';
        }
        const correctPct = (correct / total) * 100;
        const wrongPct = (wrong / total) * 100;
        const unansweredPct = (unanswered / total) * 100;
        const donutStyle = `--correct-end: ${correctPct}%; --wrong-end: ${correctPct + wrongPct}%;`;

        return `
            <div class="score-chart" role="img" aria-label="${correct} correct, ${wrong} wrong, ${unanswered} unanswered out of ${total}">
                <div class="score-donut" style="${donutStyle}">
                    <div class="score-donut-hole">
                        <span class="score-donut-fraction">${correct}/${total}</span>
                        <span class="score-donut-label">${pct}% correct</span>
                    </div>
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

    function finishSet() {
        clearTimer();
        els.play.hidden = true;

        const stats = getScoreStats();
        const { correct: correctCount, total, pct } = stats;

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

        els.setResults.hidden = false;
    }

    function renderReview() {
        const stats = getScoreStats();
        renderScoreCharts(stats);

        els.reviewList.innerHTML = state.questions.map((q, i) => {
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
