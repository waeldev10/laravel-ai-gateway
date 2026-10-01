<div class="{{ $centered ? 'w-full' : 'border-t border-black/5 dark:border-white/10 bg-white dark:bg-[#141414] p-4' }}" x-data="{text: @entangle('content')}">
{{-- NOTE: plain @entangle is deferred by default (typing sends no request;
     the latest value rides along with the submit request). Do NOT chain
     `.defer` (not a valid entangle modifier: it evaluates to `undefined`
     and silently destroys the two-way sync, so Livewire keeps validating
     a stale empty `content`) or `.live` (a request per keystroke). --}}
<form wire:submit="send" class="{{ $centered ? 'w-full' : 'max-w-3xl mx-auto' }} flex gap-3 items-end rounded-[24px] border border-black/10 dark:border-white/10 bg-[#f7f7f8] dark:bg-[#212121] p-2 shadow-sm">
<textarea x-ref="input" x-model="text" rows="1" @keydown.enter.exact.prevent="if(text.trim()) $wire.send()" @keydown.shift.enter.stop placeholder="اسأل أي شيء..." required maxlength="10000" class="flex-1 max-h-32 bg-transparent px-3 py-2 text-sm resize-none focus:outline-none placeholder:text-zinc-500"></textarea>
<button type="submit" wire:loading.attr="disabled" :disabled="!text.trim()" aria-label="إرسال" class="w-9 h-9 shrink-0 grid place-items-center rounded-full ui-primary-btn disabled:opacity-40 disabled:cursor-not-allowed">
<span wire:loading.remove>↑</span><span wire:loading>…</span>
</button>
</form>
@error('content')<p class="{{ $centered ? '' : 'max-w-3xl mx-auto' }} mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
@if(!$centered)<p class="max-w-3xl mx-auto mt-2 text-[11px] text-zinc-500 text-center">اضغط Enter للإرسال و Shift+Enter لسطر جديد</p>@endif
</div>
