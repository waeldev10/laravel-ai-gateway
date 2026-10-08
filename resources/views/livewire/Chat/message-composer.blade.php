<div class="{{ $centered ? 'w-full shrink-0' : 'shrink-0 border-t border-black/5 dark:border-white/10 bg-white dark:bg-[#141414] p-4' }}"
    data-chat-composer
    @if($conversation) data-stream-url="{{ route('conversations.messages.stream', $conversation) }}" data-conversation-id="{{ $conversation->id }}" @else data-new-stream-url="{{ route('conversations.stream') }}" @endif
    data-csrf="{{ csrf_token() }}"
    data-usage-status-url="{{ route('ai.usage-status') }}"
    data-usage-limited="{{ $usageLimited ? '1' : '0' }}"
    @if($usageLimited && $usageRetryAfter !== null) data-retry-after="{{ $usageRetryAfter }}" @endif>
{{-- Streaming composer: plain fetch + ReadableStream SSE, never a Livewire
     request for AI generation. The Livewire MessageComposer component still
     exists (validation fallback + existing tests) but the browser no longer
     invokes its send action — this form submits to the streaming endpoint
     and renders deltas progressively. Livewire remains for conversation
     search, rename, delete, message edit, and all other UI interactions. --}}
@if($usageLimited)
<div data-usage-limit-banner role="status" class="{{ $centered ? 'w-full' : 'max-w-3xl mx-auto' }} mb-3 flex gap-2.5 rounded-2xl border border-amber-200 dark:border-amber-400/30 bg-amber-50 dark:bg-amber-400/10 px-4 py-3">
    <svg class="w-4 h-4 shrink-0 mt-0.5 text-amber-600 dark:text-amber-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10" /><path d="M12 6v6l4 2" /></svg>
    <div class="min-w-0">
        <p class="text-xs font-semibold leading-relaxed text-amber-800 dark:text-amber-200">لقد وصلت إلى الحد المسموح من طلبات الذكاء الاصطناعي. يرجى الانتظار حتى انتهاء فترة التهدئة قبل إرسال طلب جديد.</p>
        @if($usageRetryAfter !== null && $usageRetryAfter > 0)
        <p class="mt-1 text-[11px] leading-relaxed text-amber-700 dark:text-amber-300">يمكنك إرسال طلب جديد بعد <span data-usage-countdown-text data-retry-after="{{ $usageRetryAfter }}">{{ $this->formatCooldown($usageRetryAfter) }}</span>.</p>
        @endif
    </div>
</div>
@endif
<form data-composer-form class="{{ $centered ? 'w-full' : 'max-w-3xl mx-auto' }} flex gap-3 items-end rounded-[24px] border border-black/10 dark:border-white/10 bg-[#f7f7f8] dark:bg-[#212121] p-2 shadow-sm">
<textarea data-composer-input rows="1" placeholder="اسأل أي شيء..." required maxlength="10000" @if($usageLimited) disabled @endif class="flex-1 max-h-32 overflow-y-auto bg-transparent px-3 py-2 text-sm resize-none focus:outline-none placeholder:text-zinc-500 disabled:opacity-50"></textarea>
<button type="submit" data-send-btn aria-label="إرسال" class="w-9 h-9 shrink-0 grid place-items-center rounded-full ui-primary-btn disabled:opacity-40 disabled:cursor-not-allowed" disabled>↑</button>
<button type="button" data-stop-btn aria-label="إيقاف التوليد" class="hidden w-9 h-9 shrink-0 grid place-items-center rounded-full ui-primary-btn">■</button>
</form>
<p data-composer-error class="hidden {{ $centered ? '' : 'max-w-3xl mx-auto' }} mt-2 text-xs text-red-600"></p>
@if(!$centered)<p class="max-w-3xl mx-auto mt-2 text-[11px] text-zinc-500 text-center">اضغط Enter للإرسال و Shift+Enter لسطر جديد</p>@endif
</div>
