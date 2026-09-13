{{-- Settings modal for UI colors (single implementation).
     Alpine owns all browser-only state: open/closed, draft preview, busy flags.
     Picking a color calls `preview()` only (sets CSS vars on <html>, no server
     request). Save/Reset each send ONE Livewire request with the final palette;
     loading reflects that real request via the saving/resetting flags tied to
     the $wire promise (no timers) and `wire:loading` disables natively.
     Server stays authoritative: UiColorSettings validates strict #RRGGBB,
     persists through UiColorService, then dispatches `ui-colors-saved`
     (new authoritative palette) + `close-settings-modal` + unified `toast`.
     Cancel/Escape/backdrop reverts the preview to the last saved palette.
     Not teleported: it lives as a direct child of <body> in the app layout
     (outside the transformed/filtered sidebar and content containers), so
     viewport-fixed centering works and $wire stays in scope. RTL inherited,
     light/dark via standard variants, responsive max-w-md. --}}
<div x-data="{
        open: false,
        saving: false,
        resetting: false,
        saved: @js($initial),
        draft: @js($initial),
        defaults: @js($lightDefaults),
        get busy() { return this.saving || this.resetting; },
        apply(c) {
            try {
                const r = document.documentElement;
                if (!r || !c) return;
                if (c.primary) r.style.setProperty('--color-primary', String(c.primary));
                if (c.primary_text) r.style.setProperty('--color-primary-text', String(c.primary_text));
                if (c.accent) r.style.setProperty('--color-accent', String(c.accent));
                if (c.link) r.style.setProperty('--color-link', String(c.link));
            } catch (e) {}
        },
        preview() { this.apply(this.draft); },
        revert() { this.apply(this.saved); },
    }"
    x-init="apply(saved)"
    @open-settings-modal.window="draft = JSON.parse(JSON.stringify(saved)); apply(draft); open = true; $nextTick(() => $refs.firstColor?.focus());"
    @close-settings-modal.window="open = false;"
    @ui-colors-saved.window="if ($event.detail && $event.detail.colors) { saved = JSON.parse(JSON.stringify($event.detail.colors)); draft = JSON.parse(JSON.stringify(saved)); apply(saved); }"
    @keydown.escape.window="if (open && !busy) { revert(); open = false; }"
    x-cloak>
    <div x-show="open" class="fixed inset-0 z-[60] flex items-center justify-center p-4" role="dialog"
        aria-modal="true" aria-labelledby="settings-title" aria-describedby="settings-desc">
        <div x-show="open" x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="if (!busy) { revert(); open = false; }" aria-hidden="true"></div>
        <div x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100 translate-y-0" x-transition:leave-end="opacity-0 scale-95 translate-y-2"
            class="relative bg-white dark:bg-zinc-900 rounded-2xl p-5 sm:p-6 w-full max-w-md shadow-2xl border border-black/10 dark:border-white/10 max-h-[90vh] overflow-y-auto">
            <div class="flex items-start gap-3">
                <span class="w-10 h-10 shrink-0 rounded-full bg-black/5 dark:bg-white/10 text-zinc-600 dark:text-zinc-200 grid place-items-center" aria-hidden="true">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" /></svg>
                </span>
                <span class="flex-1 min-w-0">
                    <h2 id="settings-title" class="text-base font-semibold">الإعدادات</h2>
                    <p id="settings-desc" class="text-sm text-zinc-500 dark:text-zinc-400 mt-1 leading-relaxed">تخصيص ألوان الواجهة. التغيير يظهر فوراً، والحفظ يثبّته لحسابك.</p>
                </span>
                <button type="button" x-ref="closeBtn" @click="if (!busy) { revert(); open = false; }" :disabled="busy" aria-label="إغلاق الإعدادات"
                    class="w-8 h-8 shrink-0 grid place-items-center rounded-full text-zinc-400 hover:bg-black/5 hover:text-zinc-600 dark:hover:bg-white/10 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
                </button>
            </div>

            <div class="mt-5 space-y-3">
                <div class="flex items-center gap-3 rounded-xl border border-black/10 dark:border-white/10 px-3 py-2.5">
                    <span class="w-8 h-8 rounded-full border border-black/10 dark:border-white/10 shrink-0" :style="'background-color: ' + draft.primary" aria-hidden="true"></span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-xs font-medium">اللون الرئيسي (الأزرار)</span>
                        <span class="block text-[11px] text-zinc-500 dark:text-zinc-400" dir="ltr" x-text="draft.primary"></span>
                    </span>
                    <input type="color" x-ref="firstColor" x-model="draft.primary" @input="preview()" :disabled="busy" aria-label="اللون الرئيسي"
                        class="w-10 h-10 shrink-0 cursor-pointer rounded-lg border border-black/10 dark:border-white/10 bg-transparent p-1 disabled:opacity-50 disabled:cursor-not-allowed">
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-black/10 dark:border-white/10 px-3 py-2.5">
                    <span class="w-8 h-8 rounded-full border border-black/10 dark:border-white/10 shrink-0 grid place-items-center text-xs font-semibold" :style="'background-color: ' + draft.primary_text + '; color: ' + draft.primary" aria-hidden="true">أ</span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-xs font-medium">لون نص الأزرار</span>
                        <span class="block text-[11px] text-zinc-500 dark:text-zinc-400" dir="ltr" x-text="draft.primary_text"></span>
                    </span>
                    <input type="color" x-model="draft.primary_text" @input="preview()" :disabled="busy" aria-label="لون نص الأزرار"
                        class="w-10 h-10 shrink-0 cursor-pointer rounded-lg border border-black/10 dark:border-white/10 bg-transparent p-1 disabled:opacity-50 disabled:cursor-not-allowed">
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-black/10 dark:border-white/10 px-3 py-2.5">
                    <span class="w-8 h-8 rounded-full border border-black/10 dark:border-white/10 shrink-0" :style="'background-color: ' + draft.accent" aria-hidden="true"></span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-xs font-medium">لون التمييز</span>
                        <span class="block text-[11px] text-zinc-500 dark:text-zinc-400" dir="ltr" x-text="draft.accent"></span>
                    </span>
                    <input type="color" x-model="draft.accent" @input="preview()" :disabled="busy" aria-label="لون التمييز"
                        class="w-10 h-10 shrink-0 cursor-pointer rounded-lg border border-black/10 dark:border-white/10 bg-transparent p-1 disabled:opacity-50 disabled:cursor-not-allowed">
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-black/10 dark:border-white/10 px-3 py-2.5">
                    <span class="w-8 h-8 rounded-full border border-black/10 dark:border-white/10 shrink-0 grid place-items-center text-xs font-semibold underline" :style="'color: ' + draft.link" aria-hidden="true">رابط</span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-xs font-medium">لون الروابط</span>
                        <span class="block text-[11px] text-zinc-500 dark:text-zinc-400" dir="ltr" x-text="draft.link"></span>
                    </span>
                    <input type="color" x-model="draft.link" @input="preview()" :disabled="busy" aria-label="لون الروابط"
                        class="w-10 h-10 shrink-0 cursor-pointer rounded-lg border border-black/10 dark:border-white/10 bg-transparent p-1 disabled:opacity-50 disabled:cursor-not-allowed">
                </div>
                <p class="text-[11px] text-zinc-500 dark:text-zinc-400 leading-relaxed">يعمل مع الوضع الفاتح والداكن. زر الحفظ لديه حالة تحميل حقيقية ويمنع التكرار.</p>
            </div>

            <div class="flex gap-2 justify-between mt-5 flex-wrap">
                <button type="button" @click="if (busy) return; resetting = true; $wire.resetToDefaults().finally(() => { resetting = false; });" :disabled="busy"
                    wire:loading.attr="disabled" wire:target="resetToDefaults,save"
                    class="px-5 py-2 rounded-full border border-black/10 dark:border-white/10 text-xs font-medium hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current disabled:opacity-50 disabled:cursor-not-allowed min-h-[36px] inline-flex items-center gap-1.5 transition-colors">
                    <span x-show="resetting" x-cloak>
                        <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                    </span>
                    <span x-text="resetting ? 'جارٍ الاستعادة...' : 'استعادة الألوان الافتراضية'">استعادة الألوان الافتراضية</span>
                </button>
                <div class="flex gap-2">
                    <button type="button" @click="if (!busy) { revert(); open = false; }" :disabled="busy"
                        class="px-5 py-2 rounded-full border border-black/10 dark:border-white/10 text-xs font-medium hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current disabled:opacity-50 disabled:cursor-not-allowed min-h-[36px] transition-colors">إلغاء</button>
                    <button type="button" @click="if (busy) return; saving = true; $wire.save(draft).finally(() => { saving = false; });" :disabled="busy"
                        wire:loading.attr="disabled" wire:target="save,resetToDefaults"
                        class="px-5 py-2 rounded-full ui-primary-btn text-xs font-medium disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5 min-h-[36px] transition-colors">
                        <span x-show="saving" x-cloak>
                            <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                        </span>
                        <span wire:loading.remove wire:target="save"><span x-text="saving ? 'جارٍ الحفظ...' : 'حفظ'">حفظ</span></span>
                        <span wire:loading wire:target="save">جارٍ الحفظ...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
