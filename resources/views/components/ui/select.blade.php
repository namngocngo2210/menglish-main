{{--
    <x-ui.select> — dropdown chuẩn. Có `label` => tự bọc <x-ui.field>.
    Props:
      name, label, required, hint, inlineLabel
      options:     mảng [value => label] (tuỳ chọn; hoặc tự viết <option> trong slot)
      value:       giá trị chọn (mặc định old(name) rồi request(name))
      placeholder: option rỗng đầu tiên (vd. "Tất cả nguồn")
    Ví dụ:
      <x-ui.select name="branch_id" label="Chi nhánh" :options="$branches->pluck('name', 'id')" placeholder="-- Chọn --" required />
--}}
@props(['name' => null, 'label' => null, 'options' => [], 'value' => null, 'placeholder' => null, 'required' => false, 'hint' => null, 'inlineLabel' => null, 'bag' => null])

@php
    $id = $attributes->get('id') ?? ($name ? 'f_' . preg_replace('/[^A-Za-z0-9_]/', '_', $name) : null);
    $errorKey = $name ? rtrim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.') : null;
    $hasError = $errorKey && (($errors ?? new \Illuminate\Support\ViewErrorBag)->getBag($bag ?? 'default'))->has($errorKey);
    $selected = (string) ($name ? old($errorKey, $value ?? request($errorKey)) : $value);
    $control = 'w-full min-w-[150px] rounded-lg border bg-surface-container-lowest py-sm pl-md pr-xl font-body-base text-body-base text-on-surface transition-colors focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-surface-container-low '
        . ($hasError ? 'border-error focus:border-error focus:ring-error/20' : 'border-outline-variant focus:border-primary-container focus:ring-primary-container/20');
@endphp

@if ($label)
    <x-ui.field :label="$label" :name="$name" :for="$id" :required="$required" :hint="$hint" :bag="$bag">@include('components.ui.partials.select-control')</x-ui.field>
@elseif ($inlineLabel)
    <label class="flex items-center gap-sm">
        <span class="whitespace-nowrap font-body-small text-body-small font-medium text-on-surface-variant">{{ $inlineLabel }}</span>
        @include('components.ui.partials.select-control')
    </label>
@else
    @include('components.ui.partials.select-control')
@endif
