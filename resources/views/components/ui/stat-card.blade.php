{{--
    <x-ui.stat-card> — thẻ số liệu tổng quan.
    Props:
      label: nhãn (bắt buộc)
      value: giá trị hiển thị (đã format)
      tone:  default | primary | success | secondary | error | warning — màu của con số
      icon:  Material Symbol (tuỳ chọn) — hiển thị trong vòng tròn bên trái
      hint:  dòng chú thích nhỏ phía dưới (tuỳ chọn)
    Ví dụ:
      <div class="grid grid-cols-2 gap-md lg:grid-cols-4">
          <x-ui.stat-card label="Tổng nhân sự" :value="$total" />
          <x-ui.stat-card label="Vô hiệu hóa" :value="$locked" tone="error" icon="person_off" />
      </div>
--}}
@props(['label', 'value' => null, 'tone' => 'default', 'icon' => null, 'hint' => null])

@php
    $tones = [
        'default' => ['text-on-surface', 'bg-surface-container-low text-on-surface-variant'],
        'primary' => ['text-primary-container', 'bg-primary-fixed text-primary'],
        'success' => ['text-tertiary-container', 'bg-tertiary-fixed/50 text-tertiary'],
        'secondary' => ['text-secondary-container', 'bg-secondary-fixed text-secondary'],
        'error' => ['text-error', 'bg-error-container text-error'],
        'warning' => ['text-warning', 'bg-warning-container text-warning'],
    ];
    [$valueTone, $iconTone] = $tones[$tone] ?? $tones['default'];
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-md rounded-xl border border-surface-variant bg-surface-container-lowest p-md']) }}>
    @if ($icon)
        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full {{ $iconTone }}">
            <span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span>
        </div>
    @endif
    <div class="flex min-w-0 flex-col gap-xs">
        <span class="truncate font-body-medium text-body-medium text-on-surface-variant">{{ $label }}</span>
        <span class="font-h1 text-h1 {{ $valueTone }}">{{ $value ?? $slot }}</span>
        @if ($hint)
            <span class="font-caption text-caption text-on-surface-variant">{{ $hint }}</span>
        @endif
    </div>
</div>
