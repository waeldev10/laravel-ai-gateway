@extends('layouts.app')

@section('title', 'تسجيل الدخول')

@section('content')
    <div class="mx-auto w-full max-w-md">
        <div class="mb-6 text-center">
            <h1 class="text-2xl font-semibold">تسجيل الدخول</h1>
            <p class="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                مرحباً بعودتك إلى {{ config('app.name') }}.
            </p>
        </div>

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium">البريد الإلكتروني</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    autocomplete="email"
                    class="mt-1 w-full rounded-sm border border-[#19140035] bg-white px-3 py-2 text-sm focus:border-black focus:outline-none dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                >
                @error('email')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium">كلمة المرور</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    class="mt-1 w-full rounded-sm border border-[#19140035] bg-white px-3 py-2 text-sm focus:border-black focus:outline-none dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                >
                @error('password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center gap-2">
                <input id="remember" type="checkbox" name="remember" class="rounded-sm border-[#19140035]">
                <label for="remember" class="text-sm">تذكرني</label>
            </div>

            <button
                type="submit"
                class="w-full rounded-sm border border-black bg-[#1b1b18] px-5 py-2 text-sm text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
            >
                تسجيل الدخول
            </button>
        </form>

        <p class="mt-6 text-center text-sm">
            ليس لديك حساب؟
            <a wire:navigate href="{{ route('register') }}" class="font-medium underline underline-offset-4">إنشاء حساب</a>
        </p>
    </div>
@endsection