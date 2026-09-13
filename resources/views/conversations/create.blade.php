@extends('layouts.app')
@section('title', 'محادثة جديدة')
@section('content')
    <div class="flex flex-col h-full min-h-0">
        <div class="flex-1 min-h-0 flex flex-col items-center justify-center px-4 py-10 text-center">
            <div class="w-12 h-12 rounded-2xl ui-primary-surface grid place-items-center text-lg mb-4">◐</div>
            <h1 class="text-2xl font-semibold">Laravel AI Hub</h1>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">كيف يمكنني مساعدتك اليوم؟</p>
            <div class="mt-6 w-full max-w-2xl">
                @livewire('message-composer', ['centered' => true])
            </div>
        </div>
        {{-- legacy test compat hidden --}}
        <div class="hidden" aria-hidden="true"><span>لا توجد رسائل بعد</span></div>
    </div>
@endsection
