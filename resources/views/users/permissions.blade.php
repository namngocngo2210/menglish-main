{{-- Mở từ danh sách nhân sự → modal 4xl (htmx); mở thẳng URL → trang đầy đủ (kèm thẻ số liệu).
     Phân quyền cá nhân (mockup epic-5/phan-quyen-chi-tiet-ca-nhan; RBAC — docs/rbac.md): cùng ma trận với màn Vai trò.
     Mỗi quyền: theo vai trò / cấp thêm / thu hồi (checkbox); mỗi module: phạm vi dữ liệu theo vai trò hoặc riêng người này;
     Lớp học còn "Phạm vi áp dụng" theo chi nhánh / lớp cụ thể. --}}
@php $targetIsSuperAdmin = $user->isSuperAdmin(); @endphp
@if ($asModal)
    <x-ui.modal-frame :title="'Phân quyền chi tiết — '.$user->name" description="Phân quyền cá nhân thắng quyền theo vai trò: &quot;Thu hồi&quot; chặn quyền vai trò đang cấp, &quot;Cấp thêm&quot; mở quyền vai trò không có. Mọi thay đổi được ghi nhật ký.">
        @if ($targetIsSuperAdmin)
            <x-ui.alert type="warning" class="mb-md">Tài khoản Super Admin luôn có toàn quyền thao tác (phân quyền cá nhân không thu hẹp được) — chỉ các quyền "đối tượng" có tác dụng.</x-ui.alert>
        @endif
        @if ($errors->any())
            <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
        @endif
        @include('users.partials.permission-user-card')
        @include('users._permissions-form')
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('perm-reset')">Đặt lại mặc định</x-ui.button>
            <x-ui.button type="submit" form="modal-permission-override-form" icon="save">Lưu phân quyền</x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
<x-app-layout title="Phân quyền cá nhân">
    {{-- Đổi vai trò (modal) xong → tải lại trang: quyền kế thừa theo vai trò thay đổi nên ma trận phải tính lại --}}
    <div x-data x-on:users-changed.window="window.location.reload()" hidden></div>
    <x-ui.page-header :title="'Cấu hình quyền chi tiết — '.$user->name">
        <x-slot:breadcrumbs>
            <a href="{{ route('users.index') }}" class="inline-flex items-center gap-xs hover:text-primary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">home</span>Nhân sự</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span>Phân quyền cá nhân</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @can('activity_log.view')
                <x-ui.button variant="secondary" icon="history" :href="route('activity-logs.index', ['search' => $user->name, 'log_name' => 'Người dùng & Phân quyền'])">Xem nhật ký</x-ui.button>
            @endcan
            <x-ui.button type="submit" form="permissionOverrideForm" icon="save">Lưu thay đổi</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert type="info" title="Ghi chú bảo mật quan trọng" class="mb-md">
        Mọi thay đổi về phân quyền sẽ được hệ thống tự động ghi lại vào Nhật ký vận hành bao gồm: Người thực hiện, Thời gian, và Nội dung thay đổi chi tiết (trước / sau). Phân quyền cá nhân thắng quyền theo vai trò: "Thu hồi" chặn quyền vai trò đang cấp, "Cấp thêm" mở quyền vai trò không có.
    </x-ui.alert>
    @if ($targetIsSuperAdmin)
        <x-ui.alert type="warning" class="mb-md">Tài khoản Super Admin luôn có toàn quyền thao tác (phân quyền cá nhân không thu hẹp được) — chỉ các quyền "đối tượng" có tác dụng.</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif

    @include('users.partials.permission-user-card')

    @include('users._permissions-form')


    <div class="mt-md grid grid-cols-1 gap-md sm:grid-cols-3">
        <x-ui.stat-card label="Tổng số Module" :value="str_pad((string) $moduleCount, 2, '0', STR_PAD_LEFT).' danh mục'" icon="apps" />
        <x-ui.stat-card label="Quyền truy cập" :value="$effectiveCount.' thao tác cho phép'" icon="verified_user" tone="primary" />
        <x-ui.stat-card label="Phạm vi dữ liệu" :value="str_pad((string) $scopeUnitCount, 2, '0', STR_PAD_LEFT).' đơn vị quản lý'" icon="domain" tone="secondary"
                        :hint="$scopeUnitCount === 0 ? 'Toàn hệ thống theo vai trò' : null" />
    </div>
</x-app-layout>
@endif
