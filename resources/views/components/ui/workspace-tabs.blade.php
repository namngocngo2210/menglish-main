{{--
    <x-ui.workspace-tabs> — thanh tab của workspace (tab là link, không phải JS tab) + nút hành động của workspace.
    Tab + nút khai báo tập trung ở App\Support\Navigation\SidebarMenu::definition() (cùng nguồn với sidebar), ẩn tab
    user không có quyền; tab đang mở giữ query (bộ lọc) khi vẫn ở đúng route đó.
    Layout app tự chèn ở đầu trang nếu trang chưa tự đặt; trang muốn đặt ở vị trí khác (vd. header CRM) thì gọi:
      <x-ui.workspace-tabs workspace="crm" class="!mb-0 !border-b-0" />
    Item `as` => 'chip' (kèm `chip_of` = route tab cha) hiển thị thành hàng lọc nhanh dưới tab khi tab cha đang mở;
    item `as` => 'menu' và action có `menu` được gom vào 1 nút thả xuống theo tên `menu`.
    Props: workspace (id, mặc định = workspace chứa route hiện tại), counts (số hiển thị trên chip, theo khóa `count`)
--}}
@props(['workspace' => null, 'counts' => []])

@php
    // Đánh dấu để layout không chèn thêm lần nữa.
    request()->attributes->set('workspace_tabs_rendered', true);
    $ws = app(\App\Support\Navigation\SidebarMenu::class)->workspaceFor(auth()->user(), request(), $workspace);

    if ($ws) {
        $items = collect($ws['items']);
        $chips = $items->where('as', 'chip')->values();
        // Tab cha đang mở khi chính nó hoặc 1 chip của nó đang mở.
        $tabs = $items->whereNull('as')->map(fn (array $tab) => [
            ...$tab,
            'active' => $tab['active'] || $chips->contains(fn (array $chip) => $chip['chip_of'] === $tab['route'] && $chip['active']),
        ])->values();
        $activeTab = $tabs->firstWhere('active', true);
        $tabChips = $activeTab ? $chips->where('chip_of', $activeTab['route'])->values() : collect();
        // Chip "Tất cả" = tab cha, không kèm bộ lọc của chip nào.
        $allActive = $activeTab && ! $tabChips->contains('active', true);

        $menus = $items->where('as', 'menu')->concat(collect($ws['actions'])->whereNotNull('menu'))->groupBy('menu');
        $buttons = collect($ws['actions'])->whereNull('menu')->values();
    }
@endphp

@if ($ws && ($tabs->count() > 1 || $buttons->isNotEmpty() || $menus->isNotEmpty()))
    <div {{ $attributes->merge(['class' => 'mb-lg border-b border-surface-container-highest']) }} data-workspace-tabs="{{ $ws['id'] }}">
        <div class="flex flex-col gap-sm md:flex-row md:items-end md:justify-between">
            @if ($tabs->count() > 1)
                <x-ui.tabs class="min-w-0 -mb-px border-b-0" aria-label="{{ $ws['label'] }}">
                    @foreach ($tabs as $tab)
                        <x-ui.tab :href="$tab['url']" :active="$tab['active']">{{ $tab['label'] }}</x-ui.tab>
                    @endforeach
                </x-ui.tabs>
            @else
                <span></span>
            @endif

            @if ($buttons->isNotEmpty() || $menus->isNotEmpty())
                <div class="flex shrink-0 flex-wrap items-center gap-sm pb-sm">
                    @foreach ($menus as $menuLabel => $menuItems)
                        <x-dropdown align="right" width="64">
                            <x-slot name="trigger">
                                <x-ui.button size="sm" variant="secondary" type="button" aria-haspopup="menu">
                                    {{ $menuLabel }}
                                    <span class="material-symbols-outlined -mr-1 text-[18px]" aria-hidden="true">expand_more</span>
                                </x-ui.button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="py-xs" role="menu" data-workspace-menu="{{ $menuLabel }}">
                                    @foreach ($menuItems as $entry)
                                        <a href="{{ $entry['url'] }}" role="menuitem"
                                           class="flex items-center gap-sm px-md py-sm font-body-medium text-body-medium transition-colors hover:bg-surface-container-low hover:text-primary {{ ! empty($entry['active']) ? 'text-primary' : 'text-on-surface' }}">
                                            <span class="material-symbols-outlined text-[20px] text-on-surface-variant" aria-hidden="true">{{ $entry['icon'] ?? 'chevron_right' }}</span>
                                            {{ $entry['label'] }}
                                        </a>
                                    @endforeach
                                </div>
                            </x-slot>
                        </x-dropdown>
                    @endforeach
                    @foreach ($buttons as $action)
                        <x-ui.button size="sm" :variant="$action['variant'] ?? 'primary'" :icon="$action['icon'] ?? null" :href="$action['url']" :modal="$action['modal'] ?? null">{{ $action['label'] }}</x-ui.button>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($tabChips->isNotEmpty())
            {{-- Lọc nhanh của tab đang mở --}}
            <nav class="flex flex-wrap items-center gap-xs py-sm" aria-label="Lọc nhanh" data-workspace-chips>
                @php
                    $chipClass = fn (bool $active, ?string $tone) => 'inline-flex items-center gap-xs rounded-full border px-sm py-1 font-body-small text-body-small font-semibold transition-colors '.match (true) {
                        $active && $tone === 'danger' => 'border-error bg-error text-white',
                        $active => 'border-primary-container bg-primary-container text-white',
                        $tone === 'danger' => 'border-error/30 bg-error/5 text-error hover:bg-error/10',
                        default => 'border-outline-variant bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface',
                    };
                @endphp
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
    </div>
@endif
