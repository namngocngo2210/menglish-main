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
                    <span class="text-primary-container font-semibold">Phân quyền cá nhân</span>
                </nav>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span>Cấu hình quyền chi tiết — {{ $user->name }}</span>
                </h1>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('activity-logs.index') }}" class="px-4 py-2.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold shadow-2xs transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[18px]">history</span>
                    <span>Xem nhật ký</span>
                </a>
                <button type="submit" form="permissionOverrideForm" class="px-5 py-2.5 rounded-xl bg-primary-container hover:bg-primary text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[18px]">save</span>
                    <span>Lưu thay đổi</span>
                </button>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6" x-data="{
        setAll(status) {
            document.querySelectorAll('.permission-select').forEach(sel => {
                sel.value = status;
                sel.dispatchEvent(new Event('change'));
            });
        }
    }">

        @if (session('status'))
            <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold">{{ session('status') }}</div>
        @endif

        <!-- Security Banner -->
        <div class="p-4 bg-indigo-50/70 border-l-4 border-indigo-600 rounded-r-2xl flex items-start gap-3 text-xs shadow-2xs">
            <span class="material-symbols-outlined text-indigo-600 text-xl shrink-0 mt-0.5">security</span>
            <div class="space-y-0.5">
                <p class="font-bold text-indigo-950">Ghi chú bảo mật quan trọng:</p>
                <p class="text-indigo-800">
                    Mọi thay đổi phân quyền cá nhân sẽ được hệ thống tự động ghi nhận vào <strong>Audit Log (Nhật ký vận hành)</strong> bao gồm: Người thực hiện, thời gian và chi tiết quyền hạn được cấp thêm/thu hồi.
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- Left Sidebar: Employee Info Card (Col 4) -->
            <aside class="lg:col-span-4 space-y-6">
                <!-- Employee Summary Card -->
                <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm text-center space-y-5">
                    <div class="flex flex-col items-center">
                        <div class="w-20 h-20 rounded-full bg-orange-100 text-primary-container border-4 border-orange-200/60 flex items-center justify-center font-bold text-3xl mb-3 shadow-xs">
                            {{ Str::substr($user->name, 0, 1) }}
                        </div>
                        <h2 class="text-lg font-bold text-gray-900">{{ $user->name }}</h2>
                        <div class="flex flex-wrap gap-1 justify-center mt-1">
                            @foreach ($user->roles as $role)
                                <span class="px-3 py-1 rounded-full bg-orange-50 text-primary-container border border-orange-200 text-xs font-bold">
                                    {{ \App\Helpers\AclHelper::roleLabel($role->name) }}
                                </span>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-3 border-t border-gray-100 pt-4 text-left text-xs">
                        <div class="flex items-center gap-3 p-2 bg-gray-50 rounded-xl">
                            <span class="material-symbols-outlined text-gray-500 text-[18px]">apartment</span>
                            <div>
                                <p class="text-[10px] text-gray-400 font-bold uppercase">Cơ sở trực thuộc</p>
                                <p class="font-semibold text-gray-900">{{ $user->branch?->name ?? 'Chưa gán chi nhánh' }}</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 p-2 bg-gray-50 rounded-xl">
                            <span class="material-symbols-outlined text-gray-500 text-[18px]">mail</span>
                            <div class="overflow-hidden">
                                <p class="text-[10px] text-gray-400 font-bold uppercase">Email hệ thống</p>
                                <p class="font-semibold text-gray-900 truncate">{{ $user->email }}</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 p-2 bg-gray-50 rounded-xl">
                            <span class="material-symbols-outlined text-gray-500 text-[18px]">call</span>
                            <div>
                                <p class="text-[10px] text-gray-400 font-bold uppercase">Số điện thoại</p>
                                <p class="font-semibold text-gray-900 font-mono">{{ $user->phone ?? 'Chưa cập nhật' }}</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 p-2 bg-gray-50 rounded-xl">
                            <span class="material-symbols-outlined text-gray-500 text-[18px]">badge</span>
                            <div>
                                <p class="text-[10px] text-gray-400 font-bold uppercase">Mã nhân viên</p>
                                <p class="font-semibold text-gray-900 font-mono">{{ $user->employee_code ?? ('NV-' . str_pad($user->id, 4, '0', STR_PAD_LEFT)) }}</p>
                            </div>
                        </div>
                    </div>

                    <a href="{{ route('users.roles.edit', $user) }}" class="w-full py-2 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 border border-indigo-200 rounded-xl text-xs font-semibold flex items-center justify-center gap-1.5 transition">
                        <span class="material-symbols-outlined text-[16px]">tune</span>
                        Thay đổi vai trò chính
                    </a>
                </div>

                <!-- Quick Help -->
                <div class="bg-gray-50 rounded-2xl p-5 border border-gray-200 space-y-3 text-xs">
                    <h3 class="font-bold text-gray-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary-container text-[18px]">lightbulb</span>
                        Hướng dẫn phân quyền
                    </h3>
                    <p class="text-gray-600 leading-relaxed">
                        Tùy chỉnh quyền hạn riêng cho nhân sự này vượt ngoài vai trò mặc định:
                    </p>
                    <ul class="space-y-2 text-gray-700">
                        <li class="flex items-start gap-2">
                            <span class="w-2 h-2 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                            <span><strong>Kế thừa:</strong> Sử dụng quyền theo vai trò chức vụ.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 mt-1.5 shrink-0"></span>
                            <span><strong>🟢 Cho phép:</strong> Đặc cách cấp thêm quyền cho cá nhân.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="w-2 h-2 rounded-full bg-rose-500 mt-1.5 shrink-0"></span>
                            <span><strong>🔴 Từ chối:</strong> Chặn/Thu hồi quyền cụ thể của nhân sự này.</span>
                        </li>
                    </ul>
                </div>
            </aside>

            <!-- Right Main Area: Permissions Matrix (Col 8) -->
            <section class="lg:col-span-8 space-y-6">
                <form id="permissionOverrideForm" method="POST" action="{{ route('users.permissions.update', $user) }}">
                    @csrf
                    @method('PUT')

                    @if ($errors->any())
                        <div class="mb-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs space-y-0.5">
                            @foreach ($errors->all() as $error)
                                <p>{{ $error }}</p>
                            @endforeach
                        </div>
                    @endif

                    <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                        <div class="p-4 border-b border-gray-200 bg-gray-50/70 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div>
                                <h3 class="font-bold text-sm text-gray-900 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-indigo-600 text-[18px]">grid_view</span>
                                    <span>Ma trận phân quyền chi tiết</span>
                                </h3>
                                <p class="text-[11px] text-gray-500 mt-0.5">Mỗi ô: <strong>—</strong> kế thừa vai trò · <strong class="text-emerald-700">✓</strong> cấp thêm · <strong class="text-rose-700">✕</strong> thu hồi. Bỏ trống phạm vi = Toàn hệ thống.</p>
                            </div>
                            <div class="flex items-center gap-2 text-xs">
                                <span class="text-gray-400 font-bold uppercase text-[10px]">Chọn nhanh:</span>
                                <button type="button" @click="setAll('allow')" class="px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold hover:bg-emerald-100 transition">
                                    Chọn tất cả
                                </button>
                                <button type="button" @click="setAll('inherit')" class="px-2.5 py-1 rounded-lg bg-gray-100 text-gray-700 border border-gray-200 font-bold hover:bg-gray-200 transition">
                                    Bỏ chọn
                                </button>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                                        <th class="py-3 px-4">Danh mục module</th>
                                        @foreach (\App\Models\UserPermissionOverride::MATRIX_ACTIONS as $actionLabel)
                                            <th class="py-3 px-2 text-center">{{ $actionLabel }}</th>
                                        @endforeach
                                        <th class="py-3 px-4">Phạm vi áp dụng</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                                    @foreach ($permissionsByModule as $module => $permissions)
                                        @php
                                            $moduleActions = $permissions->map(fn ($p) => explode('.', $p->name, 2)[1] ?? $p->name);
                                            $extraActions = $moduleActions->reject(fn ($a) => array_key_exists($a, \App\Models\UserPermissionOverride::MATRIX_ACTIONS))->values();
                                            $scope = $scopes->get($module, ['type' => 'all', 'ids' => []]);
                                            $scopeType = old("scope.{$module}.type", $scope['type']);
                                            $scopeIds = collect(old("scope.{$module}.ids", $scope['ids']))->map(fn ($id) => (int) $id)->all();
                                            $supportsScope = \App\Models\UserPermissionOverride::supportsScope($module);
                                        @endphp
                                        <tr class="hover:bg-orange-50/15 transition align-top" x-data="{ scopeType: '{{ $scopeType }}' }">
                                            <td class="py-3 px-4">
                                                <div class="font-bold text-gray-900 text-xs">{{ \App\Helpers\AclHelper::moduleLabel($module) }}</div>
                                                <div class="font-mono text-[10px] text-gray-400">{{ $module }}</div>
                                                @if ($extraActions->isNotEmpty())
                                                    <details class="mt-1.5">
                                                        <summary class="cursor-pointer text-[11px] font-semibold text-indigo-600">Quyền khác ({{ $extraActions->count() }})</summary>
                                                        <div class="mt-1.5 space-y-1">
                                                            @foreach ($extraActions as $action)
                                                                @include('users.partials.permission-cell', ['module' => $module, 'action' => $action, 'label' => \App\Helpers\AclHelper::actionLabel("{$module}.{$action}"), 'inline' => true])
                                                            @endforeach
                                                        </div>
                                                    </details>
                                                @endif
                                            </td>
                                            @foreach (array_keys(\App\Models\UserPermissionOverride::MATRIX_ACTIONS) as $action)
                                                <td class="py-3 px-2 text-center">
                                                    @if ($moduleActions->contains($action))
                                                        @include('users.partials.permission-cell', ['module' => $module, 'action' => $action, 'label' => null, 'inline' => false])
                                                    @else
                                                        <span class="text-gray-300" title="Module không có quyền này">·</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td class="py-3 px-4 min-w-[220px]">
                                                @if ($supportsScope)
                                                    <select name="scope[{{ $module }}][type]" x-model="scopeType" class="w-full rounded-xl border-gray-200 text-xs py-1.5 px-2 shadow-2xs">
                                                        <option value="all">Toàn hệ thống (Mặc định)</option>
                                                        <option value="branch">Theo chi nhánh</option>
                                                        <option value="class">Theo lớp</option>
                                                    </select>
                                                    <select name="scope[{{ $module }}][ids][]" multiple x-show="scopeType === 'branch'" :disabled="scopeType !== 'branch'" class="mt-1.5 w-full rounded-xl border-gray-200 text-xs py-1 px-2 h-20">
                                                        @foreach ($branches as $branch)
                                                            <option value="{{ $branch->id }}" @selected($scopeType === 'branch' && in_array($branch->id, $scopeIds, true))>{{ $branch->name }}</option>
                                                        @endforeach
                                                    </select>
                                                    <select name="scope[{{ $module }}][ids][]" multiple x-show="scopeType === 'class'" :disabled="scopeType !== 'class'" class="mt-1.5 w-full rounded-xl border-gray-200 text-xs py-1 px-2 h-20">
                                                        @foreach ($classes as $class)
                                                            <option value="{{ $class->id }}" @selected($scopeType === 'class' && in_array($class->id, $scopeIds, true))>{{ $class->name }}{{ $class->code ? " ({$class->code})" : '' }}</option>
                                                        @endforeach
                                                    </select>
                                                    <p class="mt-1 text-[10px] text-gray-400">Giữ Ctrl/⌘ để chọn nhiều.</p>
                                                @else
                                                    <span class="inline-flex items-center gap-1 text-[11px] text-gray-500" title="Phân hệ này chưa hỗ trợ giới hạn theo chi nhánh/lớp">
                                                        <span class="material-symbols-outlined text-[14px]">language</span>
                                                        Toàn hệ thống
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Footer Actions -->
                        <div class="p-4 bg-gray-50 border-t border-gray-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="text-xs text-gray-500 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[16px] text-gray-400">info</span>
                                <span>Phạm vi chi nhánh/lớp hiện áp dụng cho phân hệ Lớp học (Xem / Sửa / Xóa).</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <a href="{{ route('users.index') }}" class="px-4 py-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold transition">
                                    Hủy
                                </a>
                                <button type="submit" class="px-5 py-2 rounded-xl bg-primary-container hover:bg-primary text-white text-xs font-bold shadow-sm transition">
                                    Lưu thay đổi phân quyền
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Footer Summary Card -->
                <div class="p-5 bg-gradient-to-r from-orange-50/60 to-indigo-50/60 border border-gray-200 rounded-2xl grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                    <div>
                        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Tổng số Module</p>
                        <p class="text-xl font-bold text-gray-900 mt-0.5 font-mono">{{ count($permissionsByModule) }} <span class="text-xs font-normal text-gray-500">phân hệ</span></p>
                    </div>
                    <div>
                        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Quyền hạn hệ thống</p>
                        <p class="text-xl font-bold text-primary-container mt-0.5 font-mono">{{ $permissionsByModule->flatten()->count() }} <span class="text-xs font-normal text-gray-500">thao tác</span></p>
                    </div>
                    <div>
                        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Cơ sở công tác</p>
                        <p class="text-xl font-bold text-indigo-700 mt-0.5 truncate">{{ $user->branch?->name ?? 'Chưa gán chi nhánh' }}</p>
                    </div>
                </div>
            </section>

        </div>

    </div>
</x-app-layout>
