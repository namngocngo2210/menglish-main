{{-- Phần tử <input> dùng chung cho <x-ui.input> (không dùng trực tiếp): icon trái, đơn vị bên phải, nút hiện / ẩn mật khẩu. --}}
@if ($icon)<span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">{{ $icon }}</span>@endif
<input type="{{ $type }}" @if ($revealable) x-bind:type="reveal ? 'text' : 'password'" @endif
       @if ($name) name="{{ $name }}" @endif @if ($id) id="{{ $id }}" @endif @if (! is_null($val)) value="{{ $val }}" @endif
       @if ($required) required @endif @if ($hasError) aria-invalid="true" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
       {{ $attributes->except('id')->merge(['class' => $control]) }}>
@if ($suffix)<span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 font-body-small text-body-small text-on-surface-variant">{{ $suffix }}</span>@endif
@if ($revealable)
    <button type="button" class="absolute right-1 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40"
            @click="reveal = ! reveal" x-bind:aria-pressed="reveal.toString()" x-bind:aria-label="reveal ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'" aria-label="Hiện mật khẩu">
        <span class="material-symbols-outlined text-[20px]" aria-hidden="true" x-text="reveal ? 'visibility_off' : 'visibility'">visibility</span>
    </button>
@endif
