{{--
    <x-ui.workspace-chips> — hàng lọc nhanh của tab workspace đang mở (item `as` => 'chip' trong SidebarMenu::definition()),
    đặt BÊN TRONG khung bộ lọc của trang (vd. slot `quick` của <x-ui.filter-bar>). Không có chip cho tab hiện tại → không render.
    Props: workspace (id, mặc định = workspace chứa route hiện tại),
           counts (số trên chip theo khóa `count`; mặc định lấy request attribute `workspace_chip_counts`, vd. do header CRM đặt)
--}}
@props(['workspace' => null, 'counts' => null])

@php
    $counts ??= request()->attributes->get('workspace_chip_counts', []);
    $ws = app(\App\Support\Navigation\SidebarMenu::class)->workspaceFor(auth()->user(), request(), $workspace);
    $tabChips = collect();
    if ($ws) {
        $items = collect($ws['items']);
        $chips = $items->where('as', 'chip')->values();
        $activeTab = $items->whereNull('as')->first(fn (array $tab) => $tab['active']
            || $chips->contains(fn (array $chip) => $chip['chip_of'] === $tab['route'] && $chip['active']));
        $tabChips = $activeTab ? $chips->where('chip_of', $activeTab['route'])->values() : collect();
        // Chip "Tất cả" = tab cha, không kèm bộ lọc của chip nào.
        $allActive = $activeTab && ! $tabChips->contains('active', true);
    }
    $chipClass = fn (bool $active, ?string $tone) => 'inline-flex items-center gap-xs rounded-full border px-sm py-1 font-body-small text-body-small font-semibold transition-colors '.match (true) {
        $active && $tone === 'danger' => 'border-error bg-error text-white',
        $active => 'border-primary-container bg-primary-container text-white',
        $tone === 'danger' => 'border-error/30 bg-error/5 text-error hover:bg-error/10',
        default => 'border-outline-variant bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface',
    };
@endphp

@if ($tabChips->isNotEmpty())
    <nav {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-xs']) }} aria-label="Lọc nhanh" data-workspace-chips>
        <a href="{{ route($activeTab['route']) }}" class="{{ $chipClass($allActive, null) }}" @if ($allActive) aria-current="page" @endif>Tất cả</a>
        @foreach ($tabChips as $chip)
            @php $count = isset($chip['count']) ? ($counts[$chip['count']] ?? null) : null; @endphp
            <a href="{{ $chip['url'] }}" class="{{ $chipClass($chip['active'], $chip['tone'] ?? null) }}" @if ($chip['active']) aria-current="page" @endif>
                @if (($chip['tone'] ?? null) === 'danger')<span class="material-symbols-outlined text-[16px]" aria-hidden="true">warning</span>@endif
                {{ $chip['label'] }}
                @if ($count !== null)
                    <span class="rounded-full px-1.5 font-code text-[11px] leading-4 {{ $chip['active'] ? 'bg-white/25' : 'bg-surface-container-high' }}">{{ number_format($count, 0, ',', '.') }}</span>
                @endif
            </a>
        @endforeach
    </nav>
@endif
