{{-- Thêm/Sửa ngày nghỉ: mở từ danh sách → modal (htmx); mở thẳng URL → trang danh sách + form bên phải. --}}
@if ($asModal)
    <x-ui.modal-frame :title="$holiday->exists ? 'Sửa ngày nghỉ' : 'Thêm ngày nghỉ'"
                      description="Ngày nghỉ dùng để sinh lịch học, hủy buổi trùng và xếp buổi học bù.">
        @include('holidays._form')
        <x-slot:footer>
            <x-ui.button type="submit" form="modal-holiday-form">Lưu thông tin</x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
    @include('holidays.index')
@endif
