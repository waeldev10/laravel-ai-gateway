<div class="flex flex-col gap-3 flex-1 min-h-0"
    x-data="{
        selected: [],
        idsRev: 0,
        visibleIds() { this.idsRev; try { const v = JSON.parse(this.$refs.ids?.dataset?.ids || '[]'); return Array.isArray(v) ? v.map(String) : []; } catch (e) { return []; } },
        get allChecked() { const v = this.visibleIds(); return v.length > 0 && v.every((id) => this.selected.includes(id)); },
    }"
    x-init="new MutationObserver(() => { idsRev++; }).observe($el, { attributes: true, childList: true, subtree: true })"
    @focusin="$dispatch('close-item-menus')"
    @conversations-changed.window="selected = []">
    <form wire:submit="searchAction"
        class="flex gap-1 items-center rounded-full bg-black/5 dark:bg-white/10 border border-black/10 dark:border-white/10 px-2 py-1">
        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ms-2 shrink-0 text-zinc-400 dark:text-white/50"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
        <input type="search" wire:model.live.debounce.700ms="search" @input="selected = []" placeholder="بحث في المحادثات..."
            aria-label="بحث في المحادثات"
            class="flex-1 bg-transparent px-2 py-1.5 text-sm placeholder:text-zinc-400 dark:placeholder:text-white/50 focus:outline-none">
        @if($search)<button type="button" wire:click="clear" @click="selected = []; $dispatch('close-item-menus')" aria-label="مسح البحث"
        class="w-7 h-7 grid place-items-center rounded-full bg-black/5 dark:bg-white/10 text-xs">×</button>@endif
    </form>

    @if($results->total() > 0)
        <div class="flex gap-2 items-center">
            <span x-ref="ids" data-ids="{{ json_encode($ids) }}" class="hidden" aria-hidden="true"></span>
            <label class="text-xs flex items-center gap-1.5 cursor-pointer">
                <input type="checkbox" :checked="allChecked"
                    x-effect="$el.indeterminate = selected.length > 0 && !allChecked && visibleIds().length > 0"
                    @change="selected = $el.checked ? visibleIds() : []"
                    @click="$dispatch('close-item-menus')" aria-label="تحديد الكل"
                    class="rounded border-black/20 dark:border-white/20 ui-checkbox">
                <span>تحديد الكل</span>
            </label>
            <button type="button" x-show="selected.length > 0" x-cloak
                @click="$dispatch('close-item-menus'); window.dispatchEvent(new CustomEvent('open-confirm-modal', { detail: { id: 'page-delete', title: 'حذف المحادثات', subject: 'سيتم حذف ' + selected.length + ' من المحادثات نهائياً.', payload: { ids: [...selected] } } }))"
                class="text-xs font-medium bg-red-600 text-white px-4 py-1.5 rounded-full hover:bg-red-700 inline-flex items-center gap-1.5 min-h-[32px] transition-colors">
                <span>حذف المحدد (<span x-text="selected.length"></span>)</span>
            </button>
        </div>
    @endif

    @if($results->total() > 0)
        @include('components.conversation.conversation-nav', ['pinned' => $groups['pinned'], 'recent' => $groups['recent'], 'activeId' => null, 'context' => 'page'])
    @else
        <div class="text-center py-10 text-sm text-zinc-500 dark:text-zinc-400">
            @if($search)
                لا توجد نتائج مطابقة. جرّب كلمة أخرى أو <a wire:navigate
                    href="{{ route('conversations.create') }}" class="underline underline-offset-4">ابدأ محادثة
                    جديدة</a>.
            @else
                لا توجد محادثات بعد. <a wire:navigate href="{{ route('conversations.create') }}"
                    class="underline underline-offset-4">ابدأ محادثة جديدة</a>.
            @endif
        </div>
    @endif

    @if($results->hasMorePages())
        <div class="flex justify-center pt-1">
            <button type="button" wire:click="loadMore" wire:loading.attr="disabled" wire:target="loadMore"
                @click="$dispatch('close-item-menus')"
                class="rounded-full border border-black/10 dark:border-white/10 px-5 py-2 text-xs font-medium hover:bg-black/5 dark:hover:bg-white/10 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5 min-h-[36px] transition-colors">
                <span wire:loading wire:target="loadMore">
                    <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                </span>
                <span wire:loading.remove wire:target="loadMore">عرض المزيد ({{ $results->total() - count($results->items()) }} متبقٍ)</span>
                <span wire:loading wire:target="loadMore">جارٍ التحميل...</span>
            </button>
        </div>
    @endif

    <x-conversation.search-rename-modal />

    <x-ui.confirm-modal id="page-delete" title="حذف المحادثة" message="سيتم حذف المحادثة نهائياً. لا يمكن التراجع عن هذا الإجراء." confirmLabel="حذف" cancelLabel="إلغاء">
        <x-slot:confirm>
            <button type="button" @click="if (busy) return; busy = true; $wire.deleteConversations(payload?.ids || []).finally(() => { busy = false; })" :disabled="busy"
                class="px-5 py-2 rounded-full bg-red-600 text-white text-xs font-medium hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5 min-h-[36px] transition-colors">
                <span x-show="busy" x-cloak>
                    <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                </span>
                <span x-text="busy ? 'جارٍ الحذف...' : 'حذف'">حذف</span>
            </button>
        </x-slot:confirm>
    </x-ui.confirm-modal>
</div>
