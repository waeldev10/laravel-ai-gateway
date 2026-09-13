@props([
    'id',
    'title',
    'message',
    'confirmLabel' => 'حذف',
    'cancelLabel' => 'إلغاء',
    'teleport' => false,
    'livewireTarget' => null,
])

{{-- Reusable destructive-action confirmation modal (single implementation).
     Driven by browser events so both plain-form and Livewire callers share it:
       open:  window.dispatchEvent(new CustomEvent('open-confirm-modal',
                  { detail: { id: '<id>', action: '<form-url>', subject: '...', payload: {...} } }))
       close: window.dispatchEvent(new CustomEvent('close-confirm-modal',
                  { detail: { id: '<id>' } }))
     Opening/closing is purely client-side; only the confirm control talks to the
     server. The confirm control is provided by the caller through the `confirm` slot:
       - plain forms: <form :action="actionUrl" @submit="busy = true">…</form>
       - Livewire: a button calling $wire once (real request state via local busy).
     An optional `payload` object from the open event is exposed to the confirm
     slot (e.g. pending ids for an Alpine-owned selection). Callers that do not
     send a payload are unaffected (it stays null).
     No timers anywhere: loading reflects the real submission/request. --}}
<div x-data="{ open: false, busy: false, actionUrl: '', subject: '', payload: null, titleText: @js($title) }"
    @open-confirm-modal.window="if ($event.detail.id === @js($id)) { actionUrl = $event.detail.action || ''; subject = $event.detail.subject || ''; payload = $event.detail.payload || null; if ($event.detail.title) titleText = $event.detail.title; busy = false; open = true; $nextTick(() => $refs.cancelBtn?.focus()); }"
    @close-confirm-modal.window="if ($event.detail.id === @js($id)) { open = false; busy = false; payload = null; }"
    @keydown.escape.window="if (open && !busy) { open = false; }" x-cloak>
    @if($teleport)<template x-teleport="body">@endif
        <div x-show="open" class="fixed inset-0 z-[60] flex items-center justify-center p-4" role="dialog"
            aria-modal="true" aria-labelledby="{{ $id }}-title" aria-describedby="{{ $id }}-desc">
            <div x-show="open" x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="if (!busy) { open = false; }" aria-hidden="true"></div>
            <div x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100 translate-y-0" x-transition:leave-end="opacity-0 scale-95 translate-y-2"
                class="relative bg-white dark:bg-zinc-900 rounded-2xl p-5 sm:p-6 w-full max-w-md shadow-2xl border border-black/10 dark:border-white/10">
                <div class="flex items-start gap-3">
                    <span class="w-10 h-10 shrink-0 rounded-full bg-red-100 dark:bg-red-500/15 text-red-600 dark:text-red-300 grid place-items-center" aria-hidden="true">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18" /><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" /><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" /></svg>
                    </span>
                    <span class="flex-1 min-w-0">
                        <h2 id="{{ $id }}-title" class="text-base font-semibold" x-text="titleText">{{ $title }}</h2>
                        <p id="{{ $id }}-desc" class="text-sm text-zinc-500 dark:text-zinc-400 mt-1 leading-relaxed">{{ $message }}</p>
                    </span>
                </div>
                <p x-show="subject" x-cloak x-text="subject"
                    class="mt-3 text-sm font-medium truncate rounded-xl bg-black/5 dark:bg-white/10 px-3 py-2.5"></p>
                <div class="flex gap-2 justify-end mt-5">
                    <button type="button" x-ref="cancelBtn" @click="if (!busy) { open = false; }" :disabled="busy"
                        @if($livewireTarget) wire:loading.attr="disabled" wire:target="{{ $livewireTarget }}" @endif
                        class="px-5 py-2 rounded-full border border-black/10 dark:border-white/10 text-xs font-medium hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current disabled:opacity-50 disabled:cursor-not-allowed min-h-[36px] transition-colors">{{ $cancelLabel }}</button>
                    {{ $confirm }}
                </div>
            </div>
        </div>
    @if($teleport)</template>@endif
</div>
