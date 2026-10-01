
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

function applyStoredUiColors() {
    try {
        if (window.__uiColors) window.__uiColors.applyStored();
    } catch (e) {}
}

applyStoredUiColors();

document.addEventListener('livewire:navigated', () => {
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
