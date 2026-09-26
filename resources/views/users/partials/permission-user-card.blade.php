{{-- Thẻ nhân sự đầu màn phân quyền cá nhân. Biến: $user (branch, roles), $asModal. --}}
@php
    $asModal = $asModal ?? false;
@endphp
<div class="mb-md flex flex-wrap items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
    <x-ui.avatar :name="$user->name" />
    <div class="min-w-0 flex-1">
        <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ $user->name }} <span class="font-code text-caption text-on-surface-variant">· {{ $user->employee_code ?: 'NV-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT) }}</span></p>
        <p class="font-body-small text-body-small text-on-surface-variant">{{ $user->branch?->name ?? 'Chưa gán chi nhánh' }} · {{ $user->email }}</p>
    </div>
    <div class="flex flex-wrap gap-xs">
        @foreach ($user->roles as $i => $role)
            <x-ui.badge :color="$i === 0 ? 'primary' : 'neutral'" :dot="false">{{ \App\Helpers\AclHelper::shortRoleLabel($role->name) }}{{ $i > 0 ? ' (kiêm nhiệm)' : '' }}</x-ui.badge>
        @endforeach
    </div>
    @can('user.assign_role')
        {{-- Trang: mở modal gán vai trò (lưu xong tải lại trang để ma trận theo vai trò mới); trong modal: link được hx-boost → đổi nội dung modal --}}
    <x-ui.button size="sm" variant="ghost" icon="tune" :href="route('users.roles.edit', $user)" :modal="$asModal ? null : 'md'">Vai trò</x-ui.button>
    @endcan
</div>
