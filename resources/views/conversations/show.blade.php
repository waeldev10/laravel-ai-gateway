@extends('layouts.app')

@section('title', $conversation->title)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="truncate text-2xl font-semibold">{{ $conversation->title }}</h1>
            <p class="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                أُنشئت في {{ $conversation->created_at->translatedFormat('j F Y') }}
            </p>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-3">
            <a
                wire:navigate
                href="{{ route('conversations.index') }}"
                class="rounded-sm border border-[#19140035] px-4 py-1.5 text-sm hover:border-black dark:border-[#3E3E3A]"
            >
                العودة إلى المحادثات
            </a>

            <div x-data="{ open: false }" class="relative">
                <button
                    type="button"
                    @click="open = true"
                    class="rounded-sm border border-red-600 px-4 py-1.5 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20"
                >
                    حذف المحادثة
                </button>

                <div
                    x-show="open"
                    x-cloak
                    x-transition
                    role="dialog"
                    aria-modal="true"
                    class="fixed inset-0 z-50 flex items-center justify-center p-6"
                >
                    <div class="fixed inset-0 bg-black/50" @click="open = false"></div>

                    <div class="relative w-full max-w-md rounded-sm border border-[#19140035] bg-white p-6 dark:border-[#3E3E3A] dark:bg-[#161615]">
                        <h2 class="text-lg font-semibold">حذف المحادثة</h2>
                        <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                            هل أنت متأكد من حذف محادثة «{{ $conversation->title }}»؟
                            سيؤدي هذا إلى حذف جميع الرسائل المرتبطة بها. لا يمكن التراجع عن هذا الإجراء.
                        </p>

                        <div class="mt-6 flex items-center justify-end gap-3">
                            <button
                                type="button"
                                @click="open = false"
                                class="rounded-sm border border-[#19140035] px-4 py-1.5 text-sm hover:border-black dark:border-[#3E3E3A]"
                            >
                                إلغاء
                            </button>

                            <form method="POST" action="{{ route('conversations.destroy', $conversation) }}">
                                @csrf
                                @method('DELETE')
                                <button
                                    type="submit"
                                    class="rounded-sm border border-red-600 bg-red-600 px-4 py-1.5 text-sm text-white hover:bg-red-700"
                                >
                                    حذف نهائياً
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="mt-6 rounded-sm border border-green-600/30 bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-950/20 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="mt-8 space-y-4">
        @if ($messages->isEmpty())
            <div class="rounded-sm border border-dashed border-[#19140035] p-6 text-center text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
                لا توجد رسائل بعد. ابدأ المحادثة برسالتك الأولى.
            </div>
        @else
            @foreach ($messages as $message)
                @if ($message->role === \App\Enums\MessageRole::User)
                    <div class="flex justify-end">
                        <div class="max-w-[85%] break-words rounded-sm border border-[#19140035] bg-[#1b1b18] px-4 py-3 text-white sm:max-w-[70%] dark:bg-[#EDEDEC] dark:text-[#1b1b18]">
                            <p class="whitespace-pre-wrap text-sm">{{ $message->content }}</p>
                            <p class="mt-1 text-xs opacity-70">{{ $message->created_at->translatedFormat('g:i A') }}</p>
                        </div>
                    </div>
                @else
                    <div class="flex justify-start">
                        <div class="max-w-[85%] break-words rounded-sm border border-[#19140035] bg-[#F5F5F4] px-4 py-3 sm:max-w-[70%] dark:bg-[#161615]">
                            <p class="whitespace-pre-wrap text-sm">{{ $message->content }}</p>
                            <p class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">{{ $message->created_at->translatedFormat('g:i A') }}</p>
                        </div>
                    </div>
                @endif
            @endforeach
        @endif
    </div>

    <form
        x-data="{ content: @js(old('content', '')), sending: false }"
        method="POST"
        action="{{ route('conversations.messages.store', $conversation) }}"
        x-ref="chatForm"
        class="mt-8 rounded-sm border border-[#19140035] p-4 dark:border-[#3E3E3A]"
    >
        @csrf

        <label for="content" class="block text-sm font-medium">رسالتك</label>
        <textarea
            x-model="content"
            id="content"
            name="content"
            rows="3"
            required
            maxlength="10000"
            @keydown.ctrl.enter.prevent="$refs.chatForm.requestSubmit()"
            @keydown.meta.enter.prevent="$refs.chatForm.requestSubmit()"
            class="mt-1 w-full resize-y rounded-sm border border-[#19140035] bg-white px-3 py-2 text-sm focus:border-black focus:outline-none dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
            placeholder="اكتب رسالتك هنا..."
        ></textarea>
        @error('content')
            <p role="alert" class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror

        <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                اضغط Ctrl + Enter للإرسال، وEnter لإضافة سطر جديد
            </p>
            <button
                type="submit"
                x-bind:disabled="!content.trim()"
                class="shrink-0 rounded-sm border border-black bg-[#1b1b18] px-5 py-2 text-sm text-white hover:bg-black disabled:cursor-not-allowed disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
            >
                إرسال
            </button>
        </div>
    </form>
@endsection