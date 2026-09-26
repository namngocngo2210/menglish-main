{{--
    <x-ui.page-header> — khối tiêu đề CHUNG của mọi trang (đặt ở đầu nội dung trang, không dùng slot `header` của layout).
    Props:
      - title (bắt buộc, chữ) — cũng dùng làm tiêu đề trên topbar.
      - description (tuỳ chọn) — dòng mô tả dưới tiêu đề.
      - icon (tuỳ chọn) — tên Material Symbol đặt trước tiêu đề.
      - back (tuỳ chọn) — URL nút quay lại bên trái tiêu đề; backLabel = aria-label (mặc định "Quay lại").
    Slots:
      - breadcrumbs — phía trên tiêu đề.
      - badges — cạnh tiêu đề (trạng thái, mã, nhãn...).
      - meta — dưới tiêu đề (thay/cùng description khi cần nội dung động).
      - actions — nhóm nút bên phải.
    Ví dụ:
      <x-ui.page-header title="TKB — Quản lý lớp học" icon="calendar_month" description="Cấu hình thời khóa biểu và phân bổ nhân sự.">
          <x-slot:actions>
              <x-ui.button variant="secondary" icon="download">Xuất Excel</x-ui.button>
              <x-ui.button icon="add" :href="route('classes.create')">Tạo lớp mới</x-ui.button>
          </x-slot:actions>
      </x-ui.page-header>
--}}
@props(['title', 'description' => null, 'icon' => null, 'back' => null, 'backLabel' => 'Quay lại'])

@php
    // Tiêu đề topbar của layout lấy từ đây (layout render sau nội dung trang).
    if (! request()->attributes->has('page_title')) {
        request()->attributes->set('page_title', trim(preg_replace('/\s+/u', ' ', strip_tags((string) $title))));
    }
@endphp

<header {{ $attributes->merge(['class' => 'mb-lg flex flex-col gap-md md:flex-row md:items-center md:justify-between']) }} data-page-header>
    <div class="flex min-w-0 items-start gap-sm">
        @if ($back)
            <a href="{{ $back }}" class="mt-0.5 shrink-0 rounded-lg border border-outline-variant bg-surface-container-lowest p-1.5 text-on-surface-variant transition-colors hover:bg-surface-container-low hover:text-on-surface" aria-label="{{ $backLabel }}" title="{{ $backLabel }}">
                <span class="material-symbols-outlined block text-[18px]" aria-hidden="true">arrow_back</span>
            </a>
        @endif
        <div class="min-w-0">
            @isset($breadcrumbs)
                <nav class="mb-xs flex flex-wrap items-center gap-xs font-body-small text-body-small text-on-surface-variant" aria-label="Breadcrumb">{{ $breadcrumbs }}</nav>
            @endisset
            <div class="flex flex-wrap items-center gap-sm">
                <h1 class="flex min-w-0 items-center gap-sm font-h1 text-h1 text-on-surface">
                    @if ($icon)
                        <span class="material-symbols-outlined shrink-0 text-[26px] text-primary" aria-hidden="true">{{ $icon }}</span>
                    @endif
                    <span class="min-w-0">{{ $title }}</span>
                </h1>
                @isset($badges)
                    <div class="flex flex-wrap items-center gap-xs">{{ $badges }}</div>
                @endisset
            </div>
            @if ($description)
                <p class="mt-xs font-body-medium text-body-medium text-on-surface-variant">{{ $description }}</p>
            @endif
            @isset($meta)
                <div class="mt-xs font-body-small text-body-small text-on-surface-variant">{{ $meta }}</div>
            @endisset
        </div>
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-sm">{{ $actions }}</div>
    @endisset
</header>
