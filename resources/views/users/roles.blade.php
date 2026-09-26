<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <nav class="flex items-center gap-1.5 text-gray-400 text-xs mb-1">
                    <a href="{{ route('users.index') }}" class="hover:text-gray-900 transition flex items-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">group</span>
                        <span>Nhân sự</span>
                    </a>
                    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                    <span class="text-primary-container font-semibold">Gán vai trò chức vụ</span>
                </nav>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span>Gán vai trò &amp; Chức vụ — {{ $user->name }}</span>
                </h1>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('users.permissions.edit', $user) }}" class="px-4 py-2.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold shadow-2xs transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[18px]">tune</span>
                    <span>Phân quyền chi tiết</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <!-- Left Column: Employee Info (Col 4) -->
            <div class="lg:col-span-4 bg-white border border-gray-200 rounded-2xl p-6 shadow-sm text-center space-y-4">
                <div class="flex flex-col items-center">
                    <div class="w-20 h-20 rounded-full bg-orange-100 text-primary-container border-4 border-orange-200/60 flex items-center justify-center font-bold text-3xl mb-3 shadow-xs">
                        {{ Str::substr($user->name, 0, 1) }}
                    </div>
                    <h2 class="text-lg font-bold text-gray-900">{{ $user->name }}</h2>
                    <p class="text-xs text-gray-500">{{ $user->branch?->name ?? 'Chưa gán chi nhánh' }}</p>
                    <p class="text-[11px] text-gray-400 font-mono mt-0.5">{{ $user->email }}</p>
                </div>

                <div class="border-t border-gray-100 pt-4 text-left text-xs space-y-2.5">
                    <div class="flex justify-between py-1 border-b border-gray-50">
                        <span class="text-gray-400 font-bold uppercase text-[10px]">Mã NV:</span>
                        <span class="font-mono font-bold text-gray-800">{{ $user->employee_code ?? ('NV-' . str_pad($user->id, 4, '0', STR_PAD_LEFT)) }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-gray-50">
                        <span class="text-gray-400 font-bold uppercase text-[10px]">Số điện thoại:</span>
                        <span class="font-mono font-bold text-gray-800">{{ $user->phone ?? 'Chưa cập nhật' }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-gray-50">
                        <span class="text-gray-400 font-bold uppercase text-[10px]">Số vai trò hiện tại:</span>
                        <span class="font-bold text-primary-container">{{ $user->roles->count() }} vai trò</span>
                    </div>
                </div>
            </div>

            <!-- Right Column: Roles Checkboxes (Col 8) -->
            <div class="lg:col-span-8 bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-5">
                <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                    <div>
                        <h3 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary-container text-[18px]">badge</span>
                            <span>Danh sách các vai trò chức vụ trong hệ thống</span>
                        </h3>
                        <p class="text-[11px] text-gray-500 mt-0.5">Chọn một hoặc nhiều vai trò để gán quyền tương ứng cho nhân sự này</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('users.roles.update', $user) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div class="space-y-3">
                        @foreach ($roles as $role)
                            <label class="flex items-center justify-between p-4 rounded-2xl border border-gray-200 hover:border-primary-container hover:bg-orange-50/20 cursor-pointer transition shadow-2xs group">
                                <div class="flex items-center gap-3.5">
                                    <input type="checkbox" name="roles[]" value="{{ $role->name }}"
                                        @checked($user->roles->contains('name', $role->name))
                                        class="w-4 h-4 rounded border-gray-300 text-primary-container focus:ring-primary-container">
                                    <div>
                                        <div class="font-bold text-sm text-gray-900 group-hover:text-primary-container transition">{{ \App\Helpers\AclHelper::roleLabel($role->name) }}</div>
                                        <div class="font-mono text-[11px] text-gray-400 font-normal">Mã hệ thống: {{ $role->name }}</div>
                                    </div>
                                </div>
                                <span class="px-2.5 py-1 rounded-full bg-gray-100 text-gray-700 font-mono text-[11px] font-bold border border-gray-200">
                                    {{ $role->permissions->count() }} quyền hạn
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('roles')" class="mt-1 text-xs" />

                    <div class="pt-4 border-t border-gray-100 flex items-center justify-end gap-3">
                        <a href="{{ route('users.index') }}" class="px-4 py-2.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold transition">Hủy</a>
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-primary-container hover:bg-primary text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                            <span class="material-symbols-outlined text-[18px]">save</span>
                            <span>Lưu thay đổi vai trò</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
