<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('users.index') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span>{{ $user->name }}</span>
                        @if ($user->isLocked())
                            <span class="text-xs px-2.5 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-200 font-semibold">Vô hiệu hóa</span>
                        @else
                            <span class="text-xs px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 font-semibold">Đang hoạt động</span>
                        @endif
                    </h1>
                    <p class="text-xs text-gray-500 font-mono">{{ $user->employee_code ?? ('NV-' . str_pad($user->id, 4, '0', STR_PAD_LEFT)) }} · {{ $user->email }} · {{ $user->branch?->name ?? 'Cơ sở Cầu Giấy' }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @can('user.update')
                    <a href="{{ route('users.edit', $user) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-xs font-semibold text-gray-700 transition">
                        <span class="material-symbols-outlined text-[16px]">edit</span>
                        <span>Sửa thông tin</span>
                    </a>
                @endcan
                @can('permission.override')
                    <a href="{{ route('users.permissions.edit', $user) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-primary-container hover:bg-primary text-white text-xs font-semibold shadow-sm transition">
                        <span class="material-symbols-outlined text-[16px]">admin_panel_settings</span>
                        <span>Phân quyền chi tiết</span>
                    </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto space-y-6">
        <!-- Top Profile Card -->
        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm flex flex-col md:flex-row items-center justify-between gap-6">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-full bg-orange-100 text-primary-container font-bold text-2xl flex items-center justify-center shadow-xs">
                    {{ Str::substr($user->name, 0, 1) }}
                </div>
                <div>
                    <h2 class="text-lg font-bold text-gray-900">{{ $user->name }}</h2>
                    <p class="text-xs text-gray-500">
                        {{ $user->roles->first()?->name ? \App\Helpers\AclHelper::roleLabel($user->roles->first()->name) : 'Nhân sự' }} — {{ $user->branch?->name ?? 'Cơ sở Cầu Giấy' }}
                    </p>
                    <div class="flex items-center gap-3 mt-2 text-xs text-gray-600">
                        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-gray-400">mail</span> {{ $user->email }}</span>
                        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-gray-400">call</span> {{ $user->phone ?? '0912 345 678' }}</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-4 border-t md:border-t-0 md:border-l border-gray-100 pt-4 md:pt-0 md:pl-6 text-center">
                <div>
                    <span class="block text-[11px] font-bold text-gray-400 uppercase">Mã NV</span>
                    <span class="text-sm font-bold font-mono text-gray-900">{{ $user->employee_code ?? ('NV-' . str_pad($user->id, 4, '0', STR_PAD_LEFT)) }}</span>
                </div>
                <div class="h-8 w-px bg-gray-200"></div>
                <div>
                    <span class="block text-[11px] font-bold text-gray-400 uppercase">Lương cơ bản</span>
                    <span class="text-sm font-bold font-mono text-primary-container">{{ $user->base_salary ? number_format($user->base_salary) . 'đ' : '15,000,000đ' }}</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left 2 Cols: Detailed Staff Profile & Contract -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Section 1: Hồ sơ nhân sự -->
                <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-5">
                    <h3 class="text-base font-bold text-gray-900 flex items-center gap-2 border-b border-gray-100 pb-3">
                        <span class="material-symbols-outlined text-primary-container">badge</span>
                        Hồ sơ nhân sự
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-1">Số CCCD (12 số)</span>
                            <span class="font-semibold text-gray-900 font-mono">{{ $user->id_card_number ?? 'Chưa cập nhật' }}</span>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-1">Email cá nhân / hệ thống</span>
                            <span class="font-semibold text-gray-900">{{ $user->email }}</span>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-1">Quê quán</span>
                            <span class="font-semibold text-gray-900">{{ $user->hometown ?? 'Chưa cập nhật' }}</span>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-1">Nơi ở hiện tại</span>
                            <span class="font-semibold text-gray-900">{{ $user->current_address ?? 'Chưa cập nhật' }}</span>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100 md:col-span-2">
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-1">Liên lạc khẩn cấp</span>
                            <span class="font-semibold text-gray-900">{{ $user->emergency_contact ?? 'Chưa cập nhật' }}</span>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 pt-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-1">Tốt nghiệp</span>
                            <span class="font-semibold text-gray-900">{{ $user->graduation_school ?? 'Chưa cập nhật' }}</span>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-1">Chứng chỉ</span>
                            <span class="font-semibold text-emerald-700 font-bold">{{ $user->certificates ?? 'Chưa cập nhật' }}</span>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100 md:col-span-2">
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-1">Level giảng dạy / Chuyên môn</span>
                            <span class="font-semibold text-gray-900">{{ $user->teaching_level ?? 'Chưa cập nhật' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Quản lý hợp đồng -->
                <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-5">
                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                        <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-blue-600">contract</span>
                            Quản lý Hợp đồng lao động
                        </h3>
                        <span class="text-xs px-2.5 py-0.5 rounded-full {{ $user->contract_type ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-gray-100 text-gray-500' }} font-semibold">
                            {{ $user->contract_type ? 'Đang hiệu lực' : 'Chưa cập nhật' }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-xs">
                        <div>
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-0.5">Loại Hợp đồng</span>
                            <span class="font-semibold text-gray-900">{{ $user->contract_type ?? 'Chưa cập nhật' }}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-0.5">Lương cơ bản</span>
                            <span class="font-bold text-gray-900 font-mono">{{ $user->base_salary ? number_format($user->base_salary) . 'đ' : 'Chưa cập nhật' }}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-0.5">Lương theo giờ dạy</span>
                            <span class="font-bold text-primary-container font-mono">{{ $user->hourly_rate ? number_format($user->hourly_rate) . 'đ' : 'Chưa cập nhật' }}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-0.5">Ngày bắt đầu</span>
                            <span class="font-semibold text-gray-900">{{ $user->contract_start_date ? $user->contract_start_date->format('d/m/Y') : 'Chưa cập nhật' }}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-0.5">Ngày kết thúc</span>
                            <span class="font-semibold text-gray-900">{{ $user->contract_end_date ? $user->contract_end_date->format('d/m/Y') : 'Chưa cập nhật' }}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-0.5">Thời hạn còn lại</span>
                            <span class="font-bold {{ $user->contract_end_date && $user->contract_end_date->isPast() ? 'text-rose-600' : 'text-emerald-600' }}">
                                {{ $user->contract_end_date ? ($user->contract_end_date->isPast() ? 'Đã hết hạn' : 'Còn hiệu lực') : 'Chưa cập nhật' }}
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 pt-3 border-t border-gray-100">
                        <button class="flex-1 py-2 bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 rounded-xl text-xs font-semibold flex items-center justify-center gap-1.5 transition">
                            <span class="material-symbols-outlined text-[16px]">upload_file</span>
                            Tải lên HĐ mới
                        </button>
                        <button class="flex-1 py-2 bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200 rounded-xl text-xs font-semibold flex items-center justify-center gap-1.5 transition">
                            <span class="material-symbols-outlined text-[16px]">visibility</span>
                            Xem / Tải hợp đồng
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right 1 Col: Kiêm nhiệm giảng dạy & Phân quyền -->
            <div class="space-y-6">
                <!-- Kiêm nhiệm giảng dạy -->
                <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm space-y-4">
                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                        <h3 class="text-sm font-bold text-gray-900 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-purple-600 text-[18px]">co_present</span>
                            Kiêm nhiệm giảng dạy
                        </h3>
                        <button class="text-[11px] text-primary-container font-bold hover:underline flex items-center gap-0.5">
                            <span class="material-symbols-outlined text-[14px]">add</span> Thêm
                        </button>
                    </div>

                    <div class="space-y-2.5">
                        <div class="p-3 bg-gray-50 border border-gray-100 rounded-xl flex items-center justify-between text-xs">
                            <div>
                                <p class="font-bold text-gray-900">Dạy thay: Trần Văn C</p>
                                <p class="text-[11px] text-gray-500 font-mono">15/09/2025 - 20/09/2025</p>
                                <p class="text-[10px] text-gray-400">Lý do: Nghỉ ốm</p>
                            </div>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-gray-200 text-gray-600">Hết hạn</span>
                        </div>
                    </div>

                    <div class="p-2.5 bg-blue-50/60 border border-blue-100 rounded-xl text-[11px] text-blue-700 flex items-start gap-1.5">
                        <span class="material-symbols-outlined text-[15px] mt-0.5">info</span>
                        <span>Đợt kiêm nhiệm không phát sinh thêm khoản lương cơ bản mà tính theo giờ dạy thực tế.</span>
                    </div>
                </div>

                <!-- Thao tác nhanh -->
                <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm space-y-3">
                    <h3 class="text-sm font-bold text-gray-900 border-b border-gray-100 pb-2">Thao tác tài khoản</h3>
                    @can('permission.override')
                        <a href="{{ route('users.permissions.edit', $user) }}" class="w-full py-2.5 bg-gray-50 hover:bg-gray-100 border border-gray-200 text-gray-800 rounded-xl text-xs font-semibold flex items-center justify-center gap-2 transition">
                            <span class="material-symbols-outlined text-[16px] text-indigo-600">admin_panel_settings</span>
                            Phân quyền chi tiết
                        </a>
                    @endcan
                    @can('user.assign_role')
                        <a href="{{ route('users.roles.edit', $user) }}" class="w-full py-2.5 bg-gray-50 hover:bg-gray-100 border border-gray-200 text-gray-800 rounded-xl text-xs font-semibold flex items-center justify-center gap-2 transition">
                            <span class="material-symbols-outlined text-[16px] text-purple-600">badge</span>
                            Gán vai trò chức vụ
                        </a>
                    @endcan
                    @cannot('permission.override')
                        @cannot('user.assign_role')
                            <p class="text-xs text-gray-400 italic text-center py-2">Bạn chỉ có quyền xem thông tin.</p>
                        @endcannot
                    @endcannot
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
