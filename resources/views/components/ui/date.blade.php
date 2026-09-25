{{--
    <x-ui.date> — ô chọn ngày (input type="date", giá trị Y-m-d). Cùng API với <x-ui.input>.
    Ví dụ: <x-ui.date name="start_date" label="Ngày khai giảng" required :value="$class->start_date?->format('Y-m-d')" />
           <x-ui.date name="from" inline-label="Từ ngày:" :value="request('from')" />
--}}
@props(['name' => null, 'label' => null, 'value' => null, 'required' => false, 'hint' => null, 'inlineLabel' => null])

@php
    $val = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
@endphp

<x-ui.input type="date" :name="$name" :label="$label" :value="$val" :required="$required" :hint="$hint" :inline-label="$inlineLabel" {{ $attributes }} />
