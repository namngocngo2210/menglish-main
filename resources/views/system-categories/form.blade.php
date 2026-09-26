{{-- Thêm/Sửa danh mục: mở từ danh sách → modal (htmx); mở thẳng URL create → trang thêm riêng (sửa: panel ở trang danh sách). --}}
@if ($asModal)
    <x-ui.modal-frame :title="$category->exists ? 'Sửa giá trị danh mục' : 'Thêm danh mục mới'"
                      description="Cấu hình các tham số nền tảng của hệ thống MENGLISH.">
        @include('system-categories._form')
        <x-slot:footer>
            <x-ui.button type="submit" form="modal-category-form" icon="save">Lưu thông tin</x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
    <x-app-layout title="Thêm danh mục mới">
        <x-ui.page-header title="Thêm danh mục mới" description="Cấu hình các tham số nền tảng của hệ thống MENGLISH." />

        <div class="mx-auto max-w-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
            @include('system-categories._form')
        </div>
    </x-app-layout>
@endif
