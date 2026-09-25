{{-- <x-ui.tab> — dùng bên trong <x-ui.tabs>; xem tài liệu ở components/ui/tabs.blade.php --}}
@props(['href' => '#', 'active' => false, 'icon' => null, 'count' => null])

<a href="{{ $href }}"
   @if ($active) aria-current="page" @endif
   {{ $attributes->merge(['class' => '-mb-px inline-flex shrink-0 items-center gap-xs whitespace-nowrap border-b-2 px-sm py-md font-body-medium text-body-medium transition-colors ' . ($active ? 'border-primary-container font-semibold text-primary' : 'border-transparent text-on-surface-variant hover:text-primary')]) }}>
    @if ($icon)<span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ $icon }}</span>@endif
    {{ $slot }}
    @if (! is_null($count))
        <span class="rounded-full px-1.5 font-code text-caption {{ $active ? 'bg-primary-container/10 text-primary' : 'bg-surface-container-high text-on-surface-variant' }}">{{ $count }}</span>
    @endif
</a>
