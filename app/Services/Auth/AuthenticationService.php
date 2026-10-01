<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthenticationService
{
    /**
     * Mirror the `throttle:login` route limiter (5 attempts/minute keyed by
     * email + IP) for entry points that cannot use route middleware, such as
     * Livewire actions.
     *
     * @throws ValidationException
     */
    public function ensureLoginNotRateLimited(string $email, string $ip): void
    {
        $key = $this->loginThrottleKey($email, $ip);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * Whether the login throttle currently blocks this email + IP.
     * Pure check (no hit): lets callers surface a friendly notice while the
     * authoritative rejection stays in `ensureLoginNotRateLimited`.
     */
    public function loginThrottled(string $email, string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->loginThrottleKey($email, $ip), 5);
    }

    /**
     * Mirror the `throttle:register` route limiter (5 attempts/minute per IP)
     * for entry points that cannot use route middleware.
     *
     * @throws ValidationException
     */
    public function ensureRegistrationNotRateLimited(string $ip): void
    {
        $key = $this->registerThrottleKey($ip);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * Whether the registration throttle currently blocks this IP.
     * Pure check (no hit); see `loginThrottled`.
     */
    public function registrationThrottled(string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->registerThrottleKey($ip), 5);
    }

    /**
     * Attempt to authenticate the given credentials.
     *
     * The request is only needed for session regeneration; when omitted (e.g.
     * Livewire actions), the session manager is used directly.
     *
     * @throws ValidationException
     */
    public function login(array $credentials, bool $remember, ?Request $request = null): void
    {
        if (! Auth::attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $this->session($request)->regenerate();
    }

    public function register(array $data, ?Request $request = null): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        event(new Registered($user));

        Auth::login($user);

        $this->session($request)->regenerate();

        return $user;
    }

    public function logout(Request $request): void
    {
        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();
    }

    /**
     * Resolve a session store, preferring the given request when it carries
     * one (plain HTTP) and falling back to the session manager (Livewire).
     *
     * @return Session
     */
    protected function session(?Request $request = null)
    {
        if ($request !== null && $request->hasSession()) {
            return $request->session();
        }

        return session();
    }

    private function loginThrottleKey(string $email, string $ip): string
    {
        return 'login|'.mb_strtolower(trim($email)).'|'.$ip;
    }

    private function registerThrottleKey(string $ip): string
    {
        return 'register|'.$ip;
    }
}
