{{--
    <x-ui.badge> — nhãn trạng thái dạng "soft" (nền 10% + chữ đậm cùng màu + chấm tròn).
    Props:
      color: neutral (mặc định) | primary | secondary | success | warning | error | info
             | stage-new | stage-consulting | stage-test_scheduled | stage-tested | stage-result_sent
             | stage-closing | stage-won | stage-lost
             | status-new | status-progress | status-pending | status-blocked | status-done | status-overdue | status-canceled
      dot:   true (mặc định) hiển thị chấm màu phía trước
      pill:  false (mặc định bo 4px như mockup bảng) | true bo tròn hoàn toàn
    Ví dụ:
      <x-ui.badge color="success">Đang học</x-ui.badge>
      <x-ui.badge :color="'stage-' . $lead->stage">{{ $lead->stage_label }}</x-ui.badge>
--}}
@props(['color' => 'neutral', 'dot' => true, 'pill' => false])

@php
    // Chuỗi class viết đầy đủ để Tailwind JIT quét được.
    $palette = [
        'neutral' => ['bg-on-surface-variant/10 text-on-surface-variant', 'bg-on-surface-variant'],
        'primary' => ['bg-primary-container/10 text-primary', 'bg-primary-container'],
        'secondary' => ['bg-secondary/10 text-secondary', 'bg-secondary'],
        'success' => ['bg-tertiary/10 text-tertiary', 'bg-tertiary'],
        'warning' => ['bg-warning/10 text-warning', 'bg-warning'],
        'error' => ['bg-error/10 text-error', 'bg-error'],
        'info' => ['bg-info/10 text-info', 'bg-info'],
        'stage-new' => ['bg-stage-new/10 text-stage-new', 'bg-stage-new'],
        'stage-consulting' => ['bg-stage-consulting/10 text-stage-consulting', 'bg-stage-consulting'],
        'stage-test_scheduled' => ['bg-stage-test_scheduled/10 text-stage-test_scheduled', 'bg-stage-test_scheduled'],
        'stage-tested' => ['bg-stage-tested/10 text-stage-tested', 'bg-stage-tested'],
        'stage-result_sent' => ['bg-stage-result_sent/10 text-stage-result_sent', 'bg-stage-result_sent'],
        'stage-closing' => ['bg-stage-closing/10 text-stage-closing', 'bg-stage-closing'],
        'stage-won' => ['bg-stage-won/10 text-stage-won', 'bg-stage-won'],
        'stage-lost' => ['bg-stage-lost/10 text-stage-lost', 'bg-stage-lost'],
        'status-new' => ['bg-status-new/10 text-gray-600', 'bg-status-new'],
        'status-progress' => ['bg-status-progress/15 text-yellow-700', 'bg-status-progress'],
        'status-pending' => ['bg-status-pending/10 text-orange-700', 'bg-status-pending'],
        'status-blocked' => ['bg-status-blocked/10 text-status-blocked', 'bg-status-blocked'],
        'status-done' => ['bg-status-done/10 text-green-700', 'bg-status-done'],
        'status-overdue' => ['bg-status-overdue/10 text-red-600', 'bg-status-overdue'],
        'status-canceled' => ['bg-status-canceled/10 text-status-canceled', 'bg-status-canceled'],
    ];
    [$tone, $dotTone] = $palette[$color] ?? $palette['neutral'];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center gap-xs whitespace-nowrap px-sm py-[2px] font-body-medium text-caption {$tone} " . ($pill ? 'rounded-full' : 'rounded')]) }}>
    @if ($dot)<span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotTone }}" aria-hidden="true"></span>@endif
    {{ $slot }}
</span>
