<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordService
{
    /**
     * Change the user's own password.
     *
     * The current password is verified with the standard hash check — never
     * compared as plaintext — and the new password is stored hashed with the
     * framework hasher. Input shape (required/confirmed/rules) is validated
     * by the caller; this service owns verification and persistence.
     *
     * Sessions are intentionally left untouched: the application has no
     * other-device invalidation mechanism, and the current user stays logged
     * in, matching the existing authentication behavior.
     *
     * @throws ValidationException
     */
    public function change(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => 'كلمة السر الحالية غير صحيحة.',
            ]);
        }

        $user->forceFill(['password' => Hash::make($newPassword)])->save();
    }
}
