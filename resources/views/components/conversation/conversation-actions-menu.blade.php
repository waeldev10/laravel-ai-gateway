@props(['context' => 'sidebar'])


<template x-teleport="body">
    <div x-ref="menu" x-show="menuId" x-cloak @click.outside="closeMenu()"
        x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
        :style="{ top: menuTop + 'px', left: menuLeft + 'px' }" role="menu" aria-label="إجراءات المحادثة" data-context="{{ $context }}"
        class="fixed z-50 w-48 rounded-xl bg-white dark:bg-[#1e293b] border border-black/10 dark:border-white/10 p-1.5 shadow-2xl origin-top">
        <button type="button" @click="renameItem(menuId)" role="menuitem"
            class="flex w-full items-center gap-2.5 text-right px-3 py-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/10 focus-visible:bg-black/5 dark:focus-visible:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current text-xs font-medium text-zinc-700 dark:text-zinc-200 min-h-[36px] transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" /><path d="m15 5 4 4" /></svg>
            <span>إعادة تسمية</span>
        </button>
        <button type="button" @click="pinItem(menuId)" role="menuitem"
            class="flex w-full items-center gap-2.5 text-right px-3 py-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/10 focus-visible:bg-black/5 dark:focus-visible:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current text-xs font-medium text-zinc-700 dark:text-zinc-200 min-h-[36px] transition-colors">
            <svg x-show="!currentItem.pinned" xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M12 17v5" /><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1z" /></svg>
            <svg x-show="currentItem.pinned" xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M12 17v5" /><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1z" /><line x1="3" y1="3" x2="21" y2="21" /></svg>
            <span x-text="currentItem.pinned ? 'إلغاء التثبيت' : 'تثبيت'">تثبيت</span>
        </button>
        <button type="button" @click="shareConversation(menuId)" role="menuitem"
            class="flex w-full items-center gap-2.5 text-right px-3 py-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/10 focus-visible:bg-black/5 dark:focus-visible:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current text-xs font-medium text-zinc-700 dark:text-zinc-200 min-h-[36px] transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" /><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" /></svg>
            <span>مشاركة</span>
        </button>
        <button type="button" @click="deleteItem(menuId)" role="menuitem"
            class="flex w-full items-center gap-2.5 text-right px-3 py-2 rounded-lg hover:bg-red-50 dark:hover:bg-red-500/10 focus-visible:bg-red-50 dark:focus-visible:bg-red-500/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current text-xs font-medium text-red-600 dark:text-red-300 min-h-[36px] transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M3 6h18" /><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" /><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" /><line x1="10" x2="10" y1="11" y2="17" /><line x1="14" x2="14" y1="11" y2="17" /></svg>
            <span>حذف المحادثة</span>
        </button>
    </div>
</template>
