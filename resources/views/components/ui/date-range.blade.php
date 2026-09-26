{{--
    <x-ui.date-range> — khoảng ngày / tháng trong 1 ô: [từ] – [đến], chung 1 nhãn. Mặc định chiếm 2 cột trong <x-ui.filter-bar>.
    Props: label, from (tên tham số, mặc định "from"), to (mặc định "to"), fromValue / toValue (mặc định request()),
           type (date | month, mặc định date)
    Ví dụ: <x-ui.date-range label="Ngày tạo" />
           <x-ui.date-range label="Kỳ báo cáo" type="month" from="month" to="month_to" :from-value="$month" :to-value="$monthTo" />
--}}
@props(['label' => null, 'from' => 'from', 'to' => 'to', 'fromValue' => null, 'toValue' => null, 'type' => 'date'])

@php
    $fmt = fn ($v) => $v instanceof \DateTimeInterface ? $v->format($type === 'month' ? 'Y-m' : 'Y-m-d') : $v;
    $fromVal = $fmt($fromValue ?? request($from));
    $toVal = $fmt($toValue ?? request($to));
@endphp

<x-ui.field :label="$label" :name="$errors->has($to) && ! $errors->has($from) ? $to : $from" :for="'f_'.$from" {{ $attributes->merge(['class' => 'sm:col-span-2']) }}>
    <div class="flex items-center gap-xs">
        <x-ui.input :type="$type" :name="$from" :value="$fromVal" class="min-w-0 flex-1" :aria-label="($label ? $label.' ' : '').'từ'" />
        <span class="shrink-0 text-on-surface-variant" aria-hidden="true">–</span>
        <x-ui.input :type="$type" :name="$to" :value="$toVal" class="min-w-0 flex-1" :aria-label="($label ? $label.' ' : '').'đến'" />
    </div>
</x-ui.field>
