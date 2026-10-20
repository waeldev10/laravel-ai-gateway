<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\ApiAbilities;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Application-level authentication operations for the token API.
 *
 * Owns everything the `/api/v1/auth/*` endpoints need beyond input
 * validation: Web-identical persistence (`User::create` + `Registered`
 * event, as in `AuthenticationService::register`), stateless
 * credential verification through the same user provider the Web
 * login uses, Sanctum issuance, and current-token revocation.
 *
 * Sessions are never touched: the bearer replaces the session
 * cookie, and the Web session flow stays exactly as it is.
 */
class ApiAuthService
{
    /**
     * Register a user exactly like the Web flow, minus the session
     * login: the caller issues a bearer for the returned user.
     *
     * @param  array<string, mixed>  $data  Validated registration input.
     */
    public function register(array $data): User
    {
        $user = User::create(Arr::only($data, ['name', 'email', 'password']));

        event(new Registered($user));

        return $user;
    }

    /**
     * Verify credentials without logging in and without a session.
     * Failure mirrors the Web shape exactly (`auth.failed` on the
     * `email` key, never revealing whether the address exists).
     *
     * @throws ValidationException
     */
    public function authenticate(string $email, string $password): User
    {
        if (! Auth::validate(['email' => $email, 'password' => $password])) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return User::where('email', $email)->firstOrFail();
    }

    /**
     * Issue the API bearer for an authenticated user: full ability
     * set, returned once — Sanctum stores only the hash.
     */
    public function issueToken(User $user): NewAccessToken
    {
        return $user->createToken('api', ApiAbilities::all());
    }

    /**
     * Revoke only the user's current bearer. Other tokens — and any
     * Web session — are left untouched.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
