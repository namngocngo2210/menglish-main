{{--
    <x-ui.alert> — banner thông báo trong trang, viền trái 4px theo mockup.
    Props:
      type:        error | warning | info (mặc định) | success
      title:       tiêu đề đậm (tuỳ chọn)
      dismissible: true => có nút đóng (Alpine)
    Slot: nội dung (text hoặc <ul>)
    Ví dụ:
      <x-ui.alert type="error" title="Cảnh báo xung đột lịch">Trợ giảng <strong>Trần Thị Bích</strong> bị trùng giờ slot 1.</x-ui.alert>
      <x-ui.alert type="warning" dismissible>Số lớp đã thay đổi 12 → 15 so với tuần trước.</x-ui.alert>
--}}
@props(['type' => 'info', 'title' => null, 'dismissible' => false])

@php
    $styles = [
        'error' => ['bg-error-container border-error text-on-error-container', 'text-error', 'error'],
        'warning' => ['bg-warning-container border-warning text-on-warning-container', 'text-warning', 'warning'],
        'info' => ['bg-secondary-fixed/50 border-secondary text-on-secondary-fixed', 'text-secondary', 'info'],
        'success' => ['bg-tertiary-fixed/30 border-tertiary text-on-tertiary-fixed-variant', 'text-tertiary', 'check_circle'],
    ];
    [$box, $iconTone, $icon] = $styles[$type] ?? $styles['info'];
@endphp

<div role="{{ $type === 'error' ? 'alert' : 'status' }}"
     @if ($dismissible) x-data="{ open: true }" x-show="open" x-transition.opacity @endif
     {{ $attributes->merge(['class' => "flex items-start gap-sm rounded-r-lg border-l-4 p-md shadow-sm {$box}"]) }}>
    <span class="material-symbols-outlined mt-[2px] shrink-0 {{ $iconTone }}" aria-hidden="true">{{ $icon }}</span>
    <div class="min-w-0 flex-1 font-body-base text-body-base">
        @if ($title)
            <h4 class="font-body-medium text-body-medium font-semibold">{{ $title }}</h4>
        @endif
        <div @class(['mt-xs' => $title])>{{ $slot }}</div>
    </div>
    @if ($dismissible)
        <button type="button" @click="open = false" class="shrink-0 rounded p-0.5 opacity-70 hover:opacity-100" aria-label="Đóng">
            <span class="material-symbols-outlined text-[18px]">close</span>
        </button>
    @endif
</div>
