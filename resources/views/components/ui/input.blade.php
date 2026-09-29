{{--
    <x-ui.input> — ô nhập liệu chuẩn. Có `label` => tự bọc <x-ui.field> (label, *, hint, lỗi).
    Props: name, label, type (mặc định text), value (mặc định old(name)), required, hint, icon (icon trái), inlineLabel,
           suffix (chữ đơn vị cố định bên phải trong ô, vd. "VNĐ", "học viên"), bag (error bag có tên)
    Lỗi validate của `name` => viền đỏ + thông báo màu error (ô nhập trỏ aria-describedby tới thông báo).
    type="password" => có nút hiện / ẩn mật khẩu bên phải.
    Ví dụ:
      <x-ui.input name="phone" label="Số điện thoại" required hint="10 số, bắt đầu bằng 0" />
      <x-ui.input name="amount" type="number" label="Số tiền" :value="$receipt->amount" />
--}}
@props(['name' => null, 'label' => null, 'type' => 'text', 'value' => null, 'required' => false, 'hint' => null, 'icon' => null, 'inlineLabel' => null, 'suffix' => null, 'bag' => null])

@php
    // Có nhãn mà không có name/id (ô Alpine x-model) → sinh id để <label for> vẫn gắn đúng ô.
    $id = $attributes->get('id') ?? ($name ? 'f_' . preg_replace('/[^A-Za-z0-9_]/', '_', $name) : ($label ? 'f_' . \Illuminate\Support\Str::random(8) : null));
    $errorKey = $name ? rtrim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.') : null;
    $hasError = $errorKey && (($errors ?? new \Illuminate\Support\ViewErrorBag)->getBag($bag ?? 'default'))->has($errorKey);
    $val = $type === 'password' ? null : ($name ? old($errorKey, $value) : $value);
    $revealable = $type === 'password';
    // aria-describedby chỉ trỏ tới lỗi / gợi ý do <x-ui.field> vẽ (khi có label).
    $describedBy = $label && $id ? ($hasError ? $id . '-error' : ($hint ? $id . '-hint' : null)) : null;
    $control = 'w-full rounded-lg border bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface placeholder:text-on-surface-subtle transition-colors focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-surface-container-low disabled:text-on-surface-variant '
        . ($hasError ? 'border-error focus:border-error focus:ring-error/20' : 'border-outline-variant focus:border-primary-container focus:ring-primary-container/50')
        . ($icon ? ' pl-10' : '') . ($suffix ? ' pr-16' : '') . ($revealable ? ' pr-12' : '');
@endphp

@if ($label)
    <x-ui.field :label="$label" :name="$name" :for="$id" :required="$required" :hint="$hint" :bag="$bag">
        <div class="relative" @if ($revealable) x-data="{ reveal: false }" @endif>@include('components.ui.partials.input-control')</div>
    </x-ui.field>
@elseif (! $inlineLabel && ! $icon && ! $suffix && ! $revealable)
    {{-- Không label / icon: chỉ ô nhập (co giãn đúng trong hàng flex / grid) --}}
    @include('components.ui.partials.input-control')
@else
    <label class="relative flex items-center gap-sm" @if ($revealable) x-data="{ reveal: false }" @endif>
        @if ($inlineLabel)<span class="whitespace-nowrap font-body-small text-body-small font-medium text-on-surface-variant">{{ $inlineLabel }}</span>@endif
        @include('components.ui.partials.input-control')
    </label>
@endif
