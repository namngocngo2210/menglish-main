{{--
    <x-ui.modal> — hộp thoại Alpine (overlay + panel), đóng bằng Esc / click nền / nút X.
    Props:
      name:     định danh để mở/đóng bằng event (bắt buộc)
      title:    tiêu đề (tuỳ chọn)
      maxWidth: sm | md | lg (mặc định) | xl | 2xl | 3xl | 4xl | full — từ 2xl trở lên: toàn màn trên điện thoại (< sm)
      show:     true => mở sẵn (vd. khi có lỗi validate của form trong modal)
      bare:     true => không render header/thân/footer, slot tự dựng khung (dùng cho x-ui.remote-modal + x-ui.modal-frame)
    Slots: nội dung chính (slot, tự cuộn khi dài — header/footer đứng yên), footer (nút hành động)
    Hành vi: giữ focus trong modal (x-trap), đóng thì trả focus về nút mở, khoá cuộn trang nền.
      Form bên trong đã sửa mà bấm nền / Esc / X → hỏi "Bỏ các thay đổi chưa lưu?". Đóng bằng event close-modal thì không hỏi.
    Mở/đóng:
      <x-ui.button @click="$dispatch('open-modal', 'confirm-delete')">Xoá</x-ui.button>
      $dispatch('open-modal', { name: 'remote', size: '3xl' })   // mở kèm đổi cỡ
      $dispatch('close-modal', 'confirm-delete')                   // '*' = đóng mọi modal đang mở
    Ví dụ:
      <x-ui.modal name="confirm-delete" title="Xoá lớp học?" max-width="md" :show="$errors->has('reason')">
          <p>Hành động này không thể hoàn tác.</p>
          <x-slot:footer>
              <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'confirm-delete')">Huỷ</x-ui.button>
              <x-ui.button variant="danger" type="submit" form="delete-form">Xoá</x-ui.button>
          </x-slot:footer>
      </x-ui.modal>
--}}
@props(['name', 'title' => null, 'maxWidth' => 'lg', 'show' => false, 'bare' => false])

@php
    $widths = [
        'sm' => 'sm:max-w-sm', 'md' => 'sm:max-w-md', 'lg' => 'sm:max-w-lg', 'xl' => 'sm:max-w-xl',
        '2xl' => 'sm:max-w-2xl', '3xl' => 'sm:max-w-3xl', '4xl' => 'sm:max-w-4xl', 'full' => 'sm:max-w-none',
    ];
    $maxWidth = isset($widths[$maxWidth]) ? $maxWidth : 'lg';
    $titleId = 'modal-'.\Illuminate\Support\Str::slug($name).'-title';
@endphp

<div x-data="{
        show: @js((bool) $show),
        size: @js($maxWidth),
        dirty: false,
        widths: @js($widths),
        get large() { return ['2xl', '3xl', '4xl', 'full'].includes(this.size) },
        matches(d) { return d === @js($name) || d === '*' || d?.name === @js($name) },
        open(size) { this.size = this.widths[size] ? size : @js($maxWidth); this.dirty = false; this.show = true },
        close(force = false) {
            if (!this.show) return;
            if (!force && this.dirty && !confirm('Bỏ các thay đổi chưa lưu?')) return;
            this.show = false;
            this.dirty = false;
        },
     }"
     x-init="$watch('show', v => v || $dispatch('modal-closed', @js($name)))"
     x-on:open-modal.window="matches($event.detail) && open($event.detail?.size)"
     x-on:close-modal.window="matches($event.detail) && close(true)"
     x-on:keydown.escape.window="close()"
     x-show="show" x-cloak
     data-modal="{{ $name }}"
     class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:px-md sm:py-lg"
     :class="large ? 'p-0' : 'px-md py-lg'"
     role="dialog" aria-modal="true" aria-labelledby="{{ $titleId }}">
    <div x-show="show" x-transition.opacity class="fixed inset-0 bg-on-surface/40 backdrop-blur-xs" @click="close()"></div>

    {{-- Panel: header/footer cố định, thân cuộn; cỡ lớn chiếm toàn màn khi < sm --}}
    <div x-show="show" x-trap.noscroll="show" tabindex="-1"
         x-on:input="dirty = true" x-on:change="dirty = true"
         x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
         x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100 sm:scale-100" x-transition:leave-end="opacity-0 sm:scale-95"
         :class="[widths[size], large ? 'h-full rounded-none' : 'rounded-xl', large && size !== 'full' ? 'sm:h-auto' : '']"
         {{ $attributes->merge(['class' => 'relative flex max-h-full w-full flex-col overflow-hidden bg-surface-container-lowest shadow-level-3 focus:outline-none sm:rounded-xl']) }}>
        @if ($bare)
            {{ $slot }}
        @else
            <div class="flex shrink-0 items-center justify-between gap-md border-b border-surface-container px-lg py-md">
                <h3 id="{{ $titleId }}" class="font-h3 text-h3 text-on-surface">{{ $title }}</h3>
                <button type="button" class="rounded-lg p-xs text-on-surface-variant hover:bg-surface-container-high" @click="close()" aria-label="Đóng">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-lg py-md font-body-base text-body-base text-on-surface">{{ $slot }}</div>
            @isset($footer)
                <div class="flex shrink-0 flex-wrap justify-end gap-sm border-t border-surface-container bg-surface-container-low px-lg py-md">{{ $footer }}</div>
            @endisset
        @endif
    </div>
</div>
