<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Quản lý Tài khoản &amp; Vai trò</h1>
                <p class="text-sm text-gray-500 mt-0.5">Danh sách nhân sự, phân quyền vai trò và hồ sơ chi tiết cán bộ nhân viên</p>
            </div>
            <div class="flex items-center gap-3">
                @can('user.create')
                    <a href="{{ route('users.create') }}" class="bg-[#F5691A] hover:bg-[#d85a15] text-white text-sm font-medium px-4 py-2.5 rounded-xl transition shadow-sm flex items-center gap-2">
                        <span class="material-symbols-outlined text-[20px]">person_add</span>
                        <span>Thêm nhân viên mới</span>
                    </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="space-y-6" x-data="{
        drawerOpen: false,
        activeUser: null,
        openProfile(u) {
            this.activeUser = u;
            this.drawerOpen = true;
        },
        closeProfile() {
            this.drawerOpen = false;
        }
    }">
        @if (session('status'))
            <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex items-center gap-3 text-sm font-medium">
                <span class="material-symbols-outlined text-emerald-600">check_circle</span>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="p-4 rounded-2xl bg-rose-50 text-rose-800 border border-rose-200 text-xs font-semibold">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Stats Dashboard -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Tổng nhân sự</p>
                    <h3 class="text-2xl font-bold text-gray-900 mt-1 font-mono">{{ $totalStaff }}</h3>
                </div>
                <div class="w-10 h-10 rounded-xl bg-orange-50 text-[#F5691A] flex items-center justify-center">
                    <span class="material-symbols-outlined">group</span>
                </div>
            </div>
            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Đang hoạt động</p>
                    <h3 class="text-2xl font-bold text-emerald-600 mt-1 font-mono">{{ $activeStaff }}</h3>
                </div>
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <span class="material-symbols-outlined">check_circle</span>
                </div>
            </div>
            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Khối học thuật</p>
                    <h3 class="text-2xl font-bold text-indigo-600 mt-1 font-mono">{{ $academicStaff }}</h3>
                </div>
                <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <span class="material-symbols-outlined">school</span>
                </div>
            </div>
            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Vô hiệu hóa</p>
                    <h3 class="text-2xl font-bold text-rose-600 mt-1 font-mono">{{ $lockedStaff }}</h3>
                </div>
                <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
                    <span class="material-symbols-outlined">block</span>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="bg-white border border-gray-200 rounded-2xl p-4 shadow-sm">
            <form method="GET" action="{{ route('users.index') }}" class="flex flex-col md:flex-row items-center gap-3">
                <div class="relative flex-1 w-full">
                    <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-[18px]">search</span>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Tìm kiếm theo họ tên, email, SĐT hoặc mã nhân viên..."
                           class="w-full pl-10 pr-4 py-2 bg-white border border-gray-200 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] shadow-2xs">
                </div>
                <div class="w-full md:w-56">
                    <select name="branch_id" class="w-full bg-white border border-gray-200 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] py-2 shadow-2xs">
                        <option value="">Tất cả cơ sở</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full md:w-56">
                    <select name="role" class="w-full bg-white border border-gray-200 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] py-2 shadow-2xs">
                        <option value="">Tất cả vai trò</option>
                        @foreach ($roles as $r)
                            <option value="{{ $r }}" @selected(request('role') == $r)>{{ \App\Helpers\AclHelper::roleLabel($r) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-2 w-full md:w-auto">
                    <button type="submit" class="w-full md:w-auto px-4 py-2 bg-gray-900 hover:bg-black text-white rounded-xl text-xs font-semibold shadow-xs transition flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]">filter_list</span>
                        Lọc
                    </button>
                    @if(request()->hasAny(['search', 'branch_id', 'role']))
                        <a href="{{ route('users.index') }}" class="px-3 py-2 border border-gray-200 text-gray-500 hover:text-gray-900 rounded-xl text-xs font-semibold hover:bg-gray-50 transition" title="Xóa bộ lọc">
                            <span class="material-symbols-outlined text-[16px]">clear</span>
                        </a>
                    @endif
                </div>
            </form>
        </div>

        <!-- Data Table -->
        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3.5 px-4">Họ và tên</th>
                            <th class="py-3.5 px-4">Vai trò &amp; Chức vụ</th>
                            <th class="py-3.5 px-4">Cơ sở / Chi nhánh</th>
                            <th class="py-3.5 px-4">Trạng thái</th>
                            <th class="py-3.5 px-4 text-right">Hành động</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($users as $user)
                            <tr class="hover:bg-orange-50/15 transition group">
                                <td class="py-3.5 px-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full bg-orange-100 text-[#F5691A] flex items-center justify-center font-bold text-sm shrink-0 shadow-2xs">
                                            {{ Str::substr($user->name, 0, 1) }}
                                        </div>
                                        <div>
                                            <div class="font-bold text-gray-900 text-sm flex items-center gap-1.5">
                                                <span>{{ $user->name }}</span>
                                                <button @click="openProfile({{ json_encode($user->load(['branch', 'roles'])) }})" class="text-gray-400 hover:text-[#F5691A] transition" title="Xem hồ sơ nhanh">
                                                    <span class="material-symbols-outlined text-[15px]">info</span>
                                                </button>
                                            </div>
                                            <div class="text-[11px] text-gray-400 font-mono">{{ $user->email }} · {{ $user->employee_code ?? ('NV-' . str_pad($user->id, 4, '0', STR_PAD_LEFT)) }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($user->roles as $role)
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-indigo-50 border border-indigo-200 text-[11px] font-semibold text-indigo-700">
                                                {{ \App\Helpers\AclHelper::roleLabel($role->name) }}
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 font-medium text-gray-800">
                                    {{ $user->branch?->name ?? 'Cơ sở Cầu Giấy' }}
                                </td>
                                <td class="py-3.5 px-4">
                                    @if ($user->isLocked())
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-200 text-[11px] font-semibold">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-600"></span> Vô hiệu hóa
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px] font-semibold">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span> Đang hoạt động
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1">
                                        <!-- Nút Xem chi tiết hồ sơ (Slide-over drawer) -->
                                        <button @click="openProfile({{ json_encode($user->load(['branch', 'roles'])) }})" class="p-1 rounded-lg text-gray-500 hover:text-indigo-600 hover:bg-indigo-50 transition cursor-pointer" title="Xem hồ sơ chi tiết">
                                            <span class="material-symbols-outlined text-[18px]">visibility</span>
                                        </button>

                                        @can('permission.override')
                                            <a href="{{ route('users.permissions.edit', $user) }}" class="p-1 rounded-lg text-gray-500 hover:text-indigo-600 hover:bg-indigo-50 transition" title="Phân quyền chi tiết">
                                                <span class="material-symbols-outlined text-[18px]">admin_panel_settings</span>
                                            </a>
                                        @endcan

                                        @can('user.assign_role')
                                            <a href="{{ route('users.roles.edit', $user) }}" class="p-1 rounded-lg text-gray-500 hover:text-purple-600 hover:bg-purple-50 transition" title="Gán vai trò">
                                                <span class="material-symbols-outlined text-[18px]">badge</span>
                                            </a>
                                        @endcan

                                        @can('user.update')
                                            <a href="{{ route('users.edit', $user) }}" class="p-1 rounded-lg text-gray-500 hover:text-[#F5691A] hover:bg-orange-50 transition" title="Sửa thông tin">
                                                <span class="material-symbols-outlined text-[18px]">edit</span>
                                            </a>
                                        @endcan

                                        @can('user.reset_password')
                                            <form action="{{ route('users.reset-password', $user) }}" method="POST" class="inline" onsubmit="return confirm('Đặt lại mật khẩu cho nhân viên này?');">
                                                @csrf
                                                <button type="submit" class="p-1 rounded-lg text-gray-500 hover:text-amber-600 hover:bg-amber-50 transition cursor-pointer" title="Đặt lại mật khẩu">
                                                    <span class="material-symbols-outlined text-[18px]">key</span>
                                                </button>
                                            </form>
                                        @endcan

                                        @can('user.lock')
                                            @if ($user->isLocked())
                                                <form action="{{ route('users.unlock', $user) }}" method="POST" class="inline">
                                                    @csrf
                                                    <button type="submit" class="p-1 rounded-lg text-gray-500 hover:text-emerald-600 hover:bg-emerald-50 transition cursor-pointer" title="Kích hoạt lại">
                                                        <span class="material-symbols-outlined text-[18px]">check_circle</span>
                                                    </button>
                                                </form>
                                            @else
                                                <form action="{{ route('users.lock', $user) }}" method="POST" class="inline">
                                                    @csrf
                                                    <button type="submit" class="p-1 rounded-lg text-gray-500 hover:text-rose-600 hover:bg-rose-50 transition cursor-pointer" title="Vô hiệu hóa">
                                                        <span class="material-symbols-outlined text-[18px]">block</span>
                                                    </button>
                                                </form>
                                            @endif
                                        @endcan

                                        @can('user.delete')
                                            <form action="{{ route('users.destroy', $user) }}" method="POST" class="inline" onsubmit="return confirm('Bạn có chắc muốn xóa tài khoản nhân viên này?');">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="p-1 rounded-lg text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition cursor-pointer" title="Xóa tài khoản">
                                                    <span class="material-symbols-outlined text-[18px]">delete</span>
                                                </button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-8 text-center text-gray-400 text-xs">Không tìm thấy nhân viên nào phù hợp với bộ lọc.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-pagination :paginator="$users" />
        </div>

        <!-- Slide-over Profile Details Drawer (Hồ sơ chi tiết) -->
        <div x-show="drawerOpen" x-cloak class="fixed inset-0 z-50 overflow-hidden" aria-labelledby="slide-over-title" role="dialog" aria-modal="true">
            <!-- Background Backdrop -->
            <div x-show="drawerOpen" x-transition:enter="ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 @click="closeProfile()" class="fixed inset-0 bg-black/40 backdrop-blur-xs transition-opacity"></div>

            <div class="fixed inset-y-0 right-0 max-w-full flex pl-10">
                <div x-show="drawerOpen" x-transition:enter="transform transition ease-in-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
                     class="w-screen max-w-md bg-white border-l border-gray-200 shadow-2xl flex flex-col">

                    <!-- Drawer Header -->
                    <header class="flex items-center justify-between p-5 border-b border-gray-100 bg-gray-50/50">
                        <h2 class="text-base font-bold text-gray-900" id="slide-over-title">Hồ sơ chi tiết nhân sự</h2>
                        <button @click="closeProfile()" class="p-1 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition">
                            <span class="material-symbols-outlined text-[20px]">close</span>
                        </button>
                    </header>

                    <!-- Drawer Body -->
                    <div class="flex-1 overflow-y-auto p-5 space-y-6" x-show="activeUser">
                        <!-- Top Staff Info -->
                        <div class="flex items-center gap-4 bg-orange-50/40 p-4 rounded-2xl border border-orange-100/70">
                            <div class="w-14 h-14 rounded-full bg-[#F5691A] text-white flex items-center justify-center font-bold text-xl shrink-0 shadow-xs"
                                 x-text="activeUser ? activeUser.name.charAt(0) : 'N'"></div>
                            <div>
                                <h3 class="font-bold text-gray-900 text-base" x-text="activeUser ? activeUser.name : ''"></h3>
                                <p class="text-xs text-gray-500 font-medium" x-text="activeUser && activeUser.branch ? activeUser.branch.name : 'Cơ sở Cầu Giấy'"></p>
                                <p class="text-[11px] text-gray-400 font-mono mt-0.5" x-text="activeUser ? activeUser.email : ''"></p>
                            </div>
                        </div>

                        <!-- Section: Hồ sơ nhân sự -->
                        <section class="bg-gray-50/70 rounded-2xl border border-gray-200 p-4 space-y-4">
                            <h4 class="text-xs font-bold text-gray-900 uppercase tracking-wider flex items-center gap-1.5 border-b border-gray-200 pb-2">
                                <span class="material-symbols-outlined text-[#F5691A] text-[16px]">badge</span>
                                Thông tin nhân sự
                            </h4>
                            <div class="grid grid-cols-2 gap-3 text-xs">
                                <div>
                                    <span class="text-[10px] text-gray-400 font-bold uppercase block">Số CCCD (12 số)</span>
                                    <span class="font-semibold text-gray-900 font-mono" x-text="activeUser && activeUser.id_card_number ? activeUser.id_card_number : 'Chưa cập nhật'"></span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-gray-400 font-bold uppercase block">Số điện thoại</span>
                                    <span class="font-semibold text-gray-900 font-mono" x-text="activeUser && activeUser.phone ? activeUser.phone : 'Chưa cập nhật'"></span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-gray-400 font-bold uppercase block">Quê quán</span>
                                    <span class="font-semibold text-gray-900" x-text="activeUser && activeUser.hometown ? activeUser.hometown : 'Chưa cập nhật'"></span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-gray-400 font-bold uppercase block">Nơi ở hiện tại</span>
                                    <span class="font-semibold text-gray-900" x-text="activeUser && activeUser.current_address ? activeUser.current_address : 'Chưa cập nhật'"></span>
                                </div>
                                <div class="col-span-2">
                                    <span class="text-[10px] text-gray-400 font-bold uppercase block">Liên lạc khẩn cấp</span>
                                    <span class="font-semibold text-gray-900" x-text="activeUser && activeUser.emergency_contact ? activeUser.emergency_contact : 'Chưa cập nhật'"></span>
                                </div>
                            </div>

                            <hr class="border-gray-200">

                            <div class="grid grid-cols-2 gap-3 text-xs">
                                <div>
                                    <span class="text-[10px] text-gray-400 font-bold uppercase block">Tốt nghiệp</span>
                                    <span class="font-semibold text-gray-900" x-text="activeUser && activeUser.graduation_school ? activeUser.graduation_school : 'Chưa cập nhật'"></span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-gray-400 font-bold uppercase block">Chứng chỉ</span>
                                    <span class="font-bold text-emerald-700" x-text="activeUser && activeUser.certificates ? activeUser.certificates : 'Chưa cập nhật'"></span>
                                </div>
                                <div class="col-span-2">
                                    <span class="text-[10px] text-gray-400 font-bold uppercase block">Level giảng dạy</span>
                                    <span class="font-semibold text-gray-900" x-text="activeUser && activeUser.teaching_level ? activeUser.teaching_level : 'Chưa cập nhật'"></span>
                                </div>
                            </div>
                        </section>

                        <!-- Section: Quản lý hợp đồng -->
                        <section class="bg-gray-50/70 rounded-2xl border border-gray-200 p-4 space-y-4">
                            <div class="flex items-center justify-between border-b border-gray-200 pb-2">
                                <h4 class="text-xs font-bold text-gray-900 uppercase tracking-wider flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-blue-600 text-[16px]">contract</span>
                                    Hợp đồng lao động
                                </h4>
                                <span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 font-semibold" x-text="activeUser && activeUser.contract_type ? 'Đang hiệu lực' : 'Chưa cập nhật'"></span>
                            </div>

                            <div class="grid grid-cols-2 gap-3 text-xs">
                                <div>
                                    <span class="text-[10px] text-gray-400 font-bold uppercase block">Loại HĐ</span>
                                    <span class="font-semibold text-gray-900" x-text="activeUser && activeUser.contract_type ? activeUser.contract_type : 'Chưa cập nhật'"></span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-gray-400 font-bold uppercase block">Lương cơ bản</span>
                                    <span class="font-bold text-[#F5691A] font-mono" x-text="activeUser && activeUser.base_salary ? Number(activeUser.base_salary).toLocaleString() + 'đ' : 'Chưa cập nhật'"></span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-gray-400 font-bold uppercase block">Ngày bắt đầu</span>
                                    <span class="font-semibold text-gray-900" x-text="activeUser && activeUser.contract_start_date ? activeUser.contract_start_date.substring(0, 10) : 'Chưa cập nhật'"></span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-gray-400 font-bold uppercase block">Ngày kết thúc</span>
                                    <span class="font-semibold text-gray-900" x-text="activeUser && activeUser.contract_end_date ? activeUser.contract_end_date.substring(0, 10) : 'Chưa cập nhật'"></span>
                                </div>
                            </div>
                        </section>

                        <!-- Section: Kiêm nhiệm giảng dạy -->
                        <section class="bg-gray-50/70 rounded-2xl border border-gray-200 p-4 space-y-3">
                            <div class="flex items-center justify-between border-b border-gray-200 pb-2">
                                <h4 class="text-xs font-bold text-gray-900 uppercase tracking-wider flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-purple-600 text-[16px]">co_present</span>
                                    Kiêm nhiệm giảng dạy
                                </h4>
                            </div>

                            <p class="text-xs text-gray-500 italic">Chưa có thông tin phân công kiêm nhiệm phát sinh.</p>
                        </section>
                    </div>

                    <!-- Drawer Footer (Enforce permissions: ẩn hoàn toàn nếu chỉ có quyền xem) -->
                    <footer class="p-4 border-t border-gray-100 bg-gray-50 flex items-center justify-between gap-3">
                        @can('user.update')
                            <template x-if="activeUser">
                                <a :href="'/users/' + activeUser.id + '/edit'" class="flex-1 py-2 bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 rounded-xl text-xs font-semibold text-center transition shadow-2xs">
                                    Sửa nhân viên
                                </a>
                            </template>
                        @endcan
                        @can('permission.override')
                            <template x-if="activeUser">
                                <a :href="'/users/' + activeUser.id + '/permissions'" class="flex-1 py-2 bg-[#F5691A] hover:bg-[#d85a15] text-white rounded-xl text-xs font-semibold text-center transition shadow-xs">
                                    Phân quyền
                                </a>
                            </template>
                        @endcan
                        @cannot('user.update')
                            @cannot('permission.override')
                                <div class="w-full text-center text-xs text-gray-400 italic py-1">Chỉ xem hồ sơ (Không có quyền chỉnh sửa)</div>
                            @endcannot
                        @endcannot
                    </footer>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
