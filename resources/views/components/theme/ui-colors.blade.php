
@php
    // Static defaults only: tokens are fixed, so each one is read explicitly.
    // Every value is validated as strict #RRGGBB before it may reach CSS;
    // anything else falls back to a neutral constant (never raw config).
    $uiHex = static fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($v)) === 1
        ? strtoupper(trim($v))
        : null;
    $uiLight = [
        'primary' => $uiHex(config('ui.colors.defaults.light.primary')) ?? '#000000',
        'primary_text' => $uiHex(config('ui.colors.defaults.light.primary_text')) ?? '#000000',
        'accent' => $uiHex(config('ui.colors.defaults.light.accent')) ?? '#000000',
        'link' => $uiHex(config('ui.colors.defaults.light.link')) ?? '#000000',
    ];
    $uiDark = [
        'primary' => $uiHex(config('ui.colors.defaults.dark.primary')) ?? $uiLight['primary'],
        'primary_text' => $uiHex(config('ui.colors.defaults.dark.primary_text')) ?? $uiLight['primary_text'],
        'accent' => $uiHex(config('ui.colors.defaults.dark.accent')) ?? $uiLight['accent'],
        'link' => $uiHex(config('ui.colors.defaults.dark.link')) ?? $uiLight['link'],
    ];
@endphp
<style id="ui-colors">
    :root{
        --color-primary:{{ $uiLight['primary'] }};
        --color-primary-text:{{ $uiLight['primary_text'] }};
        --color-accent:{{ $uiLight['accent'] }};
        --color-link:{{ $uiLight['link'] }};
    }
    .dark{
        --color-primary:{{ $uiDark['primary'] }};
        --color-primary-text:{{ $uiDark['primary_text'] }};
        --color-accent:{{ $uiDark['accent'] }};
        --color-link:{{ $uiDark['link'] }};
    }
</style>
<script>
    // First-paint UI colors: same principle as theme-init. Runs synchronously
    // in <head> so a saved palette applies before anything renders (no flash
    // of defaults). Only strict #RRGGBB values are honored; anything missing,
    // malformed, or corrupted is ignored and the stylesheet defaults above
    // stay in effect. Cosmetic only: never touches auth, DB, or app logic.
    window.__uiColors = (function () {
        const KEY = 'ui_colors';
        const TOKENS = ['primary', 'primary_text', 'accent', 'link'];
        const MAP = {
            primary: '--color-primary',
            primary_text: '--color-primary-text',
            accent: '--color-accent',
            link: '--color-link'
        };
        const HEX = /^#[0-9a-fA-F]{6}$/;

        function sanitize(value) {
            if (typeof value !== 'string') return null;
            value = value.trim();
            return HEX.test(value) ? value.toUpperCase() : null;
        }

        // Atomic palette integrity: a stored palette is valid only when ALL
        // four tokens are present and strictly valid — matching save().
        // Anything incomplete, malformed, or corrupted yields {} so the
        // stylesheet defaults apply instead of a partial palette.
        function read() {
            try {
                const raw = localStorage.getItem(KEY);
                if (!raw) return {};
                const data = JSON.parse(raw);
                if (!data || typeof data !== 'object' || Array.isArray(data)) return {};
                const out = {};
                TOKENS.forEach(function (token) {
                    const clean = sanitize(data[token]);
                    if (clean) out[token] = clean;
                });
                if (Object.keys(out).length !== TOKENS.length) return {};
                return out;
            } catch (e) {
                return {};
            }
        }

        // Only validated values ever reach CSS: each input is sanitized
        // first, so the value passed to setProperty is always the checked
        // one (strict #RRGGBB, normalized). Property writes only.
        function apply(colors) {
            try {
                const root = document.documentElement;
                if (!root || !colors || typeof colors !== 'object') return;
                TOKENS.forEach(function (token) {
                    const clean = sanitize(colors[token]);
                    if (clean) root.style.setProperty(MAP[token], clean);
                });
            } catch (e) {}
        }

        function applyStored() {
            // Clear first so a removed palette (e.g. reset in another tab)
            // can never leave stale inline overrides behind.
            try {
                const root = document.documentElement;
                TOKENS.forEach(function (token) {
                    root.style.removeProperty(MAP[token]);
                });
            } catch (e) {}
            apply(read());
        }

        // Persist a full 4-token palette (strict #RRGGBB each). Returns the
        // normalized palette, or null when invalid (nothing is stored).
        function save(colors) {
            try {
                const out = {};
                TOKENS.forEach(function (token) {
                    const clean = colors ? sanitize(colors[token]) : null;
                    if (clean) out[token] = clean;
                });
                if (Object.keys(out).length !== TOKENS.length) return null;
                localStorage.setItem(KEY, JSON.stringify(out));
                apply(out);
                return out;
            } catch (e) {
                return null;
            }
        }

        // Forget the palette and drop inline overrides so the per-scheme
        // stylesheet defaults (:root / .dark) take over again.
        function clear() {
            try {
                localStorage.removeItem(KEY);
            } catch (e) {}
            try {
                const root = document.documentElement;
                TOKENS.forEach(function (token) {
                    root.style.removeProperty(MAP[token]);
                });
            } catch (e) {}
        }

        return {
            KEY: KEY,
            TOKENS: TOKENS,
            sanitize: sanitize,
            read: read,
            apply: apply,
            applyStored: applyStored,
            save: save,
            clear: clear
        };
    })();

    try {
        window.__uiColors.applyStored();
    } catch (e) {}
</script>
