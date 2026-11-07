(function () {
    'use strict';

    function $(id) { return document.getElementById(id); }

    const loginWrap = $('achievementsLoginWrap');
    const identityWrap = $('achievementsIdentity');
    const avatarWrap = $('achievementsAvatarWrap');
    const nameText = $('achievementsNameText');
    const scoreNumber = $('achievementsScoreNumber');
    const editBtn = $('achievementsEditBtn');
    const tiersWrap = $('achievementTiers');
    const badgeBanner = $('achievementsBadgeBanner');

    const editModal = $('achievementsEditModal');
    const editDisplayNameInput = $('achievementsEditDisplayName');
    const editProfileNameInput = $('achievementsEditProfileName');
    const editEmailInput = $('achievementsEditEmail');
    const editError = $('achievementsEditError');
    const editSaveBtn = $('achievementsEditSaveBtn');
    const editCancelBtn = $('achievementsEditCancelBtn');

    if (!loginWrap || !tiersWrap) return;

    // The most recent lookupGoogle() result — kept so the edit modal can be
    // prefilled and re-rendering after a rename doesn't need another round
    // trip just to get profileName/email/tiers again.
    let currentIdentity = null;

    // Updates each star's lit state plus the small "N to go" label underneath
    // it (hidden once that star is lit), and returns the player's highest
    // achieved badge so far ({tier, color, starNumber} — starNumber is a
    // 1-based count of lit stars in that tier), or null if nothing's lit yet.
    function applyTiers(tiers, correctCount) {
        const toGoSuffix = (window.QUIZ_ACHIEVEMENTS_I18N && window.QUIZ_ACHIEVEMENTS_I18N.toGo) || 'to go';
        let highest = null;

        (tiers || []).forEach((tier) => {
            let litCount = 0;
            (tier.stars || []).forEach((lit, i) => {
                const star = tiersWrap.querySelector('.achievement-star[data-tier="' + tier.key + '"][data-index="' + i + '"]');
                if (star) star.classList.toggle('is-lit', !!lit);

                const remainingEl = tiersWrap.querySelector('.achievement-star-remaining[data-tier="' + tier.key + '"][data-index="' + i + '"]');
                if (remainingEl) {
                    const threshold = (tier.thresholds || [])[i] || 0;
                    const remaining = Math.max(0, threshold - correctCount);
                    remainingEl.textContent = lit ? '' : (remaining + ' ' + toGoSuffix);
                }

                if (lit) litCount += 1;
            });
            if (litCount > 0) {
                highest = { tier: tier.label, color: tier.color, starNumber: litCount };
            }
        });

        return highest;
    }

    function showBadgeBanner(displayName, highest) {
        if (!badgeBanner) return;
        if (!highest) {
            badgeBanner.hidden = true;
            return;
        }
        const template = (window.QUIZ_ACHIEVEMENTS_I18N && window.QUIZ_ACHIEVEMENTS_I18N.badgeMessage)
            || '🎉 Congratulations, {name}! You\'ve reached {tier} — Star {star} of 5!';
        badgeBanner.textContent = template
            .replace('{name}', displayName || '')
            .replace('{tier}', highest.tier)
            .replace('{star}', String(highest.starNumber));
        badgeBanner.style.setProperty('--tier-color', highest.color);
        badgeBanner.hidden = false;
    }

    function showIdentity(identity) {
        currentIdentity = identity;
        loginWrap.hidden = true;
        identityWrap.hidden = false;

        avatarWrap.innerHTML = '';
        avatarWrap.classList.toggle('achv-avatar-placeholder', !identity.pictureUrl);
        if (identity.pictureUrl) {
            const avatar = document.createElement('img');
            avatar.className = 'achv-avatar-img';
            avatar.src = identity.pictureUrl;
            avatar.alt = '';
            avatarWrap.appendChild(avatar);
        }

        nameText.textContent = identity.displayName || '';
        scoreNumber.textContent = String(identity.correctCount);

        const highest = applyTiers(identity.tiers, identity.correctCount);
        showBadgeBanner(identity.displayName, highest);
    }

    // Google-identity lookup — no body params needed, the server reads the
    // signed-in session itself (window.QUIZ_GOOGLE_USER here is only used
    // to decide whether to call this at all). A signed-in player who
    // hasn't joined the leaderboard yet still gets shown their identity (at
    // 0 correct answers) rather than an error, since signing in already
    // proves who they are.
    async function lookupGoogle() {
        try {
            const res = await fetch('api/leaderboard.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'lookup_google' }),
            });
            const data = await res.json().catch(() => null);
            if (!res.ok || !data || !data.ok || !data.signedIn) return;
            showIdentity({
                email: data.email,
                profileName: data.profileName,
                displayName: data.displayName,
                pictureUrl: data.pictureUrl,
                correctCount: data.correctCount,
                tiers: data.tiers,
            });
        } catch (err) {
            // ignore — silent, falls back to showing the login options
        }
    }

    function openEditModal() {
        if (!editModal || !currentIdentity) return;
        editDisplayNameInput.value = currentIdentity.displayName || '';
        editProfileNameInput.value = currentIdentity.profileName || '';
        editEmailInput.value = currentIdentity.email || '';
        if (editError) editError.hidden = true;
        editModal.hidden = false;
        editDisplayNameInput.focus();
    }

    function closeEditModal() {
        if (editModal) editModal.hidden = true;
    }

    if (editBtn) {
        editBtn.addEventListener('click', openEditModal);
    }
    if (editCancelBtn) {
        editCancelBtn.addEventListener('click', closeEditModal);
    }
    if (editModal) {
        editModal.addEventListener('click', (e) => {
            if (e.target === editModal) closeEditModal();
        });
    }
    if (editSaveBtn) {
        editSaveBtn.addEventListener('click', async () => {
            const newName = editDisplayNameInput.value.trim();
            if (!newName) {
                if (editError) editError.hidden = false;
                return;
            }
            editSaveBtn.disabled = true;
            try {
                const res = await fetch('api/leaderboard.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'update_display_name', displayName: newName }),
                });
                const data = await res.json().catch(() => null);
                if (!res.ok || !data || !data.displayName) {
                    if (editError) editError.hidden = false;
                    return;
                }
                showIdentity({ ...currentIdentity, displayName: data.displayName });
                closeEditModal();
            } catch (err) {
                if (editError) editError.hidden = false;
            } finally {
                editSaveBtn.disabled = false;
            }
        });
    }

    if (window.QUIZ_GOOGLE_USER && window.QUIZ_GOOGLE_USER.email) {
        lookupGoogle();
    }
})();
