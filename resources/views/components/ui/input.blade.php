{{--
    <x-ui.input> — ô nhập liệu chuẩn. Có `label` => tự bọc <x-ui.field> (label, *, hint, lỗi).
    Props: name, label, type (mặc định text), value (mặc định old(name)), required, hint, icon (icon trái), inlineLabel,
           suffix (chữ đơn vị cố định bên phải trong ô, vd. "VNĐ", "học viên")
    Lỗi validate của `name` => viền đỏ + thông báo màu error.
    Ví dụ:
      <x-ui.input name="phone" label="Số điện thoại" required hint="10 số, bắt đầu bằng 0" />
      <x-ui.input name="amount" type="number" label="Số tiền" :value="$receipt->amount" />
--}}
@props(['name' => null, 'label' => null, 'type' => 'text', 'value' => null, 'required' => false, 'hint' => null, 'icon' => null, 'inlineLabel' => null, 'suffix' => null])

@php
    $id = $attributes->get('id') ?? ($name ? 'f_' . preg_replace('/[^A-Za-z0-9_]/', '_', $name) : null);
    $errorKey = $name ? rtrim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.') : null;
    $hasError = $errorKey && ($errors ?? new \Illuminate\Support\ViewErrorBag)->has($errorKey);
    $val = $type === 'password' ? null : ($name ? old($errorKey, $value) : $value);
    $control = 'w-full rounded-lg border bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface placeholder:text-on-surface-variant/60 transition-colors focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-surface-container-low disabled:text-on-surface-variant '
        . ($hasError ? 'border-error focus:border-error focus:ring-error/20' : 'border-outline-variant focus:border-primary-container focus:ring-primary-container/20')
        . ($icon ? ' pl-10' : '') . ($suffix ? ' pr-16' : '');
    $suffixHtml = $suffix ? '<span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 font-body-small text-body-small text-on-surface-variant">'.e($suffix).'</span>' : '';
@endphp

@if ($label)
    <x-ui.field :label="$label" :name="$name" :for="$id" :required="$required" :hint="$hint">
        <div class="relative">
            @if ($icon)<span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">{{ $icon }}</span>@endif
            <input type="{{ $type }}" @if ($name) name="{{ $name }}" @endif id="{{ $id }}" @if (! is_null($val)) value="{{ $val }}" @endif
                   @if ($required) required @endif @if ($hasError) aria-invalid="true" @endif
                   {{ $attributes->except('id')->merge(['class' => $control]) }}>
            {!! $suffixHtml !!}
        </div>
    </x-ui.field>
@elseif (! $inlineLabel && ! $icon && ! $suffix)
    {{-- Không label / icon: chỉ ô nhập (co giãn đúng trong hàng flex / grid) --}}
    <input type="{{ $type }}" @if ($name) name="{{ $name }}" @endif @if ($id) id="{{ $id }}" @endif @if (! is_null($val)) value="{{ $val }}" @endif
           @if ($required) required @endif @if ($hasError) aria-invalid="true" @endif
           {{ $attributes->except('id')->merge(['class' => $control]) }}>
@else
    <label class="relative flex items-center gap-sm">
        @if ($inlineLabel)<span class="whitespace-nowrap font-body-small text-body-small font-medium text-on-surface-variant">{{ $inlineLabel }}</span>@endif
        @if ($icon)<span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">{{ $icon }}</span>@endif
        <input type="{{ $type }}" @if ($name) name="{{ $name }}" @endif @if ($id) id="{{ $id }}" @endif @if (! is_null($val)) value="{{ $val }}" @endif
               @if ($required) required @endif @if ($hasError) aria-invalid="true" @endif
               {{ $attributes->except('id')->merge(['class' => $control]) }}>
        {!! $suffixHtml !!}
    </label>
@endif
