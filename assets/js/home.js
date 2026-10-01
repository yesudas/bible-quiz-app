document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('quizSetupForm');
    const typeSelect = document.getElementById('type');
    const langSelect = document.getElementById('lang');
    const bookCheckboxList = document.getElementById('bookCheckboxList');
    const bookSelectAll = document.getElementById('bookSelectAll');
    const bookClearAll = document.getElementById('bookClearAll');
    const bookPickerHint = document.getElementById('bookPickerHint');
    const chapterPickerSection = document.getElementById('chapterPickerSection');
    const chapterPickerHint = document.getElementById('chapterPickerHint');
    const chapterPickerActions = document.getElementById('chapterPickerActions');
    const chapterCheckboxList = document.getElementById('chapterCheckboxList');
    const chapterSelectAll = document.getElementById('chapterSelectAll');
    const chapterClearAll = document.getElementById('chapterClearAll');
    const topicSelect = document.getElementById('topic');
    const characterSelect = document.getElementById('character');
    const placeSelect = document.getElementById('place');
    const timedCheckbox = document.getElementById('timed');
    const timedOptions = document.getElementById('timedOptions');
    const timeValueInput = document.getElementById('time_value');
    const indexHint = document.getElementById('indexHint');
    const shareBtn = document.getElementById('shareQuizBtn');
    const shareResult = document.getElementById('shareResult');
    const shareUrlInput = document.getElementById('shareUrlInput');
    const shareCopyBtn = document.getElementById('shareCopyBtn');
    const shareResultMsg = document.getElementById('shareResultMsg');
    const booksOverviewSection = document.getElementById('booksOverviewSection');
    const bookLinksList = document.getElementById('bookLinksList');
    const topicsOverviewSection = document.getElementById('topicsOverviewSection');
    const topicLinksList = document.getElementById('topicLinksList');
    const charactersOverviewSection = document.getElementById('charactersOverviewSection');
    const characterLinksList = document.getElementById('characterLinksList');
    const placesOverviewSection = document.getElementById('placesOverviewSection');
    const placeLinksList = document.getElementById('placeLinksList');

    let catalog = null;

    const conditionalFields = () => document.querySelectorAll('.conditional-field');

    function rowShowsFor(row, type) {
        const modes = (row.getAttribute('data-show-for') || '').trim().split(/\s+/).filter(Boolean);
        return modes.includes(type);
    }

    function createCheckboxItem({ name, value, label, checked, disabled }) {
        const item = document.createElement('label');
        item.className = 'checkbox-item';
        const input = document.createElement('input');
        input.type = 'checkbox';
        if (name) input.name = name;
        input.value = value;
        input.checked = checked;
        input.disabled = disabled;
        const text = document.createElement('span');
        text.className = 'checkbox-item-text';
        text.textContent = label;
        item.appendChild(input);
        item.appendChild(text);
        return item;
    }

    function getSelectedBooks() {
        if (!bookCheckboxList) return [];
        return [...bookCheckboxList.querySelectorAll('input[type="checkbox"]:checked')].map((i) => i.value);
    }

    function booksFromQuery() {
        const params = new URLSearchParams(window.location.search);
        const keys = params.getAll('books[]');
        if (!keys.length && params.get('book')) {
            keys.push(params.get('book'));
        }
        return new Set(keys.map((k) => k.replace(/\D/g, '').padStart(2, '0')).filter((k) => /^\d{2}$/.test(k)));
    }

    function chaptersFromQuery() {
        const params = new URLSearchParams(window.location.search);
        const keys = params.getAll('chapters[]');
        if (!keys.length && params.get('chapter')) {
            keys.push(params.get('chapter'));
        }
        return new Set(keys.filter((k) => /^\d{2}-\d{3}$/.test(k)));
    }

    function chaptersForBook(bookKey) {
        if (!catalog) return [];
        const book = catalog.books.find((b) => b.key === bookKey);
        if (!book) return [];
        const chapterWord = window.QUIZ_I18N?.chapterWord || 'Chapter';
        return book.chapters.map((ch) => ({
            value: `${book.key}-${String(ch).padStart(3, '0')}`,
            label: `${chapterWord} ${ch}`,
        }));
    }

    function renderBookCheckboxes(selectedKeys = new Set()) {
        if (!bookCheckboxList || !catalog) return;

        const active = typeSelect.value === 'book' || typeSelect.value === 'chapter';
        bookCheckboxList.innerHTML = '';

        catalog.books.forEach((book) => {
            const item = createCheckboxItem({
                name: 'books[]',
                value: book.key,
                label: `${book.name} (${book.question_count})`,
                checked: selectedKeys.has(book.key),
                disabled: !active,
            });
            item.querySelector('input').addEventListener('change', onBookSelectionChange);
            bookCheckboxList.appendChild(item);
        });

        if (bookSelectAll) bookSelectAll.disabled = !active || !catalog.books.length;
        if (bookClearAll) bookClearAll.disabled = !active || !catalog.books.length;

        if (bookPickerHint) {
            bookPickerHint.textContent = typeSelect.value === 'chapter'
                ? 'Select one book for chapter quizzes, or multiple books to quiz whole books.'
                : 'Select one or more books.';
        }

        onBookSelectionChange();
    }

    function renderChapterCheckboxes(bookKey, selectedKeys = new Set()) {
        if (!chapterCheckboxList) return;

        const chapters = chaptersForBook(bookKey);
        chapterCheckboxList.innerHTML = '';

        chapters.forEach(({ value, label }) => {
            chapterCheckboxList.appendChild(createCheckboxItem({
                name: 'chapters[]',
                value,
                label,
                checked: selectedKeys.has(value),
                disabled: false,
            }));
        });

        if (chapterSelectAll) chapterSelectAll.disabled = !chapters.length;
        if (chapterClearAll) chapterClearAll.disabled = !chapters.length;
    }

    function clearChapterInputs() {
        if (!chapterCheckboxList) return;
        chapterCheckboxList.querySelectorAll('input[type="checkbox"]').forEach((input) => {
            input.checked = false;
            input.disabled = true;
            input.removeAttribute('name');
        });
    }

    function onBookSelectionChange() {
        if (typeSelect.value !== 'chapter') {
            return;
        }

        const selected = getSelectedBooks();

        if (selected.length === 1) {
            if (chapterPickerHint) {
                chapterPickerHint.textContent = 'Select one or more chapters from the chosen book.';
            }
            if (chapterPickerActions) chapterPickerActions.hidden = false;
            if (chapterCheckboxList) chapterCheckboxList.hidden = false;

            const bookPrefix = `${selected[0]}-`;
            const preserved = new Set();
            chapterCheckboxList?.querySelectorAll('input[type="checkbox"]:checked').forEach((input) => {
                if (input.value.startsWith(bookPrefix)) {
                    preserved.add(input.value);
                }
            });
            if (!preserved.size) {
                chaptersFromQuery().forEach((key) => {
                    if (key.startsWith(bookPrefix)) {
                        preserved.add(key);
                    }
                });
            }
            renderChapterCheckboxes(selected[0], preserved);
            chapterCheckboxList?.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                input.disabled = false;
                input.name = 'chapters[]';
            });
            return;
        }

        clearChapterInputs();

        if (chapterPickerActions) chapterPickerActions.hidden = true;
        if (chapterCheckboxList) {
            chapterCheckboxList.hidden = false;
            if (selected.length > 1) {
                chapterCheckboxList.innerHTML = '<p class="field-hint">Chapter selection is available when exactly one book is selected.</p>';
                if (chapterPickerHint) {
                    chapterPickerHint.textContent = 'Multiple books selected — the quiz will use all questions from those books.';
                }
            } else {
                chapterCheckboxList.innerHTML = '<p class="field-hint">Select exactly one book to choose chapters.</p>';
                if (chapterPickerHint) {
                    chapterPickerHint.textContent = 'Select one book above to choose chapters.';
                }
            }
        }
    }

    function syncConditionalFields() {
        const type = typeSelect.value;
        conditionalFields().forEach((row) => {
            const show = rowShowsFor(row, type);
            row.hidden = !show;
            row.querySelectorAll('select').forEach((sel) => {
                sel.disabled = !show;
                sel.required = false;
            });
        });

        const booksActive = type === 'book' || type === 'chapter';
        bookCheckboxList?.querySelectorAll('input[type="checkbox"]').forEach((input) => {
            input.disabled = !booksActive;
        });
        if (bookSelectAll) bookSelectAll.disabled = !booksActive;
        if (bookClearAll) bookClearAll.disabled = !booksActive;

        if (bookPickerHint) {
            bookPickerHint.textContent = type === 'chapter'
                ? 'Select one book for chapter quizzes, or multiple books to quiz whole books.'
                : 'Select one or more books.';
        }

        if (type === 'chapter') {
            onBookSelectionChange();
        } else if (chapterPickerSection) {
            if (chapterPickerActions) chapterPickerActions.hidden = true;
            if (chapterCheckboxList) chapterCheckboxList.hidden = true;
            clearChapterInputs();
        }
    }

    function fillSelect(select, items, valueKey, labelKey) {
        // A deep link / "Quiz by Theme or Character" click can preselect a
        // specific slug (see index.php's data-preselect); apply it once, the
        // first time this select is populated, then forget it so a later
        // language switch doesn't keep forcing the old selection back.
        const preselect = select.dataset.preselect;
        delete select.dataset.preselect;

        select.innerHTML = '';
        items.forEach((item) => {
            const opt = document.createElement('option');
            opt.value = item[valueKey];
            opt.textContent = item[labelKey];
            select.appendChild(opt);
        });
        select.disabled = items.length === 0;

        if (preselect && items.some((item) => String(item[valueKey]) === preselect)) {
            select.value = preselect;
        }
    }

    // Renders the "browse by book / theme / character" chip lists shown under
    // Quiz modes, and hides that section entirely when there's nothing to list
    // (e.g. a language with no data yet, or a data set with no themes tagged).
    function renderItemLinks(section, list, items, { type, lang, param, valueKey, labelKey }) {
        if (!section || !list) return;
        section.hidden = items.length === 0;
        list.innerHTML = '';
        items.forEach((item) => {
            const url = new URL('quiz.php', window.location.href);
            url.searchParams.set('type', type);
            url.searchParams.set('lang', lang);
            url.searchParams.append(param, item[valueKey]);

            const a = document.createElement('a');
            a.className = 'item-chip';
            a.href = `${url.pathname}${url.search}`;
            a.append(`${item[labelKey]} `);

            const count = document.createElement('span');
            count.className = 'count';
            count.textContent = `(${item.question_count})`;
            a.appendChild(count);

            list.appendChild(a);
        });
    }

    // Applies the server-computed labels bundled with the catalog response, so
    // switching language can re-translate quiz type / difficulty text via AJAX
    // instead of a full page reload.
    function applyLabels(labels) {
        if (!labels) return;

        if (labels.types) {
            document.querySelectorAll('#type option').forEach((opt) => {
                if (labels.types[opt.value]) opt.textContent = labels.types[opt.value];
            });
            // Covers both the mode tiles (<a>) and the "Quiz by Book/Topic/
            // Character" section headings (<h2>) — anything tagged with the
            // quiz type it represents.
            document.querySelectorAll('[data-type-key]').forEach((el) => {
                const key = el.dataset.typeKey;
                if (labels.types[key]) el.textContent = labels.types[key];
            });
        }

        if (labels.difficulties) {
            document.querySelectorAll('[data-difficulty-label]').forEach((el) => {
                const key = el.dataset.difficultyLabel;
                if (labels.difficulties[key]) el.textContent = labels.difficulties[key];
            });
        }

        if (labels.chapterWord) {
            window.QUIZ_I18N = window.QUIZ_I18N || {};
            window.QUIZ_I18N.chapterWord = labels.chapterWord;
        }
    }

    // Mode tiles link straight to quiz.php with a hard-coded lang= from the page
    // that rendered them; keep that in sync so they still point at whichever
    // language is currently selected after an AJAX-only language switch.
    function updateModeTileLinks(lang) {
        document.querySelectorAll('.type-grid a[data-type-key]').forEach((a) => {
            const url = new URL(a.getAttribute('href'), window.location.href);
            url.searchParams.set('lang', lang);
            a.setAttribute('href', `${url.pathname}${url.search}`);
        });
    }

    async function loadCatalog(lang) {
        const res = await fetch(`api/catalog.php?lang=${encodeURIComponent(lang)}`);
        if (!res.ok) {
            indexHint.textContent = 'Quiz not found. Ask the admin to add quiz.';
            return;
        }
        catalog = await res.json();
        indexHint.textContent = `${catalog.question_count} questions are available in ${catalog.labels?.language || catalog.language}.`;

        applyLabels(catalog.labels);
        updateModeTileLinks(lang);

        renderItemLinks(booksOverviewSection, bookLinksList, catalog.books, {
            type: 'book', lang, param: 'books[]', valueKey: 'key', labelKey: 'name',
        });
        renderItemLinks(topicsOverviewSection, topicLinksList, catalog.topics, {
            type: 'topic', lang, param: 'topic', valueKey: 'slug', labelKey: 'label',
        });
        renderItemLinks(charactersOverviewSection, characterLinksList, catalog.characters, {
            type: 'character', lang, param: 'character', valueKey: 'slug', labelKey: 'label',
        });
        renderItemLinks(placesOverviewSection, placeLinksList, catalog.places || [], {
            type: 'place', lang, param: 'place', valueKey: 'slug', labelKey: 'label',
        });

        fillSelect(
            topicSelect,
            catalog.topics.map((t) => ({
                slug: t.slug,
                label: `${t.label} (${t.question_count})`,
            })),
            'slug',
            'label'
        );

        fillSelect(
            characterSelect,
            catalog.characters.map((c) => ({
                slug: c.slug,
                label: `${c.label} (${c.question_count})`,
            })),
            'slug',
            'label'
        );

        if (placeSelect) {
            fillSelect(
                placeSelect,
                (catalog.places || []).map((p) => ({
                    slug: p.slug,
                    label: `${p.label} (${p.question_count})`,
                })),
                'slug',
                'label'
            );
        }

        // A language switch clears book selections before calling loadCatalog(),
        // so this only preselects from currently-checked boxes on the initial
        // page load (deep link / shared link's books[]/chapters[] in the URL).
        const preselectedBooks = new Set(getSelectedBooks());
        if (!preselectedBooks.size) {
            booksFromQuery().forEach((k) => preselectedBooks.add(k));
            if (!preselectedBooks.size && chaptersFromQuery().size) {
                preselectedBooks.add(chaptersFromQuery().values().next().value.slice(0, 2));
            }
        }
        renderBookCheckboxes(preselectedBooks);

        // fillSelect() re-enables topic/character based on whether options exist,
        // which ignores the currently selected quiz type. Re-apply visibility so a
        // hidden topic/character select can't stay enabled and leak its value into
        // the submitted form (or a share link) for an unrelated quiz type.
        syncConditionalFields();
    }

    function setupQtyStepper(stepperEl) {
        const input = stepperEl.querySelector('.qty-stepper-input');
        if (!input) return;

        const decBtn = stepperEl.querySelector('.qty-stepper-btn[data-step="-1"]');
        const incBtn = stepperEl.querySelector('.qty-stepper-btn[data-step="1"]');

        const bounds = () => ({
            min: parseInt(input.min, 10) || 0,
            max: parseInt(input.max, 10) || 9999,
            step: parseInt(input.step, 10) || 1,
        });

        const clamp = (value) => {
            const { min, max } = bounds();
            return Math.min(max, Math.max(min, value));
        };

        const syncDisabled = () => {
            const off = input.disabled;
            stepperEl.classList.toggle('is-disabled', off);
            if (decBtn) decBtn.disabled = off;
            if (incBtn) incBtn.disabled = off;
        };

        const bump = (direction) => {
            if (input.disabled) return;
            const { min, step } = bounds();
            const current = parseInt(input.value, 10);
            const base = Number.isFinite(current) ? current : min;
            input.value = String(clamp(base + direction * step));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        };

        const bindHold = (btn, direction) => {
            if (!btn) return;
            let holdTimeout = null;
            let holdInterval = null;

            const stopHold = () => {
                clearTimeout(holdTimeout);
                clearInterval(holdInterval);
                holdTimeout = null;
                holdInterval = null;
            };

            const startHold = (event) => {
                if (input.disabled) return;
                event.preventDefault();
                bump(direction);
                stopHold();
                holdTimeout = setTimeout(() => {
                    holdInterval = setInterval(() => bump(direction), 75);
                }, 350);
            };

            btn.addEventListener('mousedown', startHold);
            btn.addEventListener('touchstart', startHold, { passive: false });
            btn.addEventListener('mouseup', stopHold);
            btn.addEventListener('mouseleave', stopHold);
            btn.addEventListener('touchend', stopHold);
            btn.addEventListener('touchcancel', stopHold);
            btn.addEventListener('blur', stopHold);
        };

        bindHold(decBtn, -1);
        bindHold(incBtn, 1);

        input.addEventListener('change', () => {
            const { min } = bounds();
            const parsed = parseInt(input.value, 10);
            input.value = String(clamp(Number.isFinite(parsed) ? parsed : min));
        });

        syncDisabled();
        new MutationObserver(syncDisabled).observe(input, { attributes: true, attributeFilter: ['disabled'] });
    }

    function syncTimeValueLimits() {
        if (!timeValueInput) return;
        const unit = document.querySelector('input[name="time_unit"]:checked')?.value || 'seconds';
        const current = parseInt(timeValueInput.value, 10);

        if (unit === 'minutes') {
            timeValueInput.min = '1';
            timeValueInput.max = '60';
            timeValueInput.step = '1';
            if (!Number.isFinite(current) || current > 60) {
                timeValueInput.value = '5';
            } else if (current < 1) {
                timeValueInput.value = '1';
            }
        } else {
            timeValueInput.min = '30';
            timeValueInput.max = '3600';
            timeValueInput.step = '5';
            if (!Number.isFinite(current) || current < 30) {
                timeValueInput.value = '120';
            } else if (current > 3600) {
                timeValueInput.value = '3600';
            }
        }
        timeValueInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    document.querySelectorAll('[data-qty-stepper]').forEach(setupQtyStepper);

    typeSelect.addEventListener('change', () => {
        syncConditionalFields();
        if (catalog) {
            renderBookCheckboxes(new Set(getSelectedBooks()));
        }
    });
    langSelect.addEventListener('change', () => {
        // Book/topic/character selections (and their URL params) are
        // language-specific — a topic slug picked in Tamil doesn't exist in
        // English's index, for example — so switching language resets the whole
        // quiz setup back to its defaults instead of trying to carry any of it
        // across. The catalog (books/topics/characters) and translated quiz
        // type / difficulty labels are then re-fetched and patched in place, no
        // full page reload.
        const lang = langSelect.value;

        typeSelect.value = 'random';
        const setSizeInput = document.getElementById('set_size');
        if (setSizeInput) setSizeInput.value = '10';
        timedCheckbox.checked = false;
        timedOptions.hidden = true;
        timeValueInput.disabled = true;
        document.querySelectorAll('input[name="difficulty[]"]').forEach((input) => {
            input.checked = false;
        });
        bookCheckboxList?.querySelectorAll('input[type="checkbox"]').forEach((input) => {
            input.checked = false;
        });
        clearChapterInputs();

        window.history.replaceState({}, '', `${window.location.pathname}?lang=${encodeURIComponent(lang)}`);

        syncConditionalFields();
        loadCatalog(lang);
    });

    if (bookSelectAll && bookCheckboxList) {
        bookSelectAll.addEventListener('click', () => {
            bookCheckboxList.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                if (!input.disabled) input.checked = true;
            });
            onBookSelectionChange();
        });
    }

    if (bookClearAll && bookCheckboxList) {
        bookClearAll.addEventListener('click', () => {
            bookCheckboxList.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                if (!input.disabled) input.checked = false;
            });
            onBookSelectionChange();
        });
    }

    if (chapterSelectAll && chapterCheckboxList) {
        chapterSelectAll.addEventListener('click', () => {
            chapterCheckboxList.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                if (!input.disabled && input.name) input.checked = true;
            });
        });
    }

    if (chapterClearAll && chapterCheckboxList) {
        chapterClearAll.addEventListener('click', () => {
            chapterCheckboxList.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                if (!input.disabled && input.name) input.checked = false;
            });
        });
    }

    timedCheckbox.addEventListener('change', () => {
        const on = timedCheckbox.checked;
        timedOptions.hidden = !on;
        timeValueInput.disabled = !on;
    });

    document.querySelectorAll('input[name="time_unit"]').forEach((radio) => {
        radio.addEventListener('change', syncTimeValueLimits);
    });

    function validateSelection() {
        const type = typeSelect.value;
        const selectedBooks = getSelectedBooks();

        if (type === 'book' && !selectedBooks.length) {
            bookCheckboxList?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return false;
        }

        if (type === 'chapter') {
            if (!selectedBooks.length) {
                bookCheckboxList?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return false;
            }
            if (selectedBooks.length === 1) {
                const checkedChapters = chapterCheckboxList?.querySelectorAll('input[name="chapters[]"]:checked') || [];
                if (!checkedChapters.length) {
                    chapterCheckboxList?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    return false;
                }
            } else {
                clearChapterInputs();
            }
        }

        if (type === 'topic' && !topicSelect.value) {
            topicSelect.focus();
            return false;
        }
        if (type === 'character' && !characterSelect.value) {
            characterSelect.focus();
            return false;
        }
        if (type === 'place' && placeSelect && !placeSelect.value) {
            placeSelect.focus();
            return false;
        }
        if (!timedCheckbox.checked) {
            timeValueInput.disabled = true;
        }
        return true;
    }

    form.addEventListener('submit', (event) => {
        if (!validateSelection()) {
            event.preventDefault();
        }
    });

    function showShareMessage(message, isError) {
        if (!shareResultMsg) return;
        shareResultMsg.textContent = message;
        shareResultMsg.classList.toggle('error', Boolean(isError));
    }

    if (shareBtn) {
        shareBtn.addEventListener('click', async () => {
            if (!validateSelection()) {
                return;
            }

            const originalLabel = shareBtn.textContent;
            shareBtn.disabled = true;
            shareBtn.textContent = 'Sharing…';

            try {
                const params = new URLSearchParams(new FormData(form));
                const res = await fetch(`api/share.php?${params.toString()}`);
                const data = await res.json().catch(() => ({}));

                if (!res.ok || !data.token) {
                    throw new Error(data.error || 'Could not create a shareable link.');
                }

                const basePath = location.pathname.replace(/[^/]*$/, '');
                const shareUrl = `${location.origin}${basePath}?test=${data.token}`;

                if (shareResult) shareResult.hidden = false;
                if (shareUrlInput) shareUrlInput.value = shareUrl;
                showShareMessage('', false);

                if (navigator.share) {
                    try {
                        await navigator.share({ title: 'Free Bible Quiz', url: shareUrl });
                    } catch (shareErr) {
                        // Share sheet dismissed or unsupported target — the link is still shown below.
                    }
                }
            } catch (err) {
                if (shareResult) shareResult.hidden = false;
                showShareMessage(err.message || 'Could not create a shareable link.', true);
            } finally {
                shareBtn.disabled = false;
                shareBtn.textContent = originalLabel;
            }
        });
    }

    if (shareCopyBtn) {
        shareCopyBtn.addEventListener('click', async () => {
            if (!shareUrlInput || !shareUrlInput.value) return;
            shareUrlInput.select();
            try {
                await navigator.clipboard.writeText(shareUrlInput.value);
                showShareMessage('Copied to clipboard!', false);
            } catch (err) {
                showShareMessage('Copy failed — select and copy manually.', true);
            }
        });
    }

    syncConditionalFields();
    syncTimeValueLimits();
    loadCatalog(langSelect.value);
});
