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
     view). Last-message jump visibility (`showEdgeNav`) is scroll-position
     driven: the control shows only while the user is NOT at the bottom of
     the conversation (with a small threshold for sub-pixel rounding), and
     stays hidden once the latest message is reached — short conversations
     that fit on screen are therefore hidden by geometry alone. A minimum
     loaded-count gate (same NAVIGATOR_THRESHOLD) keeps the control off for
     tiny threads. `scrollToLast()` jumps to the newest loaded node and never
     fetches. Copy, edit open/cancel, tooltip hover and edge scrolling never
     touch the server: message text is always read live from the rendered
     `[data-full-text]` node (the single source), and stable
     `[data-message-id]` anchors are the only scroll targets. The single
     Livewire call here is `updateMessage(id, text)` on save, which
     validates/authorizes/persists server-side and closes the editor only on
     success, after which the re-render shows the authoritative content. --}}
<div class="space-y-6 relative" data-message-list-root data-total-count="{{ $messages->count() }}" data-edge-min="{{ \App\Livewire\Chat\ConversationMessages::NAVIGATOR_THRESHOLD }}" x-data="{
        editingId: null, busy: false,
        copiedId: null, copiedTimer: null,
        tip: false, tipTitle: '', tipTop: 0, tipLeft: 0, tipAbove: false,
        atTop: true, atBottom: true,
        showEdgeNav: false,
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
        scrollToNode(target, scroller, offset) {
            scroller.scrollTo({ top: Math.max(0, target.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop - offset), behavior: 'smooth' });
        },
        scrollToLast() {
            const scroller = this.chatScroller();
            if (!scroller) return;
            // The last message is always the newest loaded node: an
            // Alpine/DOM scroll only, never a network request.
            const items = scroller.querySelectorAll('[data-message-id]');
            if (!items.length) return;
            const target = items[items.length - 1];
            const offset = Math.max(16, scroller.clientHeight - target.clientHeight - 24);
            this.scrollToNode(target, scroller, offset);
        },
        initChatScroll() {
            const scroller = this.chatScroller();
            if (!scroller) return;
            const update = () => {
                // Last-message jump is scroll-position-driven: visible only
                // while the user is NOT at the bottom. The 48px threshold
                // absorbs sub-pixel rounding so exact equality is never
                // required. The loaded-count gate keeps tiny threads clean;
                // short conversations that fit on screen report atBottom
                // by geometry alone. No timers: this runs synchronously on
                // every scroll event plus once on init.
                const total = parseInt(this.$el.dataset.totalCount || '0', 10);
                const min = parseInt(this.$el.dataset.edgeMin || '0', 10);
                this.atTop = scroller.scrollTop < 48;
                this.atBottom = scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 48;
                this.showEdgeNav = total >= min && !this.atBottom;
            };
            update();
            // Idempotent across Livewire SPA navigations that reuse this
            // node (morph): state above refreshes every time, but the
            // scroll handler is attached only once.
            if (this.$el.dataset.chatScrollInit === '1') return;
            this.$el.dataset.chatScrollInit = '1';
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
            @include('livewire.chat.message-item', ['m' => $m])
        @endforeach
        @if($messages->count() > 0)
            <div class="sticky bottom-3 z-20 mt-2 flex justify-center pointer-events-none"
                x-show="showEdgeNav" x-cloak
                x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-2">
                <button type="button" @click="scrollToLast()" aria-label="الانتقال إلى آخر رسالة"
                    class="pointer-events-auto inline-flex items-center gap-1 rounded-full border border-black/10 dark:border-white/10 bg-white/90 dark:bg-zinc-900/90 backdrop-blur px-3 py-1.5 text-[11px] font-medium text-zinc-600 dark:text-zinc-300 shadow-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current transition">
                    <span>آخر رسالة</span>
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                </button>
            </div>
        @endif
    @endif
</div>
