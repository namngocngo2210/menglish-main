{{--
    <x-ui.modal-frame> — khung cho fragment server trả về khi mở trong x-ui.remote-modal (view nhận $asModal = true).
    Header (tiêu đề + nút X) và footer (nút Huỷ + nút hành động) cố định, thân cuộn.
    Form bên trong tự gửi bằng htmx (hx-boost): lỗi → server trả lại khung kèm lỗi (422); lưu xong → 204 + đóng modal.
    Props:
      title:       tiêu đề modal
      description: dòng mô tả dưới tiêu đề (tuỳ chọn)
      cancel:      nhãn nút huỷ ở footer (mặc định "Hủy bỏ"; false => ẩn)
    Slots: nội dung (slot), footer (nút hành động — nút submit dùng form="<id form>" vì form nằm trong thân)
    Ví dụ (holidays/form.blade.php):
      <x-ui.modal-frame :title="$holiday->exists ? 'Sửa ngày nghỉ' : 'Thêm ngày nghỉ'">
          @include('holidays._form')
          <x-slot:footer><x-ui.button type="submit" form="modal-holiday-form">Lưu thông tin</x-ui.button></x-slot:footer>
      </x-ui.modal-frame>
--}}
@props(['title', 'description' => null, 'cancel' => 'Hủy bỏ'])

<div {{ $attributes->merge(['class' => 'flex min-h-0 flex-1 flex-col']) }}
     hx-boost="true" hx-target="#remote-modal-body" hx-swap="innerHTML" hx-push-url="false">
    <div class="flex shrink-0 items-start justify-between gap-md border-b border-surface-container px-lg py-md">
        <div class="min-w-0">
            <h2 id="modal-remote-title" class="font-h3 text-h3 text-on-surface">{{ $title }}</h2>
            @if ($description)
                <p class="mt-0.5 font-body-small text-body-small text-on-surface-variant">{{ $description }}</p>
            @endif
        </div>
        <button type="button" class="shrink-0 rounded-lg p-xs text-on-surface-variant hover:bg-surface-container-high" @click="close()" aria-label="Đóng">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto px-lg py-md font-body-base text-body-base text-on-surface">{{ $slot }}</div>

    @if (isset($footer) || $cancel)
        <div class="flex shrink-0 flex-wrap justify-end gap-sm border-t border-surface-container bg-surface-container-low px-lg py-md">
            @if ($cancel)
                <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'remote')">{{ $cancel }}</x-ui.button>
            @endif
            {{ $footer ?? '' }}
        </div>
    @endif
</div>
