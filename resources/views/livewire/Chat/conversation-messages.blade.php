{{-- Conversation message list. Re-renders from MessageService whenever a
     `message-sent` event arrives (dispatched by MessageComposer right after it
     persists a message), so newly saved messages appear with no redirect and
     no full page reload. Initial paint comes from the same service query:
     this stays the ONE authoritative message list (nothing is duplicated
     into Alpine, no second composer, no cloned DOM).
     Browser-only Alpine state on this root covers transient UI only:
     `editingId`/`busy` (inline editor), `copiedId`/`copiedTimer` (copy
     checkmark revert), hover tooltips (`tip*` via the shared
     <x-title-tip /> element below), and scroll-edge flags (`atTop`/`atBottom`
     from a passive listener on the `[data-chat-scroll]` container in the show
     view). Copy, edit open/cancel, tooltip hover, navigator jumps and edge
     scrolling never touch the server: message text is always read live from
     the rendered `[data-full-text]` node (the single source), preview items
     are server-rendered from the same messages, and stable
     `[data-message-id]` anchors are the only scroll targets. The single
     Livewire call here is `updateMessage(id, text)` on save, which
     validates/authorizes/persists server-side and closes the editor only on
     success, after which the re-render shows the authoritative content. --}}
<div class="space-y-6 relative" x-data="{
        editingId: null, busy: false,
        copiedId: null, copiedTimer: null,
        tip: false, tipTitle: '', tipTop: 0, tipLeft: 0, tipAbove: false,
        atTop: true, atBottom: true,
        showTip(title, el) {
            this.tipTitle = title || '';
            try {
                const r = el.getBoundingClientRect();
                const w = Math.min(260, window.innerWidth - 16);
                this.tipLeft = Math.round(Math.min(Math.max(8, r.left), Math.max(8, window.innerWidth - w - 8)));
                const below = Math.round(r.bottom + 6);
                this.tipAbove = (below + 110 > window.innerHeight - 8) && (r.top > window.innerHeight / 2);
                this.tipTop = this.tipAbove ? Math.round(r.top - 6) : below;
            } catch (e) { this.tipTop = 0; this.tipLeft = 8; this.tipAbove = false; }
            this.tip = true;
        },
        hideTip() { this.tip = false; },
        messageText(btn) {
            const block = btn.closest('[data-message-id]');
            const node = block ? block.querySelector('[data-full-text]') : null;
            return node ? node.innerText : '';
        },
        copyMsg(btn) {
            const block = btn.closest('[data-message-id]');
            const id = block ? block.dataset.messageId : '';
            const text = this.messageText(btn);
            const fail = () => window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', title: 'تعذر النسخ', message: 'تعذر الوصول إلى الحافظة.' } }));
            if (!text || !navigator.clipboard || !navigator.clipboard.writeText) { fail(); return; }
            navigator.clipboard.writeText(text).then(() => {
                this.copiedId = id;
                if (this.copiedTimer) clearTimeout(this.copiedTimer);
                this.copiedTimer = setTimeout(() => { if (this.copiedId === id) this.copiedId = null; }, 2000);
                window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', title: 'تم النسخ', message: 'تم نسخ الرسالة.' } }));
            }).catch(fail);
        },
        startEdit(btn) {
            if (this.busy) return;
            const block = btn.closest('[data-message-id]');
            if (!block) return;
            const node = block.querySelector('[data-full-text]');
            const text = node ? node.innerText : '';
            this.editingId = block.dataset.messageId;
            this.$nextTick(() => {
                const editor = block.querySelector('input[data-edit-input]');
                if (editor) { editor.value = text; editor.focus(); }
            });
        },
        cancelEdit() { if (this.busy) return; this.editingId = null; },
        saveEdit(btn) {
            if (this.busy) return; this.busy = true;
            const block = btn.closest('[data-message-id]');
            const editor = block ? block.querySelector('input[data-edit-input]') : null;
            this.$wire.updateMessage(block ? block.dataset.messageId : '', editor ? editor.value : '').finally(() => { this.busy = false; });
        },
        chatScroller() { try { return this.$el.closest('[data-chat-scroll]'); } catch (e) { return null; } },
        previewTip(btn) {
            const scroller = this.chatScroller();
            const id = btn.dataset.targetId;
            const node = scroller && id ? scroller.querySelector('[data-message-id=' + CSS.escape(id) + '] [data-full-text]') : null;
            this.showTip(node ? node.innerText : (btn.textContent || '').trim(), btn);
        },
        scrollToNode(target, scroller, offset) {
            scroller.scrollTo({ top: Math.max(0, target.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop - offset), behavior: 'smooth' });
        },
        scrollToMsg(btn) {
            if (btn.dataset.targetId) {
                const scroller = this.chatScroller();
                const target = scroller ? scroller.querySelector('[data-message-id=' + CSS.escape(btn.dataset.targetId) + ']') : null;
                if (!scroller || !target) return;
                this.scrollToNode(target, scroller, 80);
            }
        },
        scrollToEdge(edge) {
            const scroller = this.chatScroller();
            if (!scroller) return;
            const items = scroller.querySelectorAll('[data-message-id]');
            if (!items.length) return;
            const target = edge === 'first' ? items[0] : items[items.length - 1];
            const offset = edge === 'first' ? 16 : Math.max(16, scroller.clientHeight - target.clientHeight - 24);
            this.scrollToNode(target, scroller, offset);
        },
        initChatScroll() {
            const scroller = this.chatScroller();
            if (!scroller) return;
            const update = () => {
                this.atTop = scroller.scrollTop < 48;
                this.atBottom = scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 48;
            };
            update();
            scroller.addEventListener('scroll', update, { passive: true });
        },
    }"
    x-init="initChatScroll()"
    @close-edit-message.window="editingId = null">
    <x-conversation.title-tip />
    @if($messages->isEmpty())
        <div class="text-center py-16 space-y-4">
            <div
                class="w-12 h-12 mx-auto rounded-2xl ui-primary-surface grid place-items-center">
                ◐</div>
            <h2 class="text-xl font-semibold">مرحباً في {{ config('app.name') }}</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 max-w-md mx-auto">ابدأ محادثتك الأولى. هذه مساحة دردشة نظيفة — لا يتم
                توليد ردود وهمية قبل اتصال مزود الذكاء الاصطناعي.</p>
        </div>
    @else
        @foreach($messages as $m)
            @if($m->role === \App\Enums\MessageRole::User)
                <div class="flex justify-end" data-message-id="{{ $m->id }}">
                    <div class="w-full max-w-[95%]">
                        <div x-show="editingId !== '{{ $m->id }}'" class="flex justify-end">
                            <div
                                class="max-w-[82%] sm:max-w-[74%] bg-[#f0f0f0] dark:bg-[#2a2a2a] rounded-2xl rounded-bl-md px-4 py-3 shadow-sm"
                                data-preview="{{ \Illuminate\Support\Str::limit($m->content, 80, '…') }}"
                                @mouseenter="showTip($el.dataset.preview, $el)" @mouseleave="hideTip()">
                                <div class="text-sm leading-relaxed whitespace-pre-wrap break-words" dir="auto" data-full-text>{{ $m->content }}</div>
                                <div class="mt-1.5 text-[11px] text-zinc-400 dark:text-zinc-500 text-end"><time datetime="{{ $m->created_at->toIso8601String() }}">{{ \App\Support\ArabicDateTime::forMessage($m->created_at) }}</time></div>
                            </div>
                        </div>
                        <div x-show="editingId !== '{{ $m->id }}'" class="mt-1 flex items-center justify-end gap-1">
                            <button type="button" @click="copyMsg($el)" aria-label="نسخ الرسالة" :aria-label="copiedId === '{{ $m->id }}' ? 'تم النسخ' : 'نسخ الرسالة'"
                                class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors">
                                <svg x-show="copiedId !== '{{ $m->id }}'" x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>
                                <svg x-show="copiedId === '{{ $m->id }}'" x-cloak x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="w-3.5 h-3.5" style="color: var(--color-primary)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
                                <span x-text="copiedId === '{{ $m->id }}' ? 'تم النسخ' : 'نسخ'">نسخ</span>
                            </button>
                            <button type="button" @click="startEdit($el)" aria-label="تعديل الرسالة"
                                class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" /><path d="m15 5 4 4" /></svg>
                                <span>تعديل</span>
                            </button>
                        </div>
                        <div x-show="editingId === '{{ $m->id }}'" x-cloak class="mt-1">
                            <input type="text" data-edit-input dir="auto" maxlength="10000" aria-label="تعديل نص الرسالة"
                                @keydown.escape="cancelEdit()" @keydown.enter="saveEdit($el)"
                                class="w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-[#212121] px-3.5 py-6 text-sm leading-relaxed focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current" />
                            <div class="mt-1.5 flex items-center justify-end gap-1.5">
                                <button type="button" @click="cancelEdit()" :disabled="busy"
                                    class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium text-zinc-600 dark:text-zinc-300 hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current disabled:opacity-50 disabled:cursor-not-allowed transition-colors">إلغاء</button>
                                <button type="button" @click="saveEdit($el)" :disabled="busy"
                                    class="inline-flex items-center gap-1 rounded-full ui-primary-btn px-3 py-1.5 text-[11px] font-medium disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                                    <span x-show="busy" x-cloak>
                                        <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                                    </span>
                                    <span x-text="busy ? 'جارٍ الحفظ...' : 'تعديل'">تعديل</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <div class="flex gap-3 justify-start" data-message-id="{{ $m->id }}">
                    <div
                        class="w-7 h-7 rounded-full ui-primary-surface grid place-items-center text-xs shrink-0 mt-0.5">
                        ✦</div>
                    <div class="flex-1 min-w-0 max-w-[85%] sm:max-w-[78%]">
                        <div class="rounded-2xl px-4 py-3 text-sm leading-relaxed whitespace-pre-wrap break-words"
                            dir="auto" data-full-text
                            data-preview="{{ \Illuminate\Support\Str::limit($m->content, 80, '…') }}"
                            @mouseenter="showTip($el.dataset.preview, $el)" @mouseleave="hideTip()">{{ $m->content }}</div>
                        <div class="mt-1 px-4 text-[11px] text-zinc-400 dark:text-zinc-500 text-start"><time datetime="{{ $m->created_at->toIso8601String() }}">{{ \App\Support\ArabicDateTime::forMessage($m->created_at) }}</time></div>
                        <div class="mt-1 flex items-center justify-start gap-1 px-4">
                            <button type="button" @click="copyMsg($el)" aria-label="نسخ الرسالة" :aria-label="copiedId === '{{ $m->id }}' ? 'تم النسخ' : 'نسخ الرسالة'"
                                class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors">
                                <svg x-show="copiedId !== '{{ $m->id }}'" x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>
                                <svg x-show="copiedId === '{{ $m->id }}'" x-cloak x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="w-3.5 h-3.5" style="color: var(--color-primary)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
                                <span x-text="copiedId === '{{ $m->id }}' ? 'تم النسخ' : 'نسخ'">نسخ</span>
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
        @if($messages->count() >= \App\Livewire\Chat\ConversationMessages::NAVIGATOR_THRESHOLD)
            <div class="pointer-events-none fixed left-2 top-1/2 z-20 flex -translate-y-1/2">
                <div class="group/carousel pointer-events-auto flex max-h-[46vh] flex-col justify-center gap-[3px] overflow-y-auto overscroll-contain rounded-xl border border-transparent px-1.5 py-2 [scrollbar-width:thin] transition-colors duration-150 hover:border-black/10 hover:bg-white/80 hover:backdrop-blur-sm hover:shadow-md focus-within:border-black/10 focus-within:bg-white/80 focus-within:backdrop-blur-sm focus-within:shadow-md dark:hover:border-white/10 dark:hover:bg-zinc-900/80 dark:focus-within:border-white/10 dark:focus-within:bg-zinc-900/80" role="group" aria-label="التنقل بين الرسائل">
                    @foreach($messages as $i => $m)
                        <div class="flex items-center justify-end gap-1.5">
                            <button type="button" data-nav-preview data-target-id="{{ $m->id }}"
                                @click="scrollToMsg($el)"
                                @mouseenter="previewTip($el)" @mouseleave="hideTip()"
                                @focusin="previewTip($el)" @focusout="hideTip()"
                                aria-label="الانتقال إلى الرسالة {{ \App\Support\ArabicDateTime::digits($i + 1) }}"
                                class="hidden group-hover/carousel:block group-focus-within/carousel:block w-max max-w-[180px] truncate rounded-lg px-2 py-1 text-start text-[11px] leading-relaxed text-zinc-600 dark:text-zinc-300 hover:bg-black/5 dark:hover:bg-white/10 focus-visible:bg-black/5 dark:focus-visible:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-[-2px] transition-colors">{{ \Illuminate\Support\Str::limit($m->content, 100, '…') }}</button>
                            <button type="button" data-nav-marker data-target-id="{{ $m->id }}"
                                @click="scrollToMsg($el)"
                                aria-label="الانتقال إلى الرسالة {{ \App\Support\ArabicDateTime::digits($i + 1) }}"
                                class="group/mline block shrink-0 px-1 py-[5px] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current">
                                <span class="block h-[2px] w-4 rounded-full bg-zinc-300 dark:bg-zinc-600 transition-all duration-150 group-hover/mline:w-5 group-hover/mline:bg-[var(--color-primary)]" aria-hidden="true"></span>
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        @if($messages->count() > 0)
            <div class="sticky bottom-3 z-20 mt-2 flex justify-center gap-2 pointer-events-none"
                x-show="!atTop || !atBottom" x-cloak
                x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-2">
                <button type="button" x-show="!atTop" @click="scrollToEdge('first')" aria-label="الانتقال إلى أول رسالة"
                    class="pointer-events-auto inline-flex items-center gap-1 rounded-full border border-black/10 dark:border-white/10 bg-white/90 dark:bg-zinc-900/90 backdrop-blur px-3 py-1.5 text-[11px] font-medium text-zinc-600 dark:text-zinc-300 shadow-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current transition">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 15-6-6-6 6" /></svg>
                    <span>أول رسالة</span>
                </button>
                <button type="button" x-show="!atBottom" @click="scrollToEdge('last')" aria-label="الانتقال إلى آخر رسالة"
                    class="pointer-events-auto inline-flex items-center gap-1 rounded-full border border-black/10 dark:border-white/10 bg-white/90 dark:bg-zinc-900/90 backdrop-blur px-3 py-1.5 text-[11px] font-medium text-zinc-600 dark:text-zinc-300 shadow-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current transition">
                    <span>آخر رسالة</span>
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                </button>
            </div>
        @endif
    @endif
</div>
