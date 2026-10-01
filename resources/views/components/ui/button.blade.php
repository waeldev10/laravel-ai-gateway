@props(['variant' => 'primary'])
@php $classes = $variant === 'primary' ? 'rounded-full ui-primary-btn px-5 py-2.5 text-sm font-medium transition disabled:opacity-40 disabled:cursor-not-allowed' : 'rounded-full border border-black/10 dark:border-white/10 px-4 py-2 text-sm hover:bg-black/5 dark:hover:bg-white/10 transition'; @endphp
<button {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
