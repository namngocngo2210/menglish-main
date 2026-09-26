{{--
    Danh mục quyền hạn: Sửa mở modal (htmx), Xóa qua modal xác nhận.
    Lưu/xóa xong server phát "permissions-changed" → #permission-list tự tải lại (giữ trang hiện tại).
--}}
<x-app-layout title="Danh mục quyền">
    <x-ui.page-header title="Danh mục quyền hạn hệ thống" description="Mỗi quyền có dạng module.action, được gán cho vai trò ở màn Vai trò & phân quyền.">
        <x-slot:actions>
            @can('role.view')
                <x-ui.button variant="secondary" icon="admin_panel_settings" :href="route('roles.index')">Vai trò</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div x-data="{ del: { url: '', name: '' } }">
        <div id="permission-list" hx-get="{{ route('permissions.index', request()->query()) }}" hx-trigger="permissions-changed from:body" hx-select="#permission-list" hx-swap="outerHTML">
            <x-ui.data-table min-width="760px">
                <table>
                    <thead>
                        <tr>
                            <th>Tên quyền hạn</th>
                            <th>Mã phân quyền</th>
                            <th>Phân hệ chức năng</th>
                            <th class="text-center">Số vai trò áp dụng</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($permissions as $permission)
                            <tr>
                                <td class="font-semibold text-on-surface">{{ \App\Helpers\AclHelper::actionLabel($permission->name) }}</td>
                                <td class="font-code text-primary">{{ $permission->name }}</td>
                                <td>{{ \App\Helpers\AclHelper::moduleLabel(explode('.', $permission->name)[0]) }}</td>
                                <td class="text-center"><x-ui.badge color="neutral" :dot="false">{{ $permission->roles_count }} vai trò</x-ui.badge></td>
                                <td class="whitespace-nowrap text-right">
                                    @can('permission.update')
                                        <x-ui.button size="sm" variant="ghost" icon="edit" :href="route('permissions.edit', $permission)" modal="sm" title="Sửa" aria-label="Sửa {{ $permission->name }}" />
                                    @endcan
                                    @can('permission.delete')
                                        <x-ui.button size="sm" variant="danger-text" icon="delete" title="Xóa" aria-label="Xóa {{ $permission->name }}"
                                                     data-url="{{ route('permissions.destroy', $permission) }}" data-name="{{ $permission->name }}"
                                                     @click="del = { url: $el.dataset.url, name: $el.dataset.name }; $dispatch('open-modal', 'delete-permission')" />
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$permissions" unit="quyền" /></x-slot:footer>
            </x-ui.data-table>
        </div>

        @can('permission.delete')
            <x-ui.modal name="delete-permission" title="Xóa quyền?" max-width="md">
                <p>Xóa quyền <strong class="font-code font-semibold" x-text="del.name"></strong>?</p>
                <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Chỉ xóa được quyền chưa gán cho vai trò nào.</p>
                <form id="delete-permission-form" method="POST" :action="del.url" hx-boost="true" hx-swap="none" hx-push-url="false">
                    @csrf @method('DELETE')
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'delete-permission')">Hủy</x-ui.button>
                    <x-ui.button variant="danger" type="submit" form="delete-permission-form" icon="delete">Xóa</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endcan
    </div>
</x-app-layout>
