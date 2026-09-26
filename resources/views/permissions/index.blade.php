<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-indigo-600">key</span>
                    Danh mục quyền hạn hệ thống
                </h1>
            </div>
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
                            <th class="py-3 px-4">Tên Quyền Hạn (Tiếng Việt)</th>
                            <th class="py-3 px-4">Mã phân quyền (Slug)</th>
                            <th class="py-3 px-4">Phân hệ chức năng</th>
                            <th class="py-3 px-4 text-center">Số vai trò áp dụng</th>
                            <th class="py-3 px-4 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @foreach ($permissions as $permission)
                            @php
                                $module = explode('.', $permission->name)[0];
                            @endphp
                            <tr class="hover:bg-purple-50/10 transition">
                                <td class="py-3.5 px-4 font-bold text-gray-900 text-sm">
                                    {{ \App\Helpers\AclHelper::actionLabel($permission->name) }}
                                </td>
                                <td class="py-3.5 px-4 font-mono text-primary font-bold">
                                    {{ $permission->name }}
                                </td>
                                <td class="py-3.5 px-4 font-semibold text-gray-800">
                                    {{ \App\Helpers\AclHelper::moduleLabel($module) }}
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <span class="px-2.5 py-0.5 rounded-full bg-gray-100 text-gray-700 font-mono font-bold text-xs">
                                        {{ $permission->roles_count }} vai trò
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('permission.update')
                                            <a href="{{ route('permissions.edit', $permission) }}" class="p-1 rounded-lg text-gray-500 hover:text-primary transition" title="Sửa">
                                                <span class="material-symbols-outlined text-[16px]">edit</span>
                                            </a>
                                        @endcan
                                        @can('permission.delete')
                                            <form action="{{ route('permissions.destroy', $permission) }}" method="POST" class="inline" onsubmit="return confirm('Bạn có chắc muốn xóa quyền này?');">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="p-1 rounded-lg text-gray-400 hover:text-rose-600 transition" title="Xóa">
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
            <x-pagination :paginator="$permissions" />
        </div>
    </div>
</x-app-layout>
