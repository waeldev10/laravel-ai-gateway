@props([])

{{-- Custom hover tooltip showing a conversation's full title.
     Must live inside the conversation-nav Alpine scope, which owns the
     browser-only hover state (`tip`, `tipTitle`, `tipTop`, `tipLeft`,
     `tipAbove` plus `showTip`/`hideTip`): hovering or keyboard-focusing a
     title sets them from the row's bounding rect, leaving never touches the
     server. Teleported to <body> with viewport-clamped coordinates, so the
     sidebar's `overflow-x-hidden`/scroll containers and any z-index context
     can never clip it and it never creates a horizontal scrollbar.
     `pointer-events-none` so it can never block navigation or the actions
     menu. Same panel styling as the rest of the app (light/dark + custom
     colors flow through the existing tokens/variables, no hardcoded theme). --}}
<template x-teleport="body">
    <div class="pointer-events-none fixed z-[70]" :style="{ top: tipTop + 'px', left: tipLeft + 'px' }" x-cloak>
        <div x-show="tip"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 translate-y-1 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 translate-y-1 scale-95"
            role="tooltip"
            :class="tipAbove ? '-translate-y-full' : ''"
            class="max-w-[260px] rounded-lg border border-black/10 bg-white px-2 py-1 shadow-md dark:border-white/10 dark:bg-[#1e293b]">
            <span class="block text-[11px] font-medium leading-relaxed text-zinc-700 dark:text-zinc-200 break-words" x-text="tipTitle"></span>
        </div>
    </div>
</template>
