<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('roles.index') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">admin_panel_settings</span>
                    {{ $role->exists ? 'Chỉnh Sửa Vai Trò & Phân Quyền' : 'Tạo Vai Trò & Thiết Lập Quyền Hạn' }}
                </h1>
                <p class="text-xs text-gray-500">Cấu hình danh sách quyền hạn chi tiết cho vai trò</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto space-y-6">
        <form method="POST" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}" class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-6">
            @csrf
            @if ($role->exists) @method('PUT') @endif

            <div class="max-w-md">
                <label for="name" class="block text-xs font-bold text-gray-700 mb-1">Tên định danh vai trò (Role Name) <span class="text-rose-500">*</span></label>
                <input id="name" name="name" type="text" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold text-primary" value="{{ old('name', $role->name) }}" required placeholder="Ví dụ: manager, sales_lead, academic_officer" />
                @if ($role->exists)
                    <div class="text-[11px] text-gray-400 mt-1">Tên Tiếng Việt: <strong>{{ \App\Helpers\AclHelper::roleLabel($role->name) }}</strong></div>
                @endif
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <div>
                <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
                    <label class="font-bold text-sm text-gray-900">Danh mục quyền hạn hệ thống</label>
                    <span class="text-xs text-gray-400">Chọn các quyền cho phép vai trò này thao tác</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach ($permissionsByModule as $module => $permissions)
                        <div class="border border-gray-200 rounded-2xl p-4 bg-gray-50/50 hover:bg-white hover:border-primary-container transition space-y-3">
                            <div class="font-bold text-xs text-gray-900 border-b border-gray-100 pb-2 flex items-center justify-between">
                                <span>{{ \App\Helpers\AclHelper::moduleLabel($module) }}</span>
                                <span class="font-mono text-[10px] text-gray-400 font-normal">({{ $module }})</span>
                            </div>
                            <div class="space-y-2">
                                @foreach ($permissions as $permission)
                                    <label class="flex items-start gap-2.5 text-xs text-gray-700 hover:text-gray-900 cursor-pointer p-1 rounded-lg hover:bg-gray-100 transition">
                                        <input type="checkbox" name="permissions[]" value="{{ $permission->name }}"
                                            @checked(in_array($permission->name, old('permissions', $selected)))
                                            class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary-container">
                                        <div>
                                            <div class="font-semibold text-gray-900">{{ \App\Helpers\AclHelper::actionLabel($permission->name) }}</div>
                                            <div class="font-mono text-[10px] text-gray-400">{{ $permission->name }}</div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="pt-4 border-t border-gray-100 flex items-center justify-end gap-3">
                <a href="{{ route('roles.index') }}" class="px-4 py-2 rounded-xl border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition">Hủy</a>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-sm transition">
                    Lưu cấu hình Vai trò
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
