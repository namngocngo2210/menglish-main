{{-- Tạo ticket hỗ trợ: mở từ danh sách → modal 2xl (htmx); mở thẳng URL → trang riêng. --}}
@if ($asModal)
    <x-ui.modal-frame title="Tạo yêu cầu hỗ trợ (Ticket)" description="Mô tả sự cố / yêu cầu; có thể đính kèm ảnh chụp màn hình (dán Ctrl + V).">
        @include('support-tickets._form')
        <x-slot:footer>
            <x-ui.button type="submit" form="modal-ticket-form" icon="send">Tạo &amp; Gửi Ticket</x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('tickets.index') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">add_task</span>
                    Tạo Yêu Cầu Hỗ Trợ (Ticket)
                </h1>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto">
        @include('support-tickets._form')
    </div>
</x-app-layout>
@endif
