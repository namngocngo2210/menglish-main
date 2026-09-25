@props(['active'])

@php
$classes = ($active ?? false)
            ? 'flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm font-semibold bg-primary-container/15 text-white transition-colors'
            : 'flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm font-medium text-white/70 hover:bg-white/5 hover:text-white transition-colors';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
