{{-- Danh sách vai trò (RBAC linh hoạt — docs/rbac.md): tạo, nhân bản, cấu hình quyền, xóa (chỉ khi chưa gán cho ai). --}}
<x-app-layout title="Vai trò">
    <x-ui.page-header title="Vai trò & phân quyền"
                      description="Mỗi vai trò là một tập quyền theo module + phạm vi dữ liệu. Nhân sự kiêm nhiệm nhiều vai trò được cộng dồn quyền; Phân quyền cá nhân cho phép / chặn riêng từng người.">
        <x-slot:actions>
            @can('permission.view')
                <x-ui.button variant="secondary" icon="security" :href="route('permissions.index')">Danh mục quyền</x-ui.button>
            @endcan
            @can('role.create')
                <x-ui.button icon="add_circle" :href="route('roles.create')">Thêm vai trò mới</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif

    <x-ui.data-table min-width="860px">
        <table>
            <thead>
                <tr>
                    <th>Tên vai trò</th>
                    <th>Mã định danh</th>
                    <th class="text-center">Số quyền</th>
                    <th class="text-center">Số nhân sự</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($roles as $role)
                    @php $isSuperAdmin = $role->name === \App\Support\Rbac::SUPER_ADMIN; @endphp
                    <tr>
                        <td>
                            <div class="font-semibold text-on-surface">{{ \App\Helpers\AclHelper::roleLabel($role->name) }}</div>
                            @if ($role->description)
                                <div class="font-caption text-caption text-on-surface-variant">{{ $role->description }}</div>
                            @endif
                            @if ($isSuperAdmin)
                                <x-ui.badge color="primary" :dot="false">Super Admin · bất biến</x-ui.badge>
                            @elseif (! \App\Helpers\AclHelper::isSystemRole($role->name))
                                <x-ui.badge color="info" :dot="false">Tự tạo</x-ui.badge>
                            @endif
                        </td>
                        <td class="font-code text-on-surface-variant">{{ $role->name }}</td>
                        <td class="text-center">{{ $isSuperAdmin ? 'Toàn quyền' : $role->permissions_count.' quyền' }}</td>
                        <td class="text-center">{{ $role->users_count }} nhân sự</td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-xs whitespace-nowrap">
                                @can('role.update')
                                    <x-ui.button size="sm" variant="secondary" icon="tune" :href="route('roles.edit', $role)">{{ $isSuperAdmin ? 'Xem quyền' : 'Cấu hình quyền' }}</x-ui.button>
                                @endcan
                                @can('role.create')
                                    <form action="{{ route('roles.duplicate', $role) }}" method="POST" class="inline">
                                        @csrf
                                        <x-ui.button size="sm" variant="ghost" icon="content_copy" type="submit" title="Nhân bản vai trò">Nhân bản</x-ui.button>
                                    </form>
                                @endcan
                                @can('role.delete')
                                    @if (! $isSuperAdmin && $role->users_count === 0)
                                        <form action="{{ route('roles.destroy', $role) }}" method="POST" class="inline" onsubmit="return confirm('Xóa vai trò này?');">
                                            @csrf @method('DELETE')
                                            <x-ui.button size="sm" variant="ghost" icon="delete" type="submit" title="Xóa vai trò">Xóa</x-ui.button>
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <x-slot:footer><x-ui.pagination :paginator="$roles" /></x-slot:footer>
    </x-ui.data-table>
</x-app-layout>
