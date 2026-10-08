
import './stream-client.js';
import './chat-stream.js';
import './message-actions.js';
import './chat-history.js';

function currentTheme() {
    try {
        return localStorage.getItem('theme') || 'system';
    } catch (e) {
        return 'system';
    }
}

function applyTheme() {
    const t = currentTheme();
    let dark = false;
    try {
        dark = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
    } catch (e) {
        dark = false;
    }
    document.documentElement.classList.toggle('dark', dark);
    try {
        document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
    } catch (e) {}
    return t;
}

window.__applyTheme = applyTheme;
window.__currentTheme = currentTheme;


window.__setTheme = function (t) {
    if (t !== 'light' && t !== 'dark' && t !== 'system') {
        return currentTheme();
    }
    try {
        localStorage.setItem('theme', t);
    } catch (e) {}
    return applyTheme();
};

applyTheme();

// Guard the client-owned theme class on <html> across Livewire SPA
// navigations. The navigate swap syncs <html> attributes from the
// server-rendered document, which never carries the client theme, so it
// strips `dark` and the browser would paint light/unstyled frames until
// `livewire:navigated` re-applies the theme. MutationObserver callbacks
// run before the next paint, so restoring the expected class here
// synchronously means no wrong-theme frame is ever rendered. Stateless:
// when the class already matches the stored preference this is a no-op,
// so own writes and user toggles can never loop. No timers, no masking.
function expectedDark() {
    try {
        const t = currentTheme();
        if (t === 'dark') return true;
        if (t === 'light') return false;
        return matchMedia('(prefers-color-scheme: dark)').matches;
    } catch (e) {
        return false;
    }
}

try {
    if (typeof MutationObserver !== 'undefined' && document.documentElement) {
        new MutationObserver(() => {
            let has = false;
            try {
                has = document.documentElement.classList.contains('dark');
            } catch (e) { return; }
            if (has !== expectedDark()) applyTheme();
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    }
} catch (e) {}

function applyStoredUiColors() {
    try {
        if (window.__uiColors) window.__uiColors.applyStored();
    } catch (e) {}
}

applyStoredUiColors();

// Navigation loading gate: Livewire's built-in progress bar starts ~150ms
// after navigation begins, so ordinary fast navigations flash it briefly.
// Gate only its VISIBILITY for 250ms (see the matching CSS rule for
// `html.nav-loading-pending #nprogress`): navigations that finish first
// (livewire:navigated) never show it; slower ones reveal the
// framework-owned bar until they complete. Navigation itself, its timing,
// and the bar are untouched — never disabled, never replaced. The single
// timer below bounds the gate class lifetime; it never delays navigation.
let navLoadingTimer = null;
function disarmNavLoadingGate() {
    if (navLoadingTimer) {
        clearTimeout(navLoadingTimer);
        navLoadingTimer = null;
    }
    try {
        document.documentElement.classList.remove('nav-loading-pending');
    } catch (e) {}
}
document.addEventListener('livewire:navigate', () => {
    try {
        document.documentElement.classList.add('nav-loading-pending');
    } catch (e) {}
    if (navLoadingTimer) clearTimeout(navLoadingTimer);
    navLoadingTimer = setTimeout(disarmNavLoadingGate, 250);
});

document.addEventListener('livewire:navigated', () => {
    disarmNavLoadingGate();
    applyTheme();
    applyStoredUiColors();
});

try {
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (currentTheme() === 'system') {
            applyTheme();
        }
    });
} catch (e) {}

window.addEventListener('storage', (e) => {
    if (e && e.key === 'theme') {
        applyTheme();
    }
    if (e && e.key === 'ui_colors') {
        applyStoredUiColors();
    }
});
