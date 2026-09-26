{{--
    <x-ui.textarea> — ô nhập nhiều dòng. Có `label` => tự bọc <x-ui.field>.
    Props: name, label, value (mặc định old(name)), rows (mặc định 4), required, hint
    Ví dụ: <x-ui.textarea name="reason" label="Lý do không chốt" required rows="3" />
--}}
@props(['name' => null, 'label' => null, 'value' => null, 'rows' => 4, 'required' => false, 'hint' => null, 'bag' => null])

@php
    $id = $attributes->get('id') ?? ($name ? 'f_' . preg_replace('/[^A-Za-z0-9_]/', '_', $name) : null);
    $errorKey = $name ? rtrim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.') : null;
    $hasError = $errorKey && (($errors ?? new \Illuminate\Support\ViewErrorBag)->getBag($bag ?? 'default'))->has($errorKey);
    $val = $name ? old($errorKey, $value ?? ($slot->isNotEmpty() ? (string) $slot : null)) : $value;
    $control = 'w-full rounded-lg border bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface placeholder:text-on-surface-variant/60 transition-colors focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-surface-container-low '
        . ($hasError ? 'border-error focus:border-error focus:ring-error/20' : 'border-outline-variant focus:border-primary-container focus:ring-primary-container/20');
@endphp

@if ($label)
    <x-ui.field :label="$label" :name="$name" :for="$id" :required="$required" :hint="$hint" :bag="$bag">
        <textarea @if ($name) name="{{ $name }}" @endif id="{{ $id }}" rows="{{ $rows }}" @if ($required) required @endif @if ($hasError) aria-invalid="true" @endif {{ $attributes->except('id')->merge(['class' => $control]) }}>{{ $val }}</textarea>
    </x-ui.field>
@else
    <textarea @if ($name) name="{{ $name }}" @endif @if ($id) id="{{ $id }}" @endif rows="{{ $rows }}" @if ($required) required @endif @if ($hasError) aria-invalid="true" @endif {{ $attributes->except('id')->merge(['class' => $control]) }}>{{ $val }}</textarea>
@endif
