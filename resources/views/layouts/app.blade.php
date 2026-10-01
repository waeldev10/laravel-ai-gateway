<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name'))</title>

        <meta name="description" content="@yield('meta_description', 'وصف الموقع الافتراضي هنا')" > 
        <meta name="robots" content="@yield('meta_robots', 'index, follow')" > 
        <link rel="canonical" href="@yield('canonical', url()->current())"> {{-- Language --}} 
        <meta http-equiv="content-language" content="{{ app()->getLocale() }}"> {{-- Open Graph / Facebook / WhatsApp --}} 
        <meta property="og:type" content="@yield('og_type', 'website')"> 
        <meta property="og:title" content="@yield('og_title', trim($__env->yieldContent('title', config('app.name'))))"> 
        <meta property="og:description" content="@yield('og_description', trim($__env->yieldContent('meta_description', 'وصف الموقع الافتراضي هنا')))"> 
        <meta property="og:url" content="@yield('canonical', url()->current())"> 
        <meta property="og:site_name" content="{{ config('app.name') }}"> 
        <meta property="og:locale" content="{{ app()->getLocale() === 'ar' ? 'ar_AR' : 'en_US' }}"> @hasSection('og_image') 
        <meta property="og:image" content="@yield('og_image')"> @endif {{-- Twitter / X --}} 
        <meta name="twitter:card" content="@yield('twitter_card', 'summary_large_image')"> 
        <meta name="twitter:title" content="@yield('twitter_title', trim($__env->yieldContent('title', config('app.name'))))"> 
        <meta name="twitter:description" content="@yield('twitter_description', trim($__env->yieldContent('meta_description', 'وصف الموقع الافتراضي هنا')))"> @hasSection('twitter_image') 
        <meta name="twitter:image" content="@yield('twitter_image')"> @elseif(View::hasSection('og_image')) 
        <meta name="twitter:image" content="@yield('og_image')"> @endif {{-- Favicon --}} 
        <link rel="icon" href="{{ asset('favicon.ico') }}">

        @include('components.theme.theme-init')
        @include('components.theme.ui-colors')


        @vite(['resources/css/app.css', 'resources/js/app.js']) 
    

        @livewireStyles
        <style>
            [x-cloak] {
                display: none !important
            }
        </style>
        @stack('css')

    </head>

    <body
        class="bg-foreground dark:bg-background text-foreground dark:text-foregroundantialiased h-screen flex overflow-hidden"
        x-data="{
                    sidebarOpen: (() => {
                        try {
                            const value = localStorage.getItem('sidebarOpen');
                            return value !== null ? JSON.parse(value) === true : true;
                        } catch {
                            return true;
                        }
                    })(),

                    drawer: false,
                    userMenu: false,

                    theme: window.__currentTheme?.() ?? 'system',

                    setTheme(value) {
                        this.theme = window.__setTheme?.(value) ?? this.theme;
                    }
                }"
        x-init="
                $watch('sidebarOpen', value => {
                        try {
                            localStorage.setItem('sidebarOpen', JSON.stringify(value));
                        } catch {}
                    });

                    $watch('drawer', value => {
                        if (!value) userMenu = false;
                    });
                "
        @keydown.escape.window="drawer=false; userMenu=false">
        
        @include('components.sidebar.sidebar')

        <x-ui.toast />

        <div class="flex-1 flex flex-col min-w-0 min-h-0 bg-white dark:bg-[#141414] overflow-hidden transition-[filter] duration-300"
            :class="drawer ? 'blur-sm pointer-events-none select-none lg:blur-none lg:pointer-events-auto lg:select-auto' : ''">
            <header class="h-14 flex items-center gap-3 px-4 border-b border-black/5 dark:border-white/10 shrink-0">
                <button @click="drawer=!drawer" :aria-expanded="drawer ? 'true' : 'false'" aria-controls="chat-sidebar"
                    class="lg:hidden w-9 h-9 grid place-items-center rounded-full hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current"
                    aria-label="القائمة"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M4 7h16M4 12h16M4 17h16" />
                    </svg></button>
                <button @click="sidebarOpen=!sidebarOpen" :aria-expanded="sidebarOpen ? 'true' : 'false'"
                    aria-controls="chat-sidebar"
                    class="hidden lg:grid w-9 h-9 place-items-center rounded-full hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current"
                    aria-label="تبديل الشريط"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M4 7h16M4 12h16M4 17h16" />
                    </svg></button>
                <h1 class="font-medium truncate text-sm" x-data @conversation-renamed.window="$el.textContent = $event.detail.title; document.title = $event.detail.title">@yield('title')</h1>
                <div class="ms-auto flex items-center gap-1 shrink-0">@yield('header-actions')</div>
            </header>
            <main class="flex-1 min-h-0 overflow-y-auto flex flex-col">
                @yield('content')
            </main>
        </div>

        @auth
            <x-settings.ui-color-settings />
        @endauth
        
    
        @livewireScripts
        @stack('scripts')
        
    </body>

</html>