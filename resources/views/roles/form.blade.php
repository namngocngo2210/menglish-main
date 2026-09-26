{{-- Màn Vai trò (RBAC linh hoạt — docs/rbac.md): tên / mã / mô tả + ma trận quyền theo module
     (Xem / Thêm / Sửa / Xóa / Duyệt + thao tác khác + phạm vi dữ liệu).
     Mở từ danh sách → modal (htmx) chỉ gồm tên / mã / mô tả; mở thẳng URL → trang đầy đủ kèm ma trận quyền. --}}
@if ($asModal)
    <x-ui.modal-frame :title="$role->exists ? 'Đổi tên vai trò' : 'Thêm vai trò mới'"
                      description="Tên hiển thị, mã và mô tả. Quyền của vai trò cấu hình ở màn “Cấu hình quyền”.">
        @if ($isSuperAdmin)
            <x-ui.alert type="info" class="mb-md">Vai trò Super Admin bất biến: chỉ đổi được tên hiển thị và mô tả.</x-ui.alert>
        @endif
        <form id="modal-role-form" method="POST" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}" class="space-y-md">
            @csrf
            @if ($role->exists) @method('PUT') @endif
            @include('roles._fields')
        </form>
        @unless ($role->exists)
            <p class="mt-md font-body-small text-body-small text-on-surface-variant">Vai trò mới chưa có quyền nào — bấm “Cấu hình quyền” ở danh sách sau khi tạo.</p>
        @endunless
        <x-slot:footer>
            <x-ui.button type="submit" form="modal-role-form" icon="save">Lưu vai trò</x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
@php
    $roleName = $role->exists ? \App\Helpers\AclHelper::shortRoleLabel($role->name) : null;
    $readonly = $isSuperAdmin || ! $canAssignPermissions;
    $selectedNames = old('permissions', $selected);
@endphp
<x-app-layout :title="$role->exists ? 'Cấu hình vai trò' : 'Tạo vai trò'">
    <x-ui.page-header :title="$role->exists ? 'Cấu hình vai trò — '.$roleName : 'Tạo vai trò mới'"
                      description="Bật / tắt từng quyền theo module và chọn phạm vi dữ liệu. Thay đổi có hiệu lực ngay, được ghi vào Nhật ký vận hành.">
        <x-slot:breadcrumbs>
            <a href="{{ route('roles.index') }}" class="inline-flex items-center gap-xs hover:text-primary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">admin_panel_settings</span>Vai trò</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span>{{ $role->exists ? $roleName : 'Tạo mới' }}</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow_back" :href="route('roles.index')">Quay lại</x-ui.button>
            <x-ui.button type="submit" form="roleForm" icon="save">Lưu vai trò</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif
    @if ($isSuperAdmin)
        <x-ui.alert type="info" title="Vai trò Super Admin bất biến" class="mb-md" data-testid="super-admin-immutable">
            Super Admin luôn có toàn quyền thao tác và phạm vi "Toàn hệ thống" (không thu hồi được quyền, không xóa được vai trò).
            Chỉ đổi được tên hiển thị và mô tả. Quyền "đối tượng" (cổng học viên / giáo viên…) không áp dụng cho Super Admin.
        </x-ui.alert>
    @elseif (! $canAssignPermissions)
        <x-ui.alert type="warning" class="mb-md">Bạn chỉ xem được ma trận quyền (cần quyền "Phân quyền cho vai trò" để thay đổi).</x-ui.alert>
    @endif

    <form id="roleForm" method="POST" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}" class="space-y-md">
        @csrf
        @if ($role->exists) @method('PUT') @endif

        <div class="grid grid-cols-1 gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md md:grid-cols-3">
            @include('roles._fields', ['asModal' => false])
        </div>

        @include('roles.partials.matrix', ['groups' => $groups, 'selected' => $selectedNames, 'readonly' => $readonly, 'superAdmin' => $isSuperAdmin])

        <div class="flex items-center justify-end gap-sm">
            <x-ui.button variant="secondary" :href="route('roles.index')">Hủy</x-ui.button>
            <x-ui.button type="submit" icon="save">Lưu vai trò</x-ui.button>
        </div>
    </form>
</x-app-layout>
@endif
