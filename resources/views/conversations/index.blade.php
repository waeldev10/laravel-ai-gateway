@extends('layouts.app')

@section('title', 'المحادثات')

@section('content')
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">المحادثات</h1>
            <p class="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                جميع محادثاتك في مكان واحد.
            </p>
        </div>

        <a
            wire:navigate
            href="{{ route('conversations.create') }}"
            class="rounded-sm border border-black bg-[#1b1b18] px-4 py-1.5 text-sm text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
        >
            محادثة جديدة
        </a>
    </div>

    <form method="GET" action="{{ route('conversations.index') }}" class="mt-6 flex items-center gap-2">
        <input
            type="search"
            name="search"
            value="{{ $search }}"
            placeholder="ابحث في محادثاتك..."
            class="w-full rounded-sm border border-[#19140035] bg-white px-3 py-2 text-sm focus:border-black focus:outline-none dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
        >
        @if ($search)
            <a
                wire:navigate
                href="{{ route('conversations.index') }}"
                aria-label="مسح البحث"
                title="مسح البحث"
                class="shrink-0 rounded-sm border border-[#19140035] px-3 py-2 text-sm hover:border-black dark:border-[#3E3E3A]"
            >
                ×
            </a>
        @endif
        <button
            type="submit"
            class="shrink-0 rounded-sm border border-black bg-[#1b1b18] px-4 py-2 text-sm text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
        >
            بحث
        </button>
    </form>

    @if ($conversations->isEmpty())
        <div class="mt-8 rounded-sm border border-dashed border-[#19140035] p-6 text-center text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
            @if ($search)
                لا توجد محادثات مطابقة لبحثك عن «{{ $search }}».
                <a wire:navigate href="{{ route('conversations.index') }}" class="font-medium underline underline-offset-4">
                    مسح البحث
                </a>
            @else
                لا توجد محادثات بعد.
                <a wire:navigate href="{{ route('conversations.create') }}" class="font-medium underline underline-offset-4">
                    ابدأ محادثتك الأولى
                </a>
            @endif
        </div>
    @else
        <div x-data="{ selected: [], deleteTarget: null, confirmBulkDelete: false }" class="mt-8">
            <form
                method="POST"
                action="{{ route('conversations.destroyMany') }}"
                x-ref="bulkForm"
                x-show="selected.length > 0"
                x-cloak
                x-transition
                class="mb-6 flex items-center justify-between gap-3 rounded-sm border border-[#19140035] bg-[#19140008] px-4 py-3 dark:border-[#3E3E3A] dark:bg-[#3E3E3A33]"
            >
                @csrf
                @method('DELETE')

                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>

                <p class="text-sm">
                    تم تحديد <span class="font-medium" x-text="selected.length"></span> محادثة
                </p>

                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        @click="selected = []"
                        class="rounded-sm border border-[#19140035] px-4 py-1.5 text-sm hover:border-black dark:border-[#3E3E3A]"
                    >
                        إلغاء التحديد
                    </button>
                    <button
                        type="button"
                        @click="confirmBulkDelete = true"
                        class="rounded-sm border border-red-600 px-4 py-1.5 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20"
                    >
                        حذف المحدد
                    </button>
                </div>
            </form>

            <ul class="divide-y divide-[#19140014] rounded-sm border border-[#19140035] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]">
                <li class="flex items-center gap-3 px-4 py-2 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    <input
                        type="checkbox"
                        x-bind:checked="selected.length === {{ $conversations->count() }}"
                        @change="selected = $event.target.checked ? @js($conversations->modelKeys()) : []"
                        aria-label="تحديد الكل"
                        class="shrink-0 rounded-sm border-[#19140035] dark:border-[#3E3E3A]"
                    >
                    <span>تحديد الكل</span>
                </li>

                @foreach ($conversations as $conversation)
                    <li class="flex items-center gap-3 px-4 py-3 hover:bg-[#19140008] dark:hover:bg-[#3E3E3A33]">
                        <input
                            type="checkbox"
                            x-model="selected"
                            :value="{{ $conversation->id }}"
                            aria-label="تحديد {{ $conversation->title }}"
                            class="shrink-0 rounded-sm border-[#19140035] dark:border-[#3E3E3A]"
                        >

                        <a
                            wire:navigate
                            href="{{ route('conversations.show', $conversation) }}"
                            class="flex flex-1 items-center justify-between gap-4 min-w-0"
                        >
                            <span class="truncate font-medium">{{ $conversation->title }}</span>
                            <span class="shrink-0 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                {{ $conversation->created_at->translatedFormat('j F Y') }}
                            </span>
                        </a>

                        <form
                            method="POST"
                            action="{{ route('conversations.destroy', $conversation) }}"
                            x-ref="singleDelete{{ $conversation->id }}"
                        >
                            @csrf
                            @method('DELETE')
                            <button
                                type="button"
                                @click="deleteTarget = { id: {{ $conversation->id }}, title: @js($conversation->title) }"
                                aria-label="حذف {{ $conversation->title }}"
                                class="shrink-0 rounded-sm border border-[#19140035] px-2.5 py-1 text-xs text-[#706f6c] hover:border-red-600 hover:text-red-600 dark:border-[#3E3E3A] dark:text-[#A1A09A]"
                            >
                                حذف
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>

            <div
                x-show="deleteTarget !== null"
                x-cloak
                x-transition
                role="dialog"
                aria-modal="true"
                class="fixed inset-0 z-50 flex items-center justify-center p-6"
            >
                <div class="fixed inset-0 bg-black/50" @click="deleteTarget = null"></div>

                <div class="relative w-full max-w-md rounded-sm border border-[#19140035] bg-white p-6 dark:border-[#3E3E3A] dark:bg-[#161615]">
                    <h2 class="text-lg font-semibold">حذف المحادثة</h2>
                    <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                        هل أنت متأكد من حذف محادثة «<span x-text="deleteTarget?.title"></span>»؟
                        سيؤدي هذا إلى حذف جميع الرسائل المرتبطة بها. لا يمكن التراجع عن هذا الإجراء.
                    </p>

                    <div class="mt-6 flex items-center justify-end gap-3">
                        <button
                            type="button"
                            @click="deleteTarget = null"
                            class="rounded-sm border border-[#19140035] px-4 py-1.5 text-sm hover:border-black dark:border-[#3E3E3A]"
                        >
                            إلغاء
                        </button>
                        <button
                            type="button"
                            @click="$refs['singleDelete' + deleteTarget.id].submit()"
                            class="rounded-sm border border-red-600 bg-red-600 px-4 py-1.5 text-sm text-white hover:bg-red-700"
                        >
                            حذف نهائياً
                        </button>
                    </div>
                </div>
            </div>

            <div
                x-show="confirmBulkDelete"
                x-cloak
                x-transition
                role="dialog"
                aria-modal="true"
                class="fixed inset-0 z-50 flex items-center justify-center p-6"
            >
                <div class="fixed inset-0 bg-black/50" @click="confirmBulkDelete = false"></div>

                <div class="relative w-full max-w-md rounded-sm border border-[#19140035] bg-white p-6 dark:border-[#3E3E3A] dark:bg-[#161615]">
                    <h2 class="text-lg font-semibold">حذف المحادثات المحددة</h2>
                    <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                        هل أنت متأكد من حذف
                        <span class="font-medium" x-text="selected.length"></span>
                        محادثة؟
                        سيؤدي هذا إلى حذف جميع الرسائل المرتبطة بها. لا يمكن التراجع عن هذا الإجراء.
                    </p>

                    <div class="mt-6 flex items-center justify-end gap-3">
                        <button
                            type="button"
                            @click="confirmBulkDelete = false"
                            class="rounded-sm border border-[#19140035] px-4 py-1.5 text-sm hover:border-black dark:border-[#3E3E3A]"
                        >
                            إلغاء
                        </button>
                        <button
                            type="button"
                            @click="$refs.bulkForm.submit()"
                            class="rounded-sm border border-red-600 bg-red-600 px-4 py-1.5 text-sm text-white hover:bg-red-700"
                        >
                            حذف نهائياً
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection