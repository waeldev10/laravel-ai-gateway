@extends('layouts.app')
@section('title', 'المحادثات')
@section('content')
    <div class="max-w-3xl mx-auto px-4 py-8">
        @if($conversations->isEmpty() && !$search)
            <div class="text-center py-12 space-y-3">
                <h1 class="text-2xl font-semibold">مرحباً بك</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">اختر محادثة من الشريط الجانبي أو ابدأ محادثة جديدة.</p>
                <a href="{{ route('conversations.create') }}"
                    class="inline-block mt-4 rounded-full ui-primary-btn px-6 py-2.5 text-sm font-medium">محادثة
                    جديدة</a>
            </div>
        @else
            <div class="flex items-center justify-between gap-3 mb-4">
                <div>
                    <h1 class="text-xl font-semibold">المحادثات</h1>
                    @if($search)
                        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">نتائج البحث عن «{{ $search }}» —
                            {{ $conversations->count() }} نتيجة</p>
                    @else
                        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $conversations->count() }} محادثة</p>
                    @endif
                </div>
                <a href="{{ route('conversations.create') }}"
                    class="shrink-0 rounded-full ui-primary-btn px-5 py-2 text-sm font-medium">＋
                    محادثة جديدة</a>
            </div>
        @endif
        <div class="grid gap-2" x-data='{selected: [], allIds: @js(array_map("strval", $conversations->modelKeys()))}'>
            @if($conversations->isNotEmpty())
                <div class="flex gap-2 mb-2 items-center flex-wrap">
                    <label class="text-xs flex items-center gap-1.5 cursor-pointer min-h-[32px]">
                        <input type="checkbox"
                            :checked="allIds.length > 0 && selected.length === allIds.length"
                            x-effect="$el.indeterminate = selected.length > 0 && selected.length < allIds.length"
                            @change="selected = $el.checked ? [...allIds] : []"
                            aria-label="تحديد الكل"
                            class="rounded border-black/20 dark:border-white/20 w-4 h-4 ui-checkbox">
                        <span>تحديد الكل</span>
                    </label>
                    <button x-show="selected.length > 0" x-cloak
                        @click="window.dispatchEvent(new CustomEvent('open-confirm-modal', { detail: { id: 'index-bulk-delete', action: '{{ route('conversations.destroyMany') }}', subject: 'سيتم حذف ' + selected.length + ' من المحادثات نهائياً.' } }))"
                        class="text-xs font-medium bg-red-600 text-white px-4 py-1.5 rounded-full hover:bg-red-700 inline-flex items-center gap-1 min-h-[32px] transition-colors">حذف المحدد (<span
                            x-text="selected.length"></span>)</button>
                </div>
            @endif
            @foreach($conversations as $c)
                <div
                    class="flex items-center gap-2 p-4 rounded-2xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/[0.02] hover:bg-zinc-50 dark:hover:bg-white/5 transition">
                    <input type="checkbox" x-model="selected" value="{{ $c->id }}"
                        aria-label="تحديد {{ $c->title }}"
                        class="rounded border-black/20 dark:border-white/20 w-4 h-4 shrink-0 ui-checkbox">
                    <a wire:navigate href="{{ route('conversations.show', $c) }}"
                        class="flex-1 min-w-0 flex justify-between items-center gap-3 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current">
                        <span class="truncate font-medium text-sm">{{ $c->title }}</span><span
                            class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ $c->created_at->translatedFormat('j F Y') }}</span>
                    </a>
                </div>
            @endforeach
            @if($conversations->isEmpty() && $search)
                <div class="text-center py-10 text-sm text-zinc-500 dark:text-zinc-400">لا توجد نتائج مطابقة. جرّب كلمة أخرى أو
                    <a wire:navigate href="{{ route('conversations.create') }}" class="underline underline-offset-4">ابدأ محادثة جديدة</a>.
                </div>
            @endif
            @if($search)
                <div class="flex justify-center pt-2">
                    <a wire:navigate aria-label="مسح البحث" href="{{ route('conversations.index') }}"
                        class="rounded-full border border-black/10 dark:border-white/10 px-5 py-1.5 text-xs hover:bg-black/5 dark:hover:bg-white/10 min-h-[32px] inline-flex items-center">مسح البحث ×</a>
                </div>
            @endif
            <x-ui.confirm-modal id="index-bulk-delete" title="حذف المحادثات" message="سيتم حذف المحادثات المحددة نهائياً. لا يمكن التراجع عن هذا الإجراء." confirmLabel="حذف" cancelLabel="إلغاء" :teleport="true">
                <x-slot:confirm>
                    <form :action="actionUrl" method="POST" @submit="if (busy) { $event.preventDefault(); } else { busy = true; }" class="contents">
                        @csrf @method('DELETE')
                        <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                        <button type="submit" :disabled="busy"
                            class="px-5 py-2 rounded-full bg-red-600 text-white text-xs font-medium hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5 min-h-[36px]">
                            <svg x-show="busy" x-cloak class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                            <span x-text="busy ? 'جارٍ الحذف...' : 'حذف'">حذف</span>
                        </button>
                    </form>
                </x-slot:confirm>
            </x-ui.confirm-modal>
        </div>
    </div>
@endsection