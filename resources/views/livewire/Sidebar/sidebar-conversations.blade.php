<div class="flex min-h-0 flex-1 flex-col">
    @include('components.conversation.conversation-nav', ['pinned' => $groups['pinned'], 'recent' => $groups['recent'], 'activeId' => $activeId, 'scrollClass' => 'ui-scroll-subtle'])

    @if($groups['hasMore'])
        <a wire:navigate href="{{ route('conversations.search') }}"
            class="mx-3 mb-2 shrink-0 rounded-xl px-3 py-2 text-center text-xs font-medium text-zinc-500 hover:bg-black/5 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-200 transition-colors">عرض كل المحادثات</a>
    @endif
</div>
