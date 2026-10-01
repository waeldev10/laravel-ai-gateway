@extends('layouts.app')

@section('title', 'بحث')

@section('content')
    <div class="max-w-3xl mx-auto px-4 py-8 w-full">
        <div class="flex items-center justify-between gap-3 mb-4">
            <h1 class="text-xl font-semibold">بحث</h1>
            <a wire:navigate href="{{ route('conversations.create') }}"
                class="shrink-0 rounded-full ui-primary-btn px-5 py-2 text-sm font-medium">＋
                محادثة جديدة</a>
        </div>
        @livewire('conversation.conversation-search')
    </div>
@endsection
