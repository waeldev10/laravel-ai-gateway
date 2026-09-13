{{-- Profile page content: header (from Livewire state, i.e. persisted data)
     plus tabbed forms. Tab switching is purely Alpine (`tab` lives only here,
     never in Livewire), so it costs no server request and survives Livewire
     re-renders (this root element is never replaced, only morphed inside).
     Both saves are single Livewire actions: no reload, no redirect. --}}
<div x-data="{ tab: 'profile' }">
    <div class="rounded-2xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/[0.02] p-5 sm:p-6">
        <div class="flex items-center gap-3.5">
            <span class="w-14 h-14 rounded-full ui-primary-surface grid place-items-center text-xl font-semibold shrink-0" aria-hidden="true">{{ mb_substr($this->name !== '' ? $this->name : 'م', 0, 1) }}</span>
            <span class="flex-1 min-w-0">
                <span class="block text-base font-semibold truncate">{{ $this->name }}</span>
                <span class="block text-xs text-zinc-500 dark:text-zinc-400 truncate mt-0.5" dir="ltr">{{ $this->email }}</span>
            </span>
        </div>

        <div class="my-5 h-px bg-black/10 dark:bg-white/10" role="separator"></div>

        <div class="grid grid-cols-2 gap-1 rounded-xl bg-black/5 dark:bg-white/10 p-1" role="tablist" aria-label="أقسام الملف الشخصي">
            <button type="button" role="tab" :aria-selected="tab === 'profile' ? 'true' : 'false'" @click="tab = 'profile'"
                :class="tab === 'profile' ? 'bg-white dark:bg-zinc-900 text-black dark:text-white shadow' : 'text-zinc-600 dark:text-zinc-300 hover:bg-white/60 dark:hover:bg-white/10'"
                class="rounded-lg px-2 py-2 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current">الملف الشخصي</button>
            <button type="button" role="tab" :aria-selected="tab === 'password' ? 'true' : 'false'" @click="tab = 'password'"
                :class="tab === 'password' ? 'bg-white dark:bg-zinc-900 text-black dark:text-white shadow' : 'text-zinc-600 dark:text-zinc-300 hover:bg-white/60 dark:hover:bg-white/10'"
                class="rounded-lg px-2 py-2 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current">كلمة السر</button>
        </div>

        <form wire:submit="saveProfile" x-show="tab === 'profile'" class="space-y-4 mt-5" role="tabpanel" aria-label="الملف الشخصي">
            <div>
                <x-ui.label for="profile-name">الاسم</x-ui.label>
                <x-ui.input id="profile-name" type="text" wire:model="name" :error="$errors->has('name')" autocomplete="name" maxlength="255" />
                <x-ui.input-error for="name" />
            </div>
            <div>
                <x-ui.label for="profile-email">البريد الإلكتروني</x-ui.label>
                <x-ui.input id="profile-email" type="email" dir="ltr" wire:model="email" :error="$errors->has('email')" autocomplete="email" maxlength="255" class="text-left" />
                <x-ui.input-error for="email" />
            </div>
            <div class="flex justify-end pt-1">
                <button type="submit" wire:loading.attr="disabled" wire:target="saveProfile"
                    class="rounded-full ui-primary-btn px-6 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed min-h-[40px] inline-flex items-center gap-1.5">
                    <span wire:loading wire:target="saveProfile">
                        <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                    </span>
                    <span wire:loading.remove wire:target="saveProfile">حفظ التغييرات</span>
                    <span wire:loading wire:target="saveProfile">جارٍ الحفظ...</span>
                </button>
            </div>
        </form>

        <form wire:submit="savePassword" x-show="tab === 'password'" x-cloak class="space-y-4 mt-5" role="tabpanel" aria-label="كلمة السر">
            <div>
                <x-ui.label for="password-current">كلمة السر الحالية</x-ui.label>
                <x-ui.password-input id="password-current" wire:model="currentPassword" :error="$errors->has('currentPassword')" autocomplete="current-password" />
                <x-ui.input-error for="currentPassword" />
            </div>
            <div>
                <x-ui.label for="password-new">كلمة السر الجديدة</x-ui.label>
                <x-ui.password-input id="password-new" wire:model="newPassword" :error="$errors->has('newPassword')" autocomplete="new-password" />
                <x-ui.input-error for="newPassword" />
            </div>
            <div>
                <x-ui.label for="password-confirm">تأكيد كلمة السر الجديدة</x-ui.label>
                <x-ui.password-input id="password-confirm" wire:model="newPassword_confirmation" autocomplete="new-password" />
            </div>
            <div class="flex justify-end pt-1">
                <button type="submit" wire:loading.attr="disabled" wire:target="savePassword"
                    class="rounded-full ui-primary-btn px-6 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed min-h-[40px] inline-flex items-center gap-1.5">
                    <span wire:loading wire:target="savePassword">
                        <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                    </span>
                    <span wire:loading.remove wire:target="savePassword">تغيير كلمة السر</span>
                    <span wire:loading wire:target="savePassword">جارٍ الحفظ...</span>
                </button>
            </div>
        </form>
    </div>
</div>
