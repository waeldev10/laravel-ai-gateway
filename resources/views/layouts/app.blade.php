<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name', 'Laravel AI Hub'))</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @fonts
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
        @livewireStyles
    </head>
    <body class="bg-[#FDFDFC] text-[#1b1b18] antialiased min-h-screen flex flex-col dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
        <header class="border-b border-[#19140014] dark:border-[#3E3E3A]">
            <div class="mx-auto flex w-full max-w-3xl flex-col items-center gap-4 px-6 py-4 sm:flex-row sm:justify-between">
                <a href="{{ route('home') }}" class="text-base font-bold">
                    {{ config('app.name') }}
                </a>

                <nav class="flex items-center gap-4 text-sm">
                    @auth
                        <a wire:navigate href="{{ route('dashboard') }}" class="hover:underline">لوحة التحكم</a>
                        <a wire:navigate href="{{ route('conversations.index') }}" class="hover:underline">المحادثات</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="hover:underline">تسجيل الخروج</button>
                        </form>
                    @else
                        <a wire:navigate href="{{ route('login') }}" class="hover:underline">تسجيل الدخول</a>
                        @if (Route::has('register'))
                            <a wire:navigate
                                href="{{ route('register') }}"
                                class="rounded-sm border border-[#19140035] px-3 py-1.5 hover:border-black dark:border-[#3E3E3A]"
                            >
                                إنشاء حساب
                            </a>
                        @endif
                    @endauth
                </nav>
            </div>
        </header>

        <main class="mx-auto flex w-full max-w-3xl flex-1 flex-col justify-center px-6 py-10">
            @yield('content')
        </main>

        @livewireScripts
    </body>
</html>