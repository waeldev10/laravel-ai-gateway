<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    @include('components.theme.theme-init')
    @include('components.theme.ui-colors')
    @if(file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
    @vite(['resources/css/app.css', 'resources/js/app.js']) @endif
    @livewireStyles
    <style>
        [x-cloak] {
            display: none !important
        }
    </style>
</head>

<body class="bg-[#f7f7f8] dark:bg-[#0f0f0f] text-[#0f0f0f] dark:text-[#ececec] antialiased min-h-screen">
    <x-ui.toast />
    <main class="min-h-screen w-full flex items-center justify-center px-4 py-10">
        <div class="w-full max-w-md">
            <div class="flex items-center justify-center gap-2.5 mb-6">
                <span class="w-10 h-10 rounded-2xl ui-primary-surface grid place-items-center text-lg">◐</span>
                <span class="font-semibold tracking-tight text-lg">{{ config('app.name') }}</span>
            </div>
            <div class="rounded-2xl border border-black/10 dark:border-white/10 bg-white dark:bg-[#141414] p-6 sm:p-8 shadow-sm">
                @yield('content')
            </div>
            <p class="mt-6 text-center text-xs text-zinc-500 dark:text-zinc-400">مساحة دردشة نظيفة — نفس هوية التطبيق في الوضع الفاتح والداكن.</p>
            <div class="mt-4 flex justify-center">
                <x-theme.theme-toggle />
      
            </div>
        </div>
          
    </main>
  
    @livewireScripts

</body>

</html>
