{{-- Single-conversation action menu (show-page header). Presentation mirrors the
     sidebar conversation-actions-menu; every mutation goes through this
     component's Livewire actions into ConversationService — the same
     authoritative backend as the sidebar/search — so no logic is duplicated.
     Alpine owns transient UI only (dropdown, modals, clipboard feedback);
     each confirmed action is exactly one Livewire request. --}}
<div class="relative" x-data="{
        open: false,
        renameOpen: false, renameTitle: '', renameBusy: false,
        copied: false,
        shareUrl: @js(route('conversations.show', $conversationId)),
        share() {
            this.open = false;
            const done = (ok) => {
                this.copied = ok ? true : 'failed';
                setTimeout(() => { this.copied = false; }, 1500);
                if (!ok) window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', title: 'تعذر النسخ', message: 'تعذر الوصول إلى الحافظة.' } }));
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(this.shareUrl).then(() => done(true)).catch(() => done(false));
            } else { done(false); }
        },
    }" @keydown.escape.window="open = false; if (!renameBusy) renameOpen = false;"
    @close-menu-rename.window="renameOpen = false; renameBusy = false;"
    @click.outside="open = false">
    <button type="button" @click="open = !open" aria-label="خيارات المحادثة"
        aria-haspopup="menu" :aria-expanded="open ? 'true' : 'false'"
        class="w-9 h-9 grid place-items-center rounded-full text-zinc-500 hover:bg-black/5 hover:text-zinc-700 focus-visible:bg-black/5 focus-visible:text-zinc-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-200 dark:focus-visible:bg-white/10 dark:focus-visible:text-zinc-200 transition-colors">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
    </button>
    <span x-show="copied === true" x-cloak
        class="absolute top-full mt-1 end-0 whitespace-nowrap rounded-full bg-black text-white dark:bg-white dark:text-black px-2 py-0.5 text-[11px]">تم النسخ</span>

    <div x-show="open" x-cloak
        x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
        role="menu" aria-label="إجراءات المحادثة"
        class="absolute end-0 top-full z-50 mt-1 w-48 rounded-xl bg-white dark:bg-[#1e293b] border border-black/10 dark:border-white/10 p-1.5 shadow-2xl origin-top">
        <button type="button" @click="renameTitle = $wire.title; renameOpen = true; open = false; $nextTick(() => $refs.renameInput?.focus())" role="menuitem"
            class="flex w-full items-center gap-2.5 text-right px-3 py-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/10 focus-visible:bg-black/5 dark:focus-visible:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current text-xs font-medium text-zinc-700 dark:text-zinc-200 min-h-[36px] transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" /><path d="m15 5 4 4" /></svg>
            <span>إعادة تسمية</span>
        </button>
        <button type="button" @click="open = false; $wire.togglePin()" role="menuitem"
            class="flex w-full items-center gap-2.5 text-right px-3 py-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/10 focus-visible:bg-black/5 dark:focus-visible:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current text-xs font-medium text-zinc-700 dark:text-zinc-200 min-h-[36px] transition-colors">
            @if($isPinned)
                <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M12 17v5" /><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1z" /><line x1="3" y1="3" x2="21" y2="21" /></svg>
                <span>إلغاء التثبيت</span>
            @else
                <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M12 17v5" /><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1z" /></svg>
                <span>تثبيت</span>
            @endif
        </button>
        <button type="button" @click="share()" role="menuitem"
            class="flex w-full items-center gap-2.5 text-right px-3 py-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/10 focus-visible:bg-black/5 dark:focus-visible:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current text-xs font-medium text-zinc-700 dark:text-zinc-200 min-h-[36px] transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" /><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" /></svg>
            <span>مشاركة</span>
        </button>
        <button type="button" @click="open = false; window.dispatchEvent(new CustomEvent('open-confirm-modal', { detail: { id: 'conversation-menu-delete', subject: $wire.title } }))" role="menuitem"
            class="flex w-full items-center gap-2.5 text-right px-3 py-2 rounded-lg hover:bg-red-50 dark:hover:bg-red-500/10 focus-visible:bg-red-50 dark:focus-visible:bg-red-500/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current text-xs font-medium text-red-600 dark:text-red-300 min-h-[36px] transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M3 6h18" /><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" /><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" /><line x1="10" x2="10" y1="11" y2="17" /><line x1="14" x2="14" y1="11" y2="17" /></svg>
            <span>حذف المحادثة</span>
        </button>
    </div>

    <div x-show="renameOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4" role="dialog"
        aria-modal="true" aria-labelledby="conversation-menu-rename-title">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="if (!renameBusy) { renameOpen = false; }" aria-hidden="true"></div>
        <div class="relative bg-white dark:bg-zinc-900 rounded-2xl p-5 sm:p-6 w-full max-w-md shadow-2xl border border-black/10 dark:border-white/10">
            <h2 id="conversation-menu-rename-title" class="text-base font-semibold">إعادة تسمية المحادثة</h2>
            <div class="mt-4">
                <input type="text" x-model="renameTitle" x-ref="renameInput" :disabled="renameBusy"
                    @keydown.enter="if (!renameBusy) { renameBusy = true; $wire.rename(renameTitle); }"
                    required maxlength="255" aria-label="عنوان المحادثة"
                    class="w-full rounded-xl bg-black/5 dark:bg-white/10 px-3 py-2.5 text-sm focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current disabled:opacity-50">
                <div class="flex gap-2 justify-end mt-5">
                    <button type="button" @click="if (!renameBusy) { renameOpen = false; }" :disabled="renameBusy"
                        class="px-5 py-2 rounded-full border border-black/10 dark:border-white/10 text-xs font-medium hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current disabled:opacity-50 disabled:cursor-not-allowed min-h-[36px] transition-colors">إلغاء</button>
                    <button type="button" @click="renameBusy = true; $wire.rename(renameTitle)" :disabled="renameBusy"
                        class="px-5 py-2 rounded-full ui-primary-btn text-xs font-medium disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5 min-h-[36px] transition-colors">
                        <span x-text="renameBusy ? 'جارٍ الحفظ...' : 'حفظ'">حفظ</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <x-ui.confirm-modal id="conversation-menu-delete" title="حذف المحادثة" message="سيتم حذف هذه المحادثة نهائياً. لا يمكن التراجع عن هذا الإجراء." confirmLabel="حذف" cancelLabel="إلغاء">
        <x-slot:confirm>
            <button type="button" @click="if (busy) return; busy = true; $wire.delete().finally(() => { busy = false; })" :disabled="busy"
                class="px-5 py-2 rounded-full bg-red-600 text-white text-xs font-medium hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5 min-h-[36px] transition-colors">
                <span x-text="busy ? 'جارٍ الحذف...' : 'حذف'">حذف</span>
            </button>
        </x-slot:confirm>
    </x-ui.confirm-modal>
</div>
