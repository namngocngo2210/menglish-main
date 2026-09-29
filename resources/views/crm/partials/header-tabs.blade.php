{{-- Đầu trang chung của workspace CRM, cùng mẫu với các khu khác: tab khu vực (+ nút Nhập Excel / Thêm khách mới,
     khai báo ở SidebarMenu workspace "crm") → tiêu đề màn hình.
     Tham số: title (tiêu đề màn; false = trang tự đặt <x-ui.page-header>), description (tuỳ chọn).
     Không đặt ô tìm kiếm ở đây: mỗi màn đã có ô tìm trong bộ lọc (tránh hai ô tìm trên một màn). --}}
@php
    // Số trên chip lọc nhanh (theo phạm vi dữ liệu của user).
    $crmVisible = fn () => \App\Models\CrmCustomer::query()->visibleTo(auth()->user());
    $crmStageCounts = $crmVisible()->whereIn('stage', ['waiting_class', 'won', 'lost'])
        ->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage');
    $crmChipCounts = [
        'sla' => $crmVisible()->staleNew()->count(),
        'waiting_class' => (int) ($crmStageCounts['waiting_class'] ?? 0),
        'won' => (int) ($crmStageCounts['won'] ?? 0),
        'lost' => (int) ($crmStageCounts['lost'] ?? 0),
        'deleted' => $crmVisible()->onlyTrashed()->count(),
    ];
@endphp
@php(request()->attributes->set('workspace_chip_counts', $crmChipCounts))
<x-ui.workspace-tabs workspace="crm" />

@if (($title ?? null) !== false)
    <x-ui.page-header :title="$title ?? 'Khách hàng'" :description="$description ?? null" />
@endif
