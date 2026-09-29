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
    <x-ui.page-header title="Tạo yêu cầu hỗ trợ" icon="add_task" :back="route('tickets.index')" />

    <div class="max-w-3xl">
        @include('support-tickets._form')
    </div>
</x-app-layout>
@endif
