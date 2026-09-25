{{--
    <x-ui.field> — khung trường form: label (+ dấu * bắt buộc), control (slot), gợi ý, lỗi validate.
    Thường không dùng trực tiếp: <x-ui.input>/<x-ui.select>/<x-ui.textarea>/<x-ui.date> tự bọc field khi có `label`.
    Props: label, name (để lấy lỗi $errors), for (id của control), required (bool), hint
    Ví dụ:
      <x-ui.field label="Ghi chú" name="note" hint="Tối đa 500 ký tự">
          <textarea name="note" ...></textarea>
      </x-ui.field>
--}}
@props(['label' => null, 'name' => null, 'for' => null, 'required' => false, 'hint' => null])

@php
    $errorKey = $name ? rtrim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.') : null;
    $message = $errorKey ? ($errors ?? new \Illuminate\Support\ViewErrorBag)->first($errorKey) : null;
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col gap-xs']) }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="font-body-small text-body-small text-on-surface-variant">
            {{ $label }}@if ($required)<span class="ml-0.5 text-error" aria-hidden="true">*</span>@endif
        </label>
    @endif
    {{ $slot }}
    @if ($message)
        <p class="flex items-center gap-xs font-caption text-caption text-error" role="alert">
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">error</span>{{ $message }}
        </p>
    @elseif ($hint)
        <p class="font-caption text-caption text-on-surface-variant">{{ $hint }}</p>
    @endif
</div>
