<?php

namespace App\Livewire\Auth;

use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\AuthenticationService;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class RegisterForm extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register(AuthenticationService $authentication): void
    {
        try {
            // Single rule source: the same rules as the HTTP register endpoint.
            // Deferred wire:model means typing costs no request; only submit does.
            $validated = $this->validate((new RegisterRequest)->rules());

            $authentication->ensureRegistrationNotRateLimited((string) request()->ip());

            // Same behavior as the HTTP endpoint: create, authenticate, keep session safe.
            $authentication->register(
                Arr::only($validated, ['name', 'email', 'password']),
                request()->hasSession() ? request() : null
            );
        } catch (ValidationException $e) {
            $this->reset('password', 'password_confirmation');

            if ($authentication->registrationThrottled((string) request()->ip())) {
                $this->dispatch('toast', type: 'error', title: 'تعذر إنشاء الحساب', message: 'لقد حاولت 5 مرات، الرجاء الانتظار.');
            }

            throw $e;
        }

        session()->flash('toast', ['type' => 'success', 'title' => 'تم إنشاء الحساب', 'message' => 'مرحباً بك في بوابة الذكاء الاصطناعي.']);
        $this->redirect(route('conversations.create'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.register-form');
    }
}
