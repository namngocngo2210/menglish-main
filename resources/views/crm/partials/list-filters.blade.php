{{-- Bộ lọc dùng chung cho Pipeline / Khách chốt / Khách không chốt.
     Cần: $filterBranches (chỉ Admin có dữ liệu), $filterSales, $filterSources. Tuỳ chọn: $dateLabel, $exportable --}}
@php
    $dateLabel ??= 'Ngày tạo';
    $exportable ??= false;
@endphp
<x-ui.filter-bar placeholder="Tìm tên, SĐT, mã KH, phụ huynh...">
    @if ($filterBranches->isNotEmpty())
        <x-ui.select name="branch_id" :options="$filterBranches->pluck('name', 'id')" placeholder="Tất cả chi nhánh" aria-label="Chi nhánh" />
    @endif
    <x-ui.select name="assigned_user_id" :options="$filterSales->pluck('name', 'id')" placeholder="Tất cả Sales" aria-label="Sales phụ trách" />
    <x-ui.select name="source" :options="$filterSources->mapWithKeys(fn ($s) => [$s => $s])" placeholder="Tất cả nguồn" aria-label="Nguồn khách" />
    <x-ui.date name="from" :value="request('from')" :inline-label="$dateLabel.' từ:'" />
    <x-ui.date name="to" :value="request('to')" inline-label="đến:" />
    @if ($exportable)
        <div class="flex items-center gap-xs">
            <x-ui.button variant="secondary" size="sm" icon="download" :href="request()->fullUrlWithQuery(['export' => 'xlsx', 'page' => null])">Xuất Excel</x-ui.button>
            <x-ui.button variant="ghost" size="sm" :href="request()->fullUrlWithQuery(['export' => 'csv', 'page' => null])">CSV</x-ui.button>
        </div>
    @endif
</x-ui.filter-bar>
