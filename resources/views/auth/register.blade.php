@extends('layouts.app')

@section('title', 'إنشاء حساب')

@section('content')
    <div class="mx-auto w-full max-w-md">
        <div class="mb-6 text-center">
            <h1 class="text-2xl font-semibold">إنشاء حساب</h1>
            <p class="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                ابدأ باستخدام دردشة الويب الذكية.
            </p>
        </div>

        <form method="POST" action="{{ route('register') }}" class="space-y-4">
            @csrf

            <div>
                <label for="name" class="block text-sm font-medium">الاسم</label>
                <input
                    id="name"
                    type="text"
                    name="name"
                    value="{{ old('name') }}"
                    
                    autofocus
                    autocomplete="name"
                    class="mt-1 w-full rounded-sm border border-[#19140035] bg-white px-3 py-2 text-sm focus:border-black focus:outline-none dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                >
                @error('name')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="block text-sm font-medium">البريد الإلكتروني</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    
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
                    
                    autocomplete="new-password"
                    class="mt-1 w-full rounded-sm border border-[#19140035] bg-white px-3 py-2 text-sm focus:border-black focus:outline-none dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                >
                @error('password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium">تأكيد كلمة المرور</label>
                <input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    
                    autocomplete="new-password"
                    class="mt-1 w-full rounded-sm border border-[#19140035] bg-white px-3 py-2 text-sm focus:border-black focus:outline-none 
                    dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]
                    "
                >
            </div>

            <button
                type="submit"
                class="w-full rounded-sm border border-black bg-[#1b1b18] px-5 py-2 text-sm text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
            >
                إنشاء حساب
            </button>
        </form>

        <p class="mt-6 text-center text-sm">
            لديك حساب بالفعل؟
            <a wire:navigate href="{{ route('login') }}" class="font-medium underline underline-offset-4">تسجيل الدخول</a>
        </p>
    </div>
@endsection