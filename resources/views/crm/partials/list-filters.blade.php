{{-- Bộ lọc dùng chung cho Pipeline / Khách chốt / Khách không chốt.
     Cần: $filterBranches (chỉ Admin có dữ liệu), $filterSales, $filterSources. Tuỳ chọn: $dateLabel, $exportable --}}
@php
    $dateLabel ??= 'Ngày tạo';
    $exportable ??= false;
    $searchPlaceholder ??= 'Tìm họ tên, số điện thoại...';
    $exportLabel ??= 'Xuất Excel';
@endphp
<x-ui.filter-bar :placeholder="$searchPlaceholder">
    {{-- Thứ tự & nhãn theo mockup pipeline-tong-quan-giai-doan: Nguồn → Người phụ trách → Chi nhánh --}}
    <x-ui.select name="source" label="Nguồn" :options="$filterSources->mapWithKeys(fn ($s) => [$s => $s])" placeholder="Tất cả nguồn" />
    <x-ui.select name="assigned_user_id" label="Người phụ trách" :options="$filterSales->pluck('name', 'id')" placeholder="Tất cả nhân viên" />
    @if ($filterBranches->isNotEmpty())
        <x-ui.select name="branch_id" label="Chi nhánh" :options="$filterBranches->pluck('name', 'id')" placeholder="Tất cả chi nhánh" />
    @endif
    @isset($filterClasses)
        <x-ui.select name="class_id" label="Lớp học" :options="$filterClasses->pluck('name', 'id')" placeholder="Tất cả lớp" />
    @endisset
    <x-ui.date-range :label="$dateLabel" />
    @if ($exportable)
        <div class="flex items-center gap-xs sm:col-span-2">
            <x-ui.button variant="secondary" size="sm" icon="download" :href="request()->fullUrlWithQuery(['export' => 'xlsx', 'page' => null])">{{ $exportLabel }}</x-ui.button>
            <x-ui.button variant="ghost" size="sm" :href="request()->fullUrlWithQuery(['export' => 'csv', 'page' => null])">CSV</x-ui.button>
        </div>
    @endif
</x-ui.filter-bar>
