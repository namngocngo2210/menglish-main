{{--
    <x-ui.money> — hiển thị số tiền: font JetBrains Mono, căn phải, số âm màu đỏ (error).
    Props:
      value:  số (int/float/string số) — null hiển thị "—"
      suffix: hậu tố đơn vị (mặc định "₫"; truyền "" để ẩn)
      align:  right (mặc định) | left
      sign:   false (mặc định) | true — hiện dấu "+" cho số dương
      tone:   auto (mặc định: âm = đỏ, còn lại màu chữ thường) | success | error | warning | primary | secondary | muted
              — tô màu theo ý nghĩa (vd. doanh thu xanh, chi phí đỏ)
    Ví dụ: <td><x-ui.money :value="$receipt->amount" /></td>   => "1.500.000 ₫"
--}}
@props(['value' => null, 'suffix' => '₫', 'align' => 'right', 'sign' => false, 'tone' => 'auto'])

@php
    $number = is_numeric($value) ? (float) $value : null;
    $formatted = $number === null
        ? '—'
        : ($number < 0 ? '-' : ($sign && $number > 0 ? '+' : '')) . number_format(abs($number), 0, ',', '.') . ($suffix !== '' ? ' ' . $suffix : '');
    $toneClass = [
        'success' => 'text-tertiary', 'error' => 'text-error', 'warning' => 'text-warning',
        'primary' => 'text-primary', 'secondary' => 'text-secondary', 'muted' => 'text-on-surface-variant',
    ][$tone] ?? ($number !== null && $number < 0 ? 'text-error' : 'text-on-surface');
@endphp

<span {{ $attributes->merge(['class' => 'block whitespace-nowrap font-code text-code tabular-nums ' . ($align === 'left' ? 'text-left' : 'text-right') . ' ' . $toneClass]) }}>{{ $formatted }}</span>
