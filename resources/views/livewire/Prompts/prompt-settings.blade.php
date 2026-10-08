{{-- Prompt/persona settings. Server-authoritative like the profile page:
     the system default is always active and read-only, the user's own
     personas (up to 3) each toggle independently, and every mutation is
     one Livewire action (create / update / toggle / two-step remove)
     with unified toasts. No Memory UI here by design. --}}
<div class="space-y-4">
    @if($systemDefault !== null)
        <div class="rounded-2xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/[0.02] p-5 sm:p-6">
            <div class="flex items-center gap-2 flex-wrap">
                <h2 class="text-sm font-semibold">البرومبت الافتراضي للنظام</h2>
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium ui-primary-surface">افتراضي</span>
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">نشط دائماً</span>
            </div>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">يُستخدم دائماً في طلبات الذكاء الاصطناعي مع أي برومبتات مخصصة مفعّلة. لا يمكن تعديله أو حذفه أو إيقافه.</p>
            <div class="mt-3 rounded-xl bg-black/5 dark:bg-white/10 px-3.5 py-3">
                <p class="text-xs font-medium">{{ $systemDefault->name }}</p>
                <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300 leading-relaxed whitespace-pre-wrap break-words" dir="auto">{{ $systemDefault->system_prompt }}</p>
            </div>
        </div>
    @endif

    <div class="rounded-2xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/[0.02] p-5 sm:p-6">
        <h2 class="text-sm font-semibold">برومبتاتي</h2>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">يمكن تفعيل أي عدد من البرومبتات المخصصة معاً، وتُستخدم مع البرومبت الافتراضي.</p>

        @if($personas->isEmpty())
            <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">لا توجد برومبتات بعد. أنشئ برومبتك الأول من النموذج بالأسفل.</p>
        @else
            <ul class="mt-4 space-y-3">
                @foreach($personas as $persona)
                    <li class="rounded-xl border border-black/10 dark:border-white/10 px-3.5 py-3" data-persona-id="{{ $persona->id }}">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="flex-1 min-w-0 text-xs font-semibold truncate">{{ $persona->name }}</span>
                            @if($persona->is_active)
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium ui-primary-surface">مفعّل</span>
                            @endif
                        </div>

                        @if($editingId === (string) $persona->id)
                            <form wire:submit="update" class="mt-3 space-y-3">
                                <div>
                                    <x-ui.label for="prompt-edit-name-{{ $persona->id }}">الاسم</x-ui.label>
                                    <x-ui.input id="prompt-edit-name-{{ $persona->id }}" type="text" wire:model="editingName" :error="$errors->has('editingName')" maxlength="100" />
                                    <x-ui.input-error for="editingName" />
                                </div>
                                <div>
                                    <x-ui.label for="prompt-edit-text-{{ $persona->id }}">نص البرومبت</x-ui.label>
                                    <x-ui.textarea id="prompt-edit-text-{{ $persona->id }}" wire:model="editingPrompt" :error="$errors->has('editingPrompt')" rows="4" maxlength="8000" dir="auto" />
                                    <x-ui.input-error for="editingPrompt" />
                                </div>
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" wire:click="cancelEdit"
                                        class="px-4 py-2 rounded-full border border-black/10 dark:border-white/10 text-xs font-medium hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current min-h-[36px] transition-colors">إلغاء</button>
                                    <button type="submit" wire:loading.attr="disabled" wire:target="update"
                                        class="px-4 py-2 rounded-full ui-primary-btn text-xs font-medium disabled:opacity-50 disabled:cursor-not-allowed min-h-[36px] transition-colors">حفظ التعديلات</button>
                                </div>
                            </form>
                        @else
                            <p class="mt-1.5 text-xs text-zinc-600 dark:text-zinc-300 leading-relaxed whitespace-pre-wrap break-words" dir="auto">{{ \Illuminate\Support\Str::limit($persona->system_prompt, 220) }}</p>
                            <div class="mt-2.5 flex items-center gap-1.5 flex-wrap">
                                @if($persona->is_active)
                                    <button type="button" wire:click="toggle('{{ $persona->id }}')"
                                        class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium border border-black/10 dark:border-white/10 text-zinc-600 dark:text-zinc-300 hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current min-h-[32px] transition-colors">إلغاء التفعيل</button>
                                @else
                                    <button type="button" wire:click="toggle('{{ $persona->id }}')"
                                        class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium ui-primary-btn min-h-[32px] transition-colors">تفعيل</button>
                                @endif
                                <button type="button" wire:click="startEdit('{{ $persona->id }}')"
                                    class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium text-zinc-600 dark:text-zinc-300 hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current min-h-[32px] transition-colors">تعديل</button>
                                @if($confirmingId === (string) $persona->id)
                                    <button type="button" wire:click="remove('{{ $persona->id }}')"
                                        class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium bg-red-600 text-white hover:opacity-90 min-h-[32px] transition-colors">تأكيد الحذف</button>
                                    <button type="button" wire:click="$set('confirmingId', null)"
                                        class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium text-zinc-600 dark:text-zinc-300 hover:bg-black/5 dark:hover:bg-white/10 min-h-[32px] transition-colors">تراجع</button>
                                @else
                                    <button type="button" wire:click="remove('{{ $persona->id }}')"
                                        class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium text-red-600 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-500/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current min-h-[32px] transition-colors">حذف</button>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="rounded-2xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/[0.02] p-5 sm:p-6">
        <h2 class="text-sm font-semibold">برومبت جديد</h2>
        @if($canCreate)
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">مثال: «مطور لارافيل خبير» مع توجيه «أنت مطور لارافيل خبير...». يمكنك إنشاء حتى {{ $maxCustom }} برومبتات مخصصة.</p>
        <form wire:submit="create" class="mt-4 space-y-4">
            <div>
                <x-ui.label for="prompt-name">الاسم</x-ui.label>
                <x-ui.input id="prompt-name" type="text" wire:model="name" :error="$errors->has('name')" maxlength="100" placeholder="مطور لارافيل خبير" />
                <x-ui.input-error for="name" />
            </div>
            <div>
                <x-ui.label for="prompt-text">نص البرومبت</x-ui.label>
                <x-ui.textarea id="prompt-text" wire:model="prompt" :error="$errors->has('prompt')" rows="4" maxlength="8000" dir="auto" placeholder="أنت مطور لارافيل خبير..." />
                <x-ui.input-error for="prompt" />
            </div>
            <div class="flex justify-end pt-1">
                <button type="submit" wire:loading.attr="disabled" wire:target="create"
                    class="rounded-full ui-primary-btn px-6 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed min-h-[40px] inline-flex items-center gap-1.5">
                    <span wire:loading wire:target="create">
                        <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                    </span>
                    <span wire:loading.remove wire:target="create">إنشاء البرومبت</span>
                    <span wire:loading wire:target="create">جارٍ الإنشاء...</span>
                </button>
            </div>
        </form>
        @else
        <p class="mt-3 rounded-xl bg-black/5 dark:bg-white/10 px-3.5 py-3 text-xs text-zinc-600 dark:text-zinc-300 leading-relaxed">تم الوصول إلى الحد الأقصى: يمكنك إنشاء حتى {{ $maxCustom }} برومبتات مخصصة. احذف أحدها لإنشاء برومبت جديد.</p>
        @endif
    </div>
</div>
        </form>
    </div>
</div>
