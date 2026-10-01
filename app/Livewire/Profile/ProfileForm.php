<?php

namespace App\Livewire\Profile;

use App\Services\Auth\PasswordService;
use App\Services\Profile\ProfileService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class ProfileForm extends Component
{
    public string $name = '';

    public string $email = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public function mount(): void
    {
        $user = Auth::user();

        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
    }

    public function saveProfile(ProfileService $service): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            // Ignore the user's own email so an unchanged email passes.
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore(Auth::id())],
        ]);

        try {
            $user = $service->update(Auth::user(), $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            $this->dispatch('toast', type: 'error', title: 'تعذر الحفظ', message: 'حدث خطأ غير متوقع. حاول مرة أخرى.');

            return;
        }

        // Reflect the actual persisted data, then notify (toast + user menu).
        $this->name = (string) $user->name;
        $this->email = (string) $user->email;

        $this->dispatch('profile-updated', name: $user->name, initial: (string) mb_substr($user->name ?? '', 0, 1), email: $user->email);
        $this->dispatch('toast', type: 'success', title: 'الملف الشخصي', message: 'تم تحديث الملف الشخصي بنجاح.');
    }

    public function savePassword(PasswordService $service): void
    {
        // Same password rules as registration: single authoritative rule set.
        $validated = $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        try {
            $service->change(Auth::user(), $validated['currentPassword'], $validated['newPassword']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            $this->dispatch('toast', type: 'error', title: 'تعذر الحفظ', message: 'حدث خطأ غير متوقع. حاول مرة أخرى.');

            return;
        }

        $this->reset(['currentPassword', 'newPassword', 'newPassword_confirmation']);

        $this->dispatch('toast', type: 'success', title: 'كلمة السر', message: 'تم تغيير كلمة السر بنجاح.');
    }

    public function render()
    {
        return view('livewire.profile.profile-form');
    }
}
