{{--
    <x-ui.settings-nav> — menu con trang Cài đặt (nguồn: SidebarMenu::settingsDefinition()).
    Layout app tự bọc trang cấu hình / danh mục bằng menu này (cột trái ≥ lg, thu gọn thành nút mở danh sách < lg).
    Props: sections (kết quả SidebarMenu::settingsFor())
--}}
@props(['sections' => []])

@php
    $current = collect($sections)->flatMap(fn ($s) => $s['items'])->firstWhere('active', true);
@endphp

<nav {{ $attributes->merge(['class' => 'shrink-0 lg:w-56']) }} aria-label="Cài đặt" x-data="{ open: false }" data-settings-nav>
    <button type="button" class="flex w-full items-center justify-between gap-sm rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-medium text-body-medium text-on-surface lg:hidden"
            @click="open = !open" :aria-expanded="open">
        <span class="flex min-w-0 items-center gap-sm">
            <span class="material-symbols-outlined text-[20px] text-on-surface-variant" aria-hidden="true">settings</span>
            <span class="truncate">Cài đặt{{ $current ? ' · '.$current['label'] : '' }}</span>
        </span>
        <span class="material-symbols-outlined text-[20px] transition-transform" :class="{ 'rotate-180': open }" aria-hidden="true">expand_more</span>
    </button>

    <div class="mt-sm hidden space-y-md rounded-xl border border-surface-container-highest bg-surface p-sm lg:sticky lg:top-20 lg:mt-0 lg:block" :class="{ '!block': open }">
        <div class="hidden px-sm pt-xs font-h3 text-h3 text-on-surface lg:block">Cài đặt</div>
        @foreach ($sections as $section)
            <div>
                <div class="px-sm pb-xs font-caption text-[10px] font-semibold uppercase tracking-widest text-on-surface-variant">{{ $section['label'] }}</div>
                <ul class="space-y-0.5">
                    @foreach ($section['items'] as $item)
                        <li>
                            <a href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif
                               class="block truncate rounded-lg px-sm py-1.5 font-body-small text-body-small transition-colors {{ $item['active'] ? 'bg-primary-container/10 font-semibold text-primary' : 'text-on-surface-variant hover:bg-surface-container-low hover:text-primary' }}">{{ $item['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</nav>
