<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">admin_panel_settings</span>
                    Quản Lý Danh Sách Vai Trò (Roles &amp; Permissions)
                </h1>
                <p class="text-xs text-gray-500">Phân quyền chức năng theo từng phòng ban: Quản trị, Kế toán, Học vụ, Tuyển sinh, Giáo viên</p>
            </div>
            @can('role.create')
                <div class="flex items-center gap-2">
                    <a href="{{ route('roles.create') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-semibold shadow-sm transition">
                        <span class="material-symbols-outlined text-[18px]">add_circle</span>
                        <span>Thêm Vai Trò Mới</span>
                    </a>
                </div>
            @endcan
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto space-y-4">

        @if ($errors->any())
            <div class="p-4 rounded-2xl bg-rose-50 text-rose-800 border border-rose-200 text-xs font-semibold flex items-center gap-2">
                <span class="material-symbols-outlined text-rose-600 text-base">error</span>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4">Tên Vai Trò (Tiếng Việt)</th>
                            <th class="py-3 px-4">Mã định danh</th>
                            <th class="py-3 px-4 text-center">Số quyền hạn (Permissions)</th>
                            <th class="py-3 px-4 text-center">Số nhân sự đảm nhiệm</th>
                            <th class="py-3 px-4 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @foreach ($roles as $role)
                            <tr class="hover:bg-purple-50/10 transition">
                                <td class="py-3.5 px-4 font-bold text-gray-900 text-sm">
                                    {{ \App\Helpers\AclHelper::roleLabel($role->name) }}
                                </td>
                                <td class="py-3.5 px-4 font-mono text-gray-500 font-bold">
                                    {{ $role->name }}
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <span class="px-2.5 py-0.5 rounded-full bg-indigo-50 text-indigo-700 font-mono font-bold text-xs">
                                        {{ $role->permissions_count }} quyền
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <span class="px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-mono font-bold text-xs">
                                        {{ $role->users_count }} nhân sự
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('role.update')
                                            <a href="{{ route('roles.edit', $role) }}" class="px-2.5 py-1 rounded-lg bg-orange-50 hover:bg-orange-100 text-primary font-bold text-xs transition inline-flex items-center gap-1">
                                                <span class="material-symbols-outlined text-[14px]">edit</span>
                                                <span>Cấu hình quyền</span>
                                            </a>
                                        @endcan
                                        @can('role.delete')
                                            <form action="{{ route('roles.destroy', $role) }}" method="POST" class="inline" onsubmit="return confirm('Bạn có chắc muốn xóa vai trò này?');">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="p-1 rounded-lg hover:bg-rose-50 text-gray-400 hover:text-rose-600 transition" title="Xóa vai trò">
                                                    <span class="material-symbols-outlined text-[16px]">delete</span>
                                                </button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$roles" />
        </div>
    </div>
</x-app-layout>
