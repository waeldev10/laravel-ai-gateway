@props(['for' => null])
@error($for)<p {{ $attributes->merge(['class' => 'mt-1.5 text-xs text-red-600 dark:text-red-400']) }}>{{ $message }}</p>@enderror
