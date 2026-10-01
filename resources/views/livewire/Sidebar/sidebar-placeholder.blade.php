@props(['active' => null])

{{-- Lazy placeholder: paints instantly with the page shell. When a conversation
     page is open, its single row renders synchronously (one primary-key
     lookup, already highlighted) so active navigation state never depends on
     hydration; the rest of the list stays deferred behind the skeleton. --}}
<div class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto overflow-x-hidden px-1 py-1 ui-scroll-subtle" aria-busy="true" aria-label="جارٍ تحميل المحادثات">
    <p class="sr-only">جارٍ تحميل المحادثات…</p>

    @if($active)
        <div class="group relative flex min-h-[44px] items-center gap-1 rounded-xl px-1 ui-accent-soft font-medium text-black dark:text-white" aria-current="page">
            <div class="flex-1 min-w-0 flex items-center gap-1">
                @if($active->pinned_at)
                    <svg class="w-3.5 h-3.5 shrink-0 ms-2 text-zinc-400 dark:text-white/50" viewBox="0 0 24 24" fill="currentColor" aria-label="مثبتة" role="img">
                        <path d="M12 17v5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        <path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1z" />
                    </svg>
                @endif
                <a wire:navigate href="{{ route('conversations.show', $active) }}"
                    class="flex-1 min-w-0 px-2 py-1.5 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current">
                    <span class="block text-sm truncate">{{ $active->title }}</span>
                </a>
            </div>
        </div>
    @endif

    <div class="flex flex-col gap-2 px-3 py-2" aria-hidden="true">
        <div class="h-11 rounded-xl bg-black/5 dark:bg-white/5 animate-pulse"></div>
        <div class="h-11 rounded-xl bg-black/5 dark:bg-white/5 animate-pulse"></div>
        <div class="h-11 rounded-xl bg-black/5 dark:bg-white/5 animate-pulse"></div>
        <div class="h-11 rounded-xl bg-black/5 dark:bg-white/5 animate-pulse"></div>
    </div>
</div>
