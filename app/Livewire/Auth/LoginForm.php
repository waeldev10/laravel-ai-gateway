<?php

namespace App\Livewire\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\AuthenticationService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class LoginForm extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(AuthenticationService $authentication): void
    {
        try {
            // Single rule source: the same rules as the HTTP login endpoint.
            // Deferred wire:model means typing costs no request; only submit does.
            $validated = $this->validate((new LoginRequest)->rules());

            $authentication->ensureLoginNotRateLimited($validated['email'], (string) request()->ip());

            $authentication->login(
                ['email' => $validated['email'], 'password' => $this->password],
                $this->remember,
                request()->hasSession() ? request() : null
            );
        } catch (ValidationException $e) {
            // Keep the email, clear the password, then surface field errors.
            // A throttle rejection additionally fires the unified error Toast
            // (static text only — never credentials or limiter internals).
            $this->reset('password');

            if ($authentication->loginThrottled($this->email, (string) request()->ip())) {
                $this->dispatch('toast', type: 'error', title: 'تعذر تسجيل الدخول', message: 'لقد حاولت 5 مرات، الرجاء الانتظار.');
            }

            throw $e;
        }

        session()->flash('toast', ['type' => 'success', 'title' => 'مرحباً بعودتك', 'message' => 'تم تسجيل الدخول بنجاح.']);
        $this->redirect(route('conversations.create'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login-form');
    }
}
