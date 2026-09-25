@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1 bg-white'])

@php
$alignmentClasses = match ($align) {
    'left' => 'ltr:origin-top-left rtl:origin-top-right start-0',
    'top' => 'origin-top',
    default => 'ltr:origin-top-right rtl:origin-top-left end-0',
};

$widthClass = match ($width) {
    '48' => 'w-48',
    '56' => 'w-56',
    '64' => 'w-64',
    '72' => 'w-72',
    '80' => 'w-80',
    '96' => 'w-80 sm:w-96',
    'notification' => 'w-[320px] sm:w-[380px] md:w-[420px] max-w-[92vw]',
    default => str_starts_with($width, 'w-') ? $width : "w-{$width}",
};
@endphp

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @close.stop="open = false">
    <div @click="open = ! open">
        {{ $trigger }}
    </div>

    <div x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="absolute z-50 mt-2 {{ $widthClass }} rounded-xl shadow-level-3 {{ $alignmentClasses }}"
            style="display: none;"
            @click="open = false">
        <div class="overflow-hidden rounded-xl border border-surface-container-highest bg-surface-container-lowest {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
