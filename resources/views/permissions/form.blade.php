{{-- Sửa permission: mở từ danh sách → modal (htmx); mở thẳng URL → trang form đầy đủ. --}}
@php $title = $permission->exists ? 'Sửa permission' : 'Thêm permission'; @endphp
@if ($asModal)
    <x-ui.modal-frame :title="$title" description="Đổi mã permission ảnh hưởng mọi vai trò đang dùng quyền này.">
        @include('permissions._form')
        <x-slot:footer>
            <x-ui.button type="submit" form="modal-permission-form" icon="save">Lưu permission</x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
    <x-app-layout :title="$title">
        <x-ui.page-header :title="$title">
            <x-slot:breadcrumbs>
                <a href="{{ route('permissions.index') }}" class="hover:text-primary">Danh mục quyền</a>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span>{{ $title }}</span>
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        <div class="mx-auto max-w-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
            @include('permissions._form')
        </div>
    </x-app-layout>
@endif
