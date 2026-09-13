// Single authoritative client-side theme state.
//
// localStorage key `theme` ('light' | 'dark' | 'system', default 'system') is
// the only source of truth. The same rule is pre-applied before first paint by
// `components/theme-init.blade.php` (every layout includes that partial).
//
// Why the listeners below exist: Livewire `wire:navigate` swaps the page from
// a fresh server snapshot and copies the snapshot's `<html>` attributes onto
// the live document, which drops the client-only `dark` class. Re-deriving
// from localStorage in the framework's synchronous `livewire:navigated` hook
// (same task as the swap, so no paint/flash in between) restores it with no
// timers and no scattered copies. The OS-preference listener keeps `system`
// mode live; the `storage` listener keeps multiple tabs in sync.
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

// Single write path for the theme preference. Both the app-shell Alpine
// scope and the auth-layout toggle delegate here, so the persist + apply
// rule exists exactly once.
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

document.addEventListener('livewire:navigated', () => {
    applyTheme();
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
});
