@props(['pinned', 'recent', 'activeId' => null, 'context' => 'sidebar', 'scrollClass' => ''])


<div 
    x-data="{
        listContext: @js($context),
        menuId: null, menuTop: -9999, menuLeft: -9999,
        copiedId: null,
        tip: false, tipTitle: '', tipTop: 0, tipLeft: 0, tipAbove: false,
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
        closeMenu() { this.menuId = null; this.tip = false; },
        currentItem: { title: '', url: '', delete: '', update: '', pinned: false },
        openMenu(id, btn) {
            if (this.menuId === id) { this.closeMenu(); return; }
            this.currentItem = { title: btn.dataset.title || '', url: btn.dataset.url || '', delete: btn.dataset.delete || '', update: btn.dataset.update || '', pinned: btn.dataset.pinned === '1' };
            const r = btn.getBoundingClientRect();
            this.menuId = id;
            this.$nextTick(() => {
                const m = this.$refs.menu;
                if (!m) return;
                const w = m.offsetWidth || 176, h = m.offsetHeight || 200;
                this.menuLeft = Math.min(Math.max(8, Math.round(r.left)), Math.max(8, window.innerWidth - w - 8));
                const below = Math.round(r.bottom + 6);
                this.menuTop = (below + h > window.innerHeight - 8) ? Math.max(8, Math.round(r.top - h - 6)) : below;
            });
        },
        closeMenu() { this.menuId = null; this.tip = false; },
        renameItem(id) {
            if (this.listContext === 'page') {
                const title = this.currentItem.title || '';
                this.closeMenu();
                window.dispatchEvent(new CustomEvent('open-rename-modal', { detail: { id: 'search-rename', title: title, conversationId: id } }));
            }
            else { this.askRename(); }
        },
        pinItem(id) { this.$wire.togglePin(id); this.closeMenu(); },
        deleteItem(id) {
            if (this.listContext === 'page') {
                const subject = this.currentItem.title || '';
                this.closeMenu();
                window.dispatchEvent(new CustomEvent('open-confirm-modal', { detail: { id: 'page-delete', title: 'حذف المحادثة', subject: subject, payload: { ids: [id] } } }));
            }
            else { this.askDelete(); }
        },
        askRename() {
            const action = this.currentItem.update || '', title = this.currentItem.title || '';
            this.closeMenu();
            window.dispatchEvent(new CustomEvent('open-rename-modal', { detail: { id: 'sidebar-rename', action: action, title: title } }));
        },
        askDelete() {
            const action = this.currentItem.delete || '', subject = this.currentItem.title || '';
            this.closeMenu();
            window.dispatchEvent(new CustomEvent('open-confirm-modal', { detail: { id: 'sidebar-delete', action: action, subject: subject } }));
        },
        shareConversation(id) {
            const url = this.currentItem.url || '';
            this.closeMenu();
            const done = (ok) => {
                this.copiedId = ok ? id : 'failed';
                setTimeout(() => { if (this.copiedId === id || this.copiedId === 'failed') this.copiedId = null; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText && url) {
                navigator.clipboard.writeText(url).then(() => done(true)).catch(() => done(false));
            } else { done(false); }
        },
    }" @scroll="closeMenu()" @scroll.window="closeMenu()" @resize.window="closeMenu()"
    @keydown.escape.window="closeMenu()" @close-item-menus.window="closeMenu()"
    class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto overflow-x-hidden px-1 py-1 {{ $scrollClass }}">

    <x-conversation.title-tip />

    @foreach([['label' => 'المثبتة', 'items' => $pinned], ['label' => 'الأخيرة', 'items' => $recent]] as $group)

        @if($group['items']->isNotEmpty())
            <p class="px-3 pt-2 pb-0.5 text-[11px] font-medium text-zinc-400 dark:text-zinc-500">{{ $group['label'] }}</p>

            @foreach($group['items'] as $c)

                <div class="group relative flex min-h-[44px] items-center gap-1 rounded-xl px-1 transition-colors hover:bg-black/5 dark:hover:bg-white/10 focus-within:bg-black/5 dark:focus-within:bg-white/10 {{ $activeId && (string) $c->id === (string) $activeId ? 'ui-accent-soft font-medium text-black dark:text-white' : 'text-zinc-600 dark:text-zinc-300' }}"
                   
                    @if($context === 'page') wire:key="conv-{{ $c->id }}" @endif

                    @if($activeId && (string) $c->id === (string) $activeId) aria-current="page" @endif>
                    @if($context === 'page')
                        <input type="checkbox" x-model="selected" value="{{ $c->id }}"
                            @click="$dispatch('close-item-menus')" aria-label="تحديد {{ $c->title }}"
                            class="ms-2 shrink-0 rounded border-black/20 dark:border-white/20 ui-checkbox">
                    @endif
                        <div class="flex-1 min-w-0 flex items-center gap-1">
                            @if($c->pinned_at)
                                <svg class="w-3.5 h-3.5 shrink-0 ms-2 text-zinc-400 dark:text-white/50" viewBox="0 0 24 24" fill="currentColor" aria-label="مثبتة" role="img">
                                    <path d="M12 17v5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                                    <path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1z" />
                                </svg>
                            @endif
                            <a wire:navigate
                                href="{{ route('conversations.show', $c) }}"
                                data-full-title="{{ $c->title }}"
                                @mouseenter="showTip($el.dataset.fullTitle, $el)" @mouseleave="hideTip()"
                                @focusin="showTip($el.dataset.fullTitle, $el)" @focusout="hideTip()"
                                class="flex-1 min-w-0 px-2 py-1.5 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current">
                                <span class="block text-sm truncate">{{ $c->title }}</span>
                                @if($context === 'page')
                                    <span class="block text-[11px] text-zinc-400 dark:text-zinc-500 truncate">{{ \App\Support\ArabicDateTime::forSearch($c->created_at) }}</span>
                                @endif
                            </a>
                            @if($context !== 'page')
                                <span class="shrink-0 whitespace-nowrap text-[11px] text-zinc-400 dark:text-zinc-500 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 transition-opacity duration-150">{{ \App\Support\ArabicDateTime::timeOnly($c->created_at) }}</span>
                            @endif
                            <div class="relative shrink-0">
                                <button @click.stop="openMenu(@js((string) $c->id), $el)" aria-label="خيارات {{ $c->title }}"
                                    aria-haspopup="menu" :aria-expanded="menuId === @js((string) $c->id) ? 'true' : 'false'" data-title="{{ $c->title }}"
                                    data-url="{{ route('conversations.show', $c) }}"
                                    data-delete="{{ route('conversations.destroy', $c) }}"
                                    data-update="{{ route('conversations.update', $c) }}"
                                    data-pinned="{{ $c->pinned_at ? '1' : '0' }}"
                                    class="w-8 h-8 grid place-items-center rounded-full text-zinc-400 dark:text-white/60 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-600 dark:hover:text-zinc-200 focus-visible:bg-black/5 dark:focus-visible:bg-white/10 focus-visible:text-zinc-600 dark:focus-visible:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100 lg:focus-visible:opacity-100"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg></button>
                                <span x-show="copiedId === @js((string) $c->id)" x-cloak
                                    class="absolute top-full mt-1 end-0 whitespace-nowrap rounded-full bg-black text-white dark:bg-white dark:text-black px-2 py-0.5 text-[11px]">تم
                                    النسخ</span>
                                <span x-show="copiedId === 'failed'" x-cloak
                                    class="absolute top-full mt-1 end-0 whitespace-nowrap rounded-full bg-red-600 text-white px-2 py-0.5 text-[11px]">تعذر
                                    النسخ</span>
                            </div>
                        </div>
                </div>

            @endforeach

        @endif

    @endforeach

    @if($pinned->isEmpty() && $recent->isEmpty())
        <p class="text-xs text-zinc-400 dark:text-white/50 px-3 py-4 text-center">لا توجد محادثات</p>
    @endif

    <x-conversation.conversation-actions-menu :context="$context" />

    @if($context === 'sidebar')

        <x-ui.confirm-modal id="sidebar-delete" title="حذف المحادثة" message="سيتم حذف هذه المحادثة نهائياً. لا يمكن التراجع عن هذا الإجراء." confirmLabel="حذف" cancelLabel="إلغاء" :teleport="true">
            <x-slot:confirm>
                <form :action="actionUrl" method="POST" @submit.prevent="if (busy) return; busy = true; (async () => { const createUrl = @js(route('conversations.create')); try { const res = await fetch(actionUrl, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData($el) }); if (!res.ok) { let message = 'تعذر الحذف. حاول مرة أخرى.'; try { const data = await res.json(); message = data?.message || message; } catch (e) {} window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', title: 'تعذر الحذف', message: message } })); return; } let deletedPath = ''; try { deletedPath = new URL(actionUrl, window.location.origin).pathname; } catch (e) {} const currentPath = window.location.pathname || ''; const isOpen = deletedPath !== '' && (currentPath === deletedPath || currentPath.startsWith(deletedPath + '/')); open = false; if (isOpen) { window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', title: 'تم حذف المحادثة', message: 'تم حذف المحادثة نهائياً.' } })); if (window.Livewire && window.Livewire.navigate) window.Livewire.navigate(createUrl); else window.location.href = createUrl; } else { if (window.Livewire) window.Livewire.dispatch('conversations-changed'); window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', title: 'تم حذف المحادثة', message: 'تم حذف المحادثة نهائياً.' } })); } } catch (e) { window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', title: 'تعذر الحذف', message: 'تعذر الاتصال بالخادم.' } })); } finally { busy = false; } })()" class="contents">
                    @csrf @method('DELETE')
                    <button type="submit" :disabled="busy"
                        class="px-4 py-1.5 rounded-full bg-red-600 text-white text-xs font-medium hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5 min-h-[32px]">
                        <svg x-show="busy" x-cloak class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                        <span x-text="busy ? 'جارٍ الحذف...' : 'حذف'">حذف</span>
                    </button>
                </form>
            </x-slot:confirm>
        </x-ui.confirm-modal>
        <x-sidebar.sidebar-rename-modal id="sidebar-rename" />

    @endif

</div>
