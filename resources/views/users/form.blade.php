{{-- Thêm / Sửa nhân sự: mở từ danh sách → modal 3xl (htmx), 3 tab Tài khoản / Hồ sơ / Hợp đồng & Lương trong 1 form;
     mở thẳng URL → trang đầy đủ (cùng form). Lưu xong "users-changed" làm mới danh sách nhân sự. --}}
@php $title = $user->exists ? 'Sửa thông tin nhân sự' : 'Thêm nhân viên mới'; @endphp
@if ($asModal)
    <x-ui.modal-frame :title="$title" :description="$user->exists ? $user->name.' · '.$user->email : 'Tài khoản mới phải đổi mật khẩu ở lần đăng nhập đầu tiên.'">
        @include('users._form')
        <x-slot:footer>
            <x-ui.button type="submit" form="modal-user-form" icon="save">{{ $user->exists ? 'Cập nhật tài khoản' : 'Lưu tài khoản' }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
    <x-app-layout :title="$title">
        <x-ui.page-header :title="$title">
            <x-slot:breadcrumbs>
                <a href="{{ route('users.index') }}" class="inline-flex items-center gap-xs hover:text-primary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">group</span>Nhân sự</a>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span>{{ $user->exists ? 'Sửa thông tin' : 'Thêm mới' }}</span>
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        <div class="mx-auto max-w-3xl rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
            @include('users._form')
        </div>
    </x-app-layout>
@endif
