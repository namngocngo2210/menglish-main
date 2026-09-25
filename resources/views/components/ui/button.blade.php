{{--
    <x-ui.button> — nút chuẩn theo mockup.
    Props:
      variant: primary (mặc định, cam CTA) | secondary (viền) | ghost (hành động trong bảng) | danger (nền đỏ) | danger-text (chữ đỏ)
      size:    md (mặc định) | sm
      icon:    tên Material Symbol hiển thị trước nhãn (vd. "add"); không có slot => nút chỉ-icon
      href:    có href => render thẻ <a>, ngược lại <button type="button"> (đổi bằng type="submit")
    Ví dụ:
      <x-ui.button icon="add" :href="route('classes.create')">Tạo lớp mới</x-ui.button>
      <x-ui.button variant="secondary" icon="download">Xuất Excel</x-ui.button>
      <x-ui.button type="submit">Lưu</x-ui.button>
      <x-ui.button variant="ghost" icon="more_vert" aria-label="Thao tác" />
--}}
@props(['variant' => 'primary', 'size' => 'md', 'icon' => null, 'href' => null, 'type' => 'button'])

@php
    $variants = [
        'primary' => 'bg-primary-container text-white shadow-sm hover:bg-primary',
        'secondary' => 'border border-outline-variant bg-surface-container-lowest text-on-surface shadow-sm hover:bg-surface-container-low',
        'ghost' => 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary',
        'danger' => 'bg-error text-white shadow-sm hover:bg-on-error-container',
        'danger-text' => 'border border-transparent text-error hover:border-error/20 hover:bg-error-container/50',
    ];
    $iconOnly = $slot->isEmpty() && $icon;
    $sizes = $iconOnly
        ? ['md' => 'p-sm', 'sm' => 'p-xs']
        : ['md' => 'px-md py-sm font-body-medium text-body-medium', 'sm' => 'px-sm py-xs font-body-medium text-body-small'];
    $classes = 'inline-flex shrink-0 items-center justify-center gap-xs whitespace-nowrap rounded-lg transition-colors duration-150 active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40 disabled:pointer-events-none disabled:opacity-50 '
        . ($variants[$variant] ?? $variants['primary']) . ' ' . ($sizes[$size] ?? $sizes['md']);
    $iconSize = $size === 'sm' ? 'text-[16px]' : 'text-[18px]';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<span class="material-symbols-outlined {{ $iconSize }}" aria-hidden="true">{{ $icon }}</span>@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<span class="material-symbols-outlined {{ $iconSize }}" aria-hidden="true">{{ $icon }}</span>@endif
        {{ $slot }}
    </button>
@endif
