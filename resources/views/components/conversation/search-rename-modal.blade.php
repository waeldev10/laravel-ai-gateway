@props(['id' => 'search-rename'])

{{-- Search page rename modal. Alpine owns the UI state (open, input, busy):
     opening, typing and closing never touch the server. Opened via browser event:
       window.dispatchEvent(new CustomEvent('open-rename-modal',
         { detail: { id: 'search-rename', title: '...', conversationId: '...' } }))
     Closed via the `close-rename-modal` browser event (dispatched by
     ConversationSearch::renameConversation on success) or locally on cancel.
     Submit calls the enclosing ConversationSearch `renameConversation` exactly
     once. Kept separate from the sidebar rename modal because the submit path
     differs (Livewire call here vs plain PATCH form there).
     Not teleported: teleporting would move it outside the Livewire morph tree
     and break the $wire submit. No transformed/filtered ancestor exists on this
     path, so viewport-fixed centering works as-is. --}}
<div x-data="{ open: false, busy: false, conversationId: '', title: '' }"
    @open-rename-modal.window="if ($event.detail.id === @js($id)) { conversationId = $event.detail.conversationId || ''; title = $event.detail.title || ''; busy = false; open = true; $nextTick(() => $refs.renameInput?.focus()); }"
    @close-rename-modal.window="if ($event.detail.id === @js($id)) { open = false; busy = false; }"
    @keydown.escape.window="if (open && !busy) { open = false; }" x-cloak>
    <div x-show="open" class="fixed inset-0 z-[60] flex items-center justify-center p-4" role="dialog"
        aria-modal="true" aria-labelledby="search-rename-title">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="if (!busy) { open = false; }" aria-hidden="true"></div>
        <div class="relative bg-white dark:bg-zinc-900 rounded-2xl p-5 sm:p-6 w-full max-w-md shadow-2xl border border-black/10 dark:border-white/10">
            <div class="flex items-start gap-3">
                <span class="w-10 h-10 shrink-0 rounded-full bg-black/5 dark:bg-white/10 text-zinc-600 dark:text-zinc-200 grid place-items-center" aria-hidden="true">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" /><path d="m15 5 4 4" /></svg>
                </span>
                <h2 id="search-rename-title" class="text-base font-semibold pt-2">إعادة تسمية المحادثة</h2>
            </div>
            <div class="mt-4">
                <input type="text" x-model="title" x-ref="renameInput" :disabled="busy"
                    @keydown.enter="if (!busy) { busy = true; $wire.renameConversation(conversationId, title).finally(() => { busy = false; }); }"
                    required maxlength="255" aria-label="عنوان المحادثة"
                    class="w-full rounded-xl bg-black/5 dark:bg-white/10 px-3 py-2.5 text-sm focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current disabled:opacity-50">
                <div class="flex gap-2 justify-end mt-5">
                    <button type="button" @click="if (!busy) { open = false; }" :disabled="busy"
                        class="px-5 py-2 rounded-full border border-black/10 dark:border-white/10 text-xs font-medium hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current disabled:opacity-50 disabled:cursor-not-allowed min-h-[36px] transition-colors">إلغاء</button>
                    <button type="button" @click="busy = true; $wire.renameConversation(conversationId, title).finally(() => { busy = false; })" :disabled="busy"
                        class="px-5 py-2 rounded-full ui-primary-btn text-xs font-medium disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5 min-h-[36px] transition-colors">
                        <span x-show="busy" x-cloak>
                            <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                        </span>
                        <span x-text="busy ? 'جارٍ الحفظ...' : 'حفظ'">حفظ</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
