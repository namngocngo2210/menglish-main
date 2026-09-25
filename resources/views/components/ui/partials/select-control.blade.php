{{-- Phần tử <select> dùng chung cho <x-ui.select> (không dùng trực tiếp). --}}
<select @if ($name) name="{{ $name }}" @endif @if ($id) id="{{ $id }}" @endif @if ($required) required @endif @if ($hasError) aria-invalid="true" @endif
        {{ $attributes->except('id')->merge(['class' => $control]) }}>
    @if (! is_null($placeholder))
        <option value="">{{ $placeholder }}</option>
    @endif
    @foreach ($options as $optValue => $optLabel)
        <option value="{{ $optValue }}" @selected((string) $optValue === $selected)>{{ $optLabel }}</option>
    @endforeach
    {{ $slot }}
</select>
