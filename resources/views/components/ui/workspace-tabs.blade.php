{{--
    <x-ui.workspace-tabs> — thanh tab của workspace (tab là link, không phải JS tab) + nút hành động của workspace.
    Tab + nút khai báo tập trung ở App\Support\Navigation\SidebarMenu::definition() (cùng nguồn với sidebar), ẩn tab
    user không có quyền; tab đang mở giữ query (bộ lọc) khi vẫn ở đúng route đó.
    Layout app tự chèn ở đầu trang nếu trang chưa tự đặt; trang muốn đặt ở vị trí khác (vd. header CRM) thì gọi:
      <x-ui.workspace-tabs workspace="crm" class="!mb-0 !border-b-0" />
    Props: workspace (id, mặc định = workspace chứa route hiện tại)
--}}
@props(['workspace' => null])

@php
    // Đánh dấu để layout không chèn thêm lần nữa.
    request()->attributes->set('workspace_tabs_rendered', true);
    $ws = app(\App\Support\Navigation\SidebarMenu::class)->workspaceFor(auth()->user(), request(), $workspace);
@endphp

@if ($ws && (count($ws['items']) > 1 || $ws['actions'] !== []))
    <div {{ $attributes->merge(['class' => 'mb-lg flex flex-col gap-sm border-b border-surface-container-highest md:flex-row md:items-end md:justify-between']) }} data-workspace-tabs="{{ $ws['id'] }}">
        @if (count($ws['items']) > 1)
            <x-ui.tabs class="min-w-0 -mb-px border-b-0" aria-label="{{ $ws['label'] }}">
                @foreach ($ws['items'] as $tab)
                    <x-ui.tab :href="$tab['url']" :active="$tab['active']">{{ $tab['label'] }}</x-ui.tab>
                @endforeach
            </x-ui.tabs>
        @else
            <span></span>
        @endif

        @if ($ws['actions'] !== [])
            <div class="flex shrink-0 flex-wrap items-center gap-sm pb-sm">
                @foreach ($ws['actions'] as $action)
                    <x-ui.button size="sm" :variant="$action['variant'] ?? 'primary'" :icon="$action['icon'] ?? null" :href="$action['url']" :modal="$action['modal'] ?? null">{{ $action['label'] }}</x-ui.button>
                @endforeach
            </div>
        @endif
    </div>
@endif
