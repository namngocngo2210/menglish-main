{{--
    <x-ui.code> — hiển thị mã chứng từ / hồ sơ. Mã ULID dài được rút gọn (tiền tố + 6 ký tự cuối, xem
    App\Support\DisplayCode), mã đầy đủ ở tooltip. Mã ngắn hiển thị nguyên văn (không bọc thẻ nếu không truyền class).
    Chỉ đổi hiển thị, không đổi mã. Ví dụ: <x-ui.code :value="$student->code" class="text-on-surface-variant" />
--}}
@props(['value' => null])
@php
    $full = (string) $value;
    $short = \App\Support\DisplayCode::short($full);
    $plain = $attributes->isEmpty();
@endphp
@if ($full === ''){!! $plain ? '—' : '<span '.$attributes.'>—</span>' !!}@elseif ($short !== $full)<span {{ $attributes->merge(['class' => 'cursor-help']) }} title="{{ $full }}">{{ $short }}</span>@elseif ($plain){{ $full }}@else<span {{ $attributes }}>{{ $full }}</span>@endif
