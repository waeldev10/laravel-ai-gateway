<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly AuthenticationService $authentication) {}

    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $this->authentication->register($request->validated(), $request);

        return redirect(route('conversations.create', absolute: false))
            ->with('toast', ['type' => 'success', 'title' => 'تم إنشاء الحساب', 'message' => 'مرحباً بك في بوابة الذكاء الاصطناعي.']);
    }
}
