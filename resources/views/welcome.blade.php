@extends('layouts.app')

@section('title', 'Laravel AI Hub')

@section('content')
    <div class="flex flex-col items-center py-8 text-center">
        <h1 class="text-4xl font-bold tracking-tight">Laravel AI Hub</h1>
        <p class="mt-4 max-w-xl text-lg leading-8 text-[#706f6c] dark:text-[#A1A09A]">
            بوابة ذكاء اصطناعي مبنية بإطار Laravel تربط تطبيقاتك بمزوّدي الذكاء الاصطناعي
            من خلال طبقة مركزية واحدة.
        </p>

        <div class="mt-6 inline-flex items-center gap-2 rounded-full border border-[#19140035] px-3 py-1 text-xs font-medium text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
            <span class="h-2 w-2 rounded-full bg-green-500"></span>
            مشروع Open Source
        </div>

        <div class="mt-10 flex flex-wrap items-center justify-center gap-4">
            @auth
                <a wire:navigate
                    href="{{ route('dashboard') }}"
                    class="rounded-sm border border-black bg-[#1b1b18] px-5 py-2 text-sm text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                >
                    لوحة التحكم
                </a>
            @else
                <a wire:navigate
                    href="{{ route('login') }}"
                    class="rounded-sm border border-black bg-[#1b1b18] px-5 py-2 text-sm text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                >
                    تسجيل الدخول
                </a>
                @if (Route::has('register'))
                    <a wire:navigate
                        href="{{ route('register') }}"
                        class="rounded-sm border border-[#19140035] px-5 py-2 text-sm hover:border-black dark:border-[#3E3E3A]"
                    >
                        إنشاء حساب
                    </a>
                @endif
            @endauth
        </div>
    </div>
@endsection