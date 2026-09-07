@extends('layouts.app')

@section('title', 'محادثة جديدة')

@section('content')
    <div class="mx-auto w-full max-w-md">
        <div class="mb-6 text-center">
            <h1 class="text-2xl font-semibold">محادثة جديدة</h1>
            <p class="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                اختر عنواناً لمحادثتك الجديدة.
            </p>
        </div>

        <form method="POST" action="{{ route('conversations.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="title" class="block text-sm font-medium">عنوان المحادثة</label>
                <input
                    id="title"
                    type="text"
                    name="title"
                    value="{{ old('title') }}"
                    required
                    autofocus
                    class="mt-1 w-full rounded-sm border border-[#19140035] bg-white px-3 py-2 text-sm focus:border-black focus:outline-none dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                >
                @error('title')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    class="rounded-sm border border-black bg-[#1b1b18] px-5 py-2 text-sm text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                >
                    إنشاء المحادثة
                </button>
                <a wire:navigate href="{{ route('conversations.index') }}" class="text-sm hover:underline">
                    إلغاء
                </a>
            </div>
        </form>
    </div>
@endsection