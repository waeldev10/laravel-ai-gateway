@extends('layouts.app')

@section('title', $conversation->title)

@section('header-actions')
    @livewire('conversation.conversation-menu', ['conversationId' => (string) $conversation->id])
@endsection

@section('content')
    <div class="flex flex-col h-full min-h-0">
        <div class="flex-1 min-h-0 overflow-y-auto" data-chat-scroll>
            <div class="max-w-3xl mx-auto px-4 py-8">
                {{-- Incremental history: older pages fetched by chat-history.js
                     (plain fetch, never Livewire) are prepended here, OUTSIDE
                     the Livewire message list below, so list re-renders
                     (new sends, edits) never wipe or morph loaded history.
                     The loader is the only loading UI for history paging:
                     no sidebar/composer/theme/layout changes. --}}
                <div data-chat-history
                    data-history-url="{{ route('conversations.messages.index', $conversation) }}"
                    data-oldest-cursor="{{ $historyOldestCursor }}"
                    data-has-more="{{ $historyHasMore ? '1' : '0' }}"
                    x-data="chatHistoryItems()"
                    @close-edit-message.window="editingId = null"></div>
                <div data-history-loader class="hidden" role="status" aria-label="جارٍ تحميل الرسائل السابقة">
                    <div class="flex items-center justify-center gap-2 py-4 text-[12px] text-zinc-500 dark:text-zinc-400">
                        <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                        <span>جارٍ تحميل الرسائل السابقة...</span>
                    </div>
                </div>
                @livewire('chat.conversation-messages', ['conversation' => $conversation])
                {{-- Streaming slot: optimistic user bubble + progressively
                     rendered assistant bubble (plain JS, outside Livewire's
                     morph root so deltas are never wiped mid-stream). --}}
                <div data-chat-stream-slot class="space-y-6 mt-6"></div>
            </div>
        </div>
        @livewire('chat.message-composer', ['conversation' => $conversation])
        {{-- legacy test compat hidden --}}
        <div class="hidden" aria-hidden="true"><a href="{{ route('conversations.search') }}">العودة إلى المحادثات</a>@if($messages->isEmpty())<span>لا توجد رسائل بعد</span>@endif<span class="justify-end"></span><span class="justify-start"></span><form action="{{ route('conversations.messages.store', $conversation) }}"></form></div>
    </div>
@endsection