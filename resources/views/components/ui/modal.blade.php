{{--
    <x-ui.modal> — hộp thoại Alpine (overlay + panel), đóng bằng Esc / click nền / nút X.
    Props:
      name:     định danh để mở/đóng bằng event (bắt buộc)
      title:    tiêu đề (tuỳ chọn)
      maxWidth: sm | md | lg (mặc định) | xl | 2xl
      show:     true => mở sẵn (vd. khi có lỗi validate của form trong modal)
    Slots: nội dung chính (slot), footer (nút hành động)
    Mở/đóng:
      <x-ui.button @click="$dispatch('open-modal', 'confirm-delete')">Xoá</x-ui.button>
      $dispatch('close-modal', 'confirm-delete')
    Ví dụ:
      <x-ui.modal name="confirm-delete" title="Xoá lớp học?" max-width="md" :show="$errors->has('reason')">
          <p>Hành động này không thể hoàn tác.</p>
          <x-slot:footer>
              <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'confirm-delete')">Huỷ</x-ui.button>
              <x-ui.button variant="danger" type="submit" form="delete-form">Xoá</x-ui.button>
          </x-slot:footer>
      </x-ui.modal>
--}}
@props(['name', 'title' => null, 'maxWidth' => 'lg', 'show' => false])

@php
    $width = ['sm' => 'sm:max-w-sm', 'md' => 'sm:max-w-md', 'lg' => 'sm:max-w-lg', 'xl' => 'sm:max-w-xl', '2xl' => 'sm:max-w-2xl'][$maxWidth] ?? 'sm:max-w-lg';
@endphp

<div x-data="{ show: @js((bool) $show) }"
     x-init="$watch('show', v => document.body.classList.toggle('overflow-hidden', v)); if (show) document.body.classList.add('overflow-hidden')"
     x-on:open-modal.window="$event.detail === @js($name) && (show = true)"
     x-on:close-modal.window="$event.detail === @js($name) && (show = false)"
     x-on:keydown.escape.window="show = false"
     x-show="show" x-cloak
     class="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto px-md py-lg sm:items-center"
     role="dialog" aria-modal="true" @if ($title) aria-label="{{ $title }}" @endif>
    <div x-show="show" x-transition.opacity class="fixed inset-0 bg-on-surface/40 backdrop-blur-xs" @click="show = false"></div>

    <div x-show="show"
         x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
         x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100 sm:scale-100" x-transition:leave-end="opacity-0 sm:scale-95"
         {{ $attributes->merge(['class' => "relative w-full {$width} overflow-hidden rounded-xl bg-surface-container-lowest shadow-level-3"]) }}>
        <div class="flex items-center justify-between gap-md border-b border-surface-container px-lg py-md">
            <h3 class="font-h3 text-h3 text-on-surface">{{ $title }}</h3>
            <button type="button" class="rounded-lg p-xs text-on-surface-variant hover:bg-surface-container-high" @click="show = false" aria-label="Đóng">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="px-lg py-md font-body-base text-body-base text-on-surface">{{ $slot }}</div>
        @isset($footer)
            <div class="flex flex-wrap justify-end gap-sm border-t border-surface-container bg-surface-container-low px-lg py-md">{{ $footer }}</div>
        @endisset
    </div>
</div>
