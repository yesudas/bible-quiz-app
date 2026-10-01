if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('./sw.js').catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
    setupSiteNav();
    setupPwaInstall();
});

function setupSiteNav() {
    const toggle = document.getElementById('navToggle');
    const nav = document.getElementById('siteNav');
    if (!toggle || !nav) {
        return;
    }

    const syncNav = () => {
        const open = nav.classList.contains('is-open');
        nav.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    toggle.addEventListener('click', () => {
        nav.classList.toggle('is-open');
        syncNav();
    });

    nav.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            nav.classList.remove('is-open');
            syncNav();
        });
    });

    syncNav();
}

function setupPwaInstall() {
    const installButtons = document.querySelectorAll('.install-app-btn');
    const installBanner = document.getElementById('pwaInstallBanner');
    const dismissBannerBtn = document.getElementById('dismissBannerBtn');
    let deferredPrompt = null;

    const bannerDismissed = localStorage.getItem('bibleQuizPwaDismissed') === 'true';
    const launchedFromApp = (() => {
        try {
            return new URLSearchParams(window.location.search).get('f') === 'app'
                || window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;
        } catch (e) {
            return false;
        }
    })();

    const hideInstallUi = () => {
        installButtons.forEach((btn) => { btn.hidden = true; });
        if (installBanner) installBanner.hidden = true;
    };

    const showInstallUi = () => {
        if (launchedFromApp) return;
        installButtons.forEach((btn) => { btn.hidden = false; });
        if (installBanner && !bannerDismissed) installBanner.hidden = false;
    };

    hideInstallUi();

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt = event;
        showInstallUi();
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        localStorage.setItem('bibleQuizPwaDismissed', 'true');
        hideInstallUi();
    });

    const promptInstall = async () => {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        await deferredPrompt.userChoice;
        deferredPrompt = null;
    };

    installButtons.forEach((btn) => btn.addEventListener('click', promptInstall));

    if (dismissBannerBtn) {
        dismissBannerBtn.addEventListener('click', () => {
            localStorage.setItem('bibleQuizPwaDismissed', 'true');
            if (installBanner) installBanner.hidden = true;
        });
    }
}
