<?php

namespace App\Services\Profile;

use App\Models\User;

class ProfileService
{
    /**
     * Update the user's own profile information.
     *
     * Only name/email are assignable here; anything else in $attributes is
     * ignored so the browser can never escalate through this operation.
     * A changed email resets verification, mirroring standard framework
     * behavior (the application has no separate verification flow).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        $user->fill([
            'name' => $attributes['name'] ?? $user->name,
            'email' => $attributes['email'] ?? $user->email,
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $user->refresh();
    }
}
