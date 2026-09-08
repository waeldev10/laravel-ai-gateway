@extends('layouts.app')

@section('title', 'لوحة التحكم')

@section('content')
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">مرحباً، {{ auth()->user()->name }}</h1>
            <p class="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                هذه منطقتك المحمية في دردشة الويب الذكية.
            </p>
        </div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button
                type="submit"
                class="rounded-sm border border-[#19140035] px-4 py-1.5 text-sm hover:border-black dark:border-[#3E3E3A]"
            >
                تسجيل الخروج
            </button>
        </form>
    </div>

    <div class="mt-8 rounded-sm border border-dashed border-[#19140035] p-6 text-center text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
        لم تبدأ محادثاتك بعد.
        <a wire:navigate href="{{ route('conversations.index') }}" class="font-medium underline underline-offset-4">
            تصفح المحادثات
        </a>
    </div>
@endsection