{{-- Lightbox ảnh bằng chứng hoàn tiền (chỉ dạng modal; mở thẳng URL tuition.refunds.proof trả file ảnh gốc). --}}
<x-ui.modal-frame :title="'Ảnh bằng chứng — '.($refund->student?->name ?? 'Hồ sơ #'.$refund->id)" cancel="Đóng"
                  :description="'Hồ sơ #'.$refund->id.($refund->student?->code ? ' · '.$refund->student->code : '')">
    <figure class="flex justify-center rounded-lg bg-surface-container-low p-sm">
        <img src="{{ route('tuition.refunds.proof', $refund->id) }}" alt="Ảnh bằng chứng hoàn tiền của {{ $refund->student?->name }}"
             class="max-h-[70vh] w-auto max-w-full rounded object-contain">
    </figure>
    <x-slot:footer>
        <x-ui.button variant="secondary" icon="open_in_new" :href="route('tuition.refunds.proof', $refund->id)" target="_blank" rel="noopener" hx-boost="false">Mở ảnh gốc</x-ui.button>
    </x-slot:footer>
</x-ui.modal-frame>
