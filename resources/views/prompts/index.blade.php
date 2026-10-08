@extends('layouts.app')

@section('title', 'إعدادات البرومبت')

@section('content')
    <div class="max-w-xl mx-auto px-4 py-8 w-full">
        <h1 class="text-xl font-semibold mb-4">إعدادات البرومبت</h1>

        @livewire('prompts.prompt-settings')
    </div>
@endsection
