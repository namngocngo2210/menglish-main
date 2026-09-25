{{--
    <x-ui.money> — hiển thị số tiền: font JetBrains Mono, căn phải, số âm màu đỏ (error).
    Props:
      value:  số (int/float/string số) — null hiển thị "—"
      suffix: hậu tố đơn vị (mặc định "₫"; truyền "" để ẩn)
      align:  right (mặc định) | left
      sign:   false (mặc định) | true — hiện dấu "+" cho số dương
    Ví dụ: <td><x-ui.money :value="$receipt->amount" /></td>   => "1.500.000 ₫"
--}}
@props(['value' => null, 'suffix' => '₫', 'align' => 'right', 'sign' => false])

@php
    $number = is_numeric($value) ? (float) $value : null;
    $formatted = $number === null
        ? '—'
        : ($number < 0 ? '-' : ($sign && $number > 0 ? '+' : '')) . number_format(abs($number), 0, ',', '.') . ($suffix !== '' ? ' ' . $suffix : '');
@endphp

<span {{ $attributes->merge(['class' => 'block whitespace-nowrap font-code text-code tabular-nums ' . ($align === 'left' ? 'text-left' : 'text-right') . ' ' . ($number !== null && $number < 0 ? 'text-error' : 'text-on-surface')]) }}>{{ $formatted }}</span>
