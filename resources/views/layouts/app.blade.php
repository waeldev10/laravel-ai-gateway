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

<body
    class="bg-[#f7f7f8] dark:bg-[#0f0f0f] text-[#0f0f0f] dark:text-[#ececec] antialiased h-screen flex overflow-hidden"
    x-data="{sidebarOpen: (()=>{try{const s=localStorage.getItem('sidebarOpen');if(s!==null)return JSON.parse(s);}catch(e){}return true;})(), drawer:false, userMenu:false, theme: (window.__currentTheme ? window.__currentTheme() : (()=>{try{return localStorage.getItem('theme')||'system';}catch(e){return 'system';}})()), setTheme(t){this.theme=t;if(window.__setTheme){window.__setTheme(t);}else{try{localStorage.setItem('theme',t);}catch(e){}document.documentElement.classList.toggle('dark',t==='dark'||(t==='system'&&matchMedia('(prefers-color-scheme: dark)').matches))}}}"
    x-init="try{$watch('sidebarOpen',(v)=>{try{localStorage.setItem('sidebarOpen',JSON.stringify(v));}catch(e){}});}catch(e){}; try{$watch('drawer',(v)=>{if(!v){userMenu=false}});}catch(e){}"
    @keydown.escape.window="drawer=false; userMenu=false">
    @include('components.sidebar.sidebar')
    <x-ui.toast />

    <div class="flex-1 flex flex-col min-w-0 min-h-0 bg-white dark:bg-[#141414] overflow-hidden transition-[filter] duration-300"
        :class="drawer ? 'blur-sm pointer-events-none select-none lg:blur-none lg:pointer-events-auto lg:select-auto' : ''">
        <header class="h-14 flex items-center gap-3 px-4 border-b border-black/5 dark:border-white/10 shrink-0">
            <button @click="drawer=!drawer" :aria-expanded="drawer ? 'true' : 'false'" aria-controls="chat-sidebar"
                class="lg:hidden w-9 h-9 grid place-items-center rounded-full hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current"
                aria-label="القائمة"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg></button>
            <button @click="sidebarOpen=!sidebarOpen" :aria-expanded="sidebarOpen ? 'true' : 'false'" aria-controls="chat-sidebar"
                class="hidden lg:grid w-9 h-9 place-items-center rounded-full hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current"
                aria-label="تبديل الشريط"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg></button>
            <h1 class="font-medium truncate text-sm">@yield('title')</h1>
        </header>
        <main class="flex-1 min-h-0 overflow-y-auto flex flex-col">
            @yield('content')
        </main>
    </div>
    @auth
        @livewire('ui-color-settings')
    @endauth
    @livewireScripts
</body>

</html>