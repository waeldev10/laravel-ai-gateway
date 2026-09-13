{{-- Pre-paint theme initialization (single copy, included by every layout).
     Applies the persisted theme before first paint so full loads never flash
     the wrong theme. The same rule lives once in `resources/js/app.js`
     (`applyTheme`) for runtime changes and SPA (`wire:navigate`) swaps, which
     otherwise drop the client-only `dark` class when Livewire replaces the
     `<html>` attributes from the fresh server snapshot. localStorage is the
     single source of truth; `system` follows the OS preference. --}}
<script>try { const t = localStorage.getItem('theme') || 'system'; const m = matchMedia('(prefers-color-scheme: dark)').matches; const d = t === 'dark' || (t === 'system' && m); document.documentElement.classList.toggle('dark', d); document.documentElement.style.colorScheme = d ? 'dark' : 'light'; } catch (e) { }</script>
