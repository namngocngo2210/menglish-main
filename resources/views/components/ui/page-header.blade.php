{{--
    <x-ui.page-header> — tiêu đề trang (H1) + mô tả + nhóm nút hành động bên phải.
    Props: title (bắt buộc), description (tuỳ chọn)
    Slots: actions (nút bên phải), breadcrumbs (hiển thị phía trên tiêu đề)
    Ví dụ:
      <x-ui.page-header title="TKB — Quản lý lớp học" description="Cấu hình thời khóa biểu và phân bổ nhân sự.">
          <x-slot:actions>
              <x-ui.button variant="secondary" icon="download">Xuất Excel</x-ui.button>
              <x-ui.button icon="add" :href="route('classes.create')">Tạo lớp mới</x-ui.button>
          </x-slot:actions>
      </x-ui.page-header>
--}}
@props(['title', 'description' => null])

<header {{ $attributes->merge(['class' => 'mb-lg flex flex-col gap-md md:flex-row md:items-center md:justify-between']) }}>
    <div class="min-w-0">
        @isset($breadcrumbs)
            <nav class="mb-xs flex flex-wrap items-center gap-xs font-body-small text-body-small text-on-surface-variant" aria-label="Breadcrumb">{{ $breadcrumbs }}</nav>
        @endisset
        <h1 class="font-h1 text-h1 text-on-surface">{{ $title }}</h1>
        @if ($description)
            <p class="mt-xs font-body-medium text-body-medium text-on-surface-variant">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-sm">{{ $actions }}</div>
    @endisset
</header>
