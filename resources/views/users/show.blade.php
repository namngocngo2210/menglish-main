@php
    $canViewSensitive = \App\Http\Controllers\UserController::canViewSensitive(auth()->user());
@endphp
<x-app-layout>
    <x-ui.page-header :title="$user->name" :back="route('users.index')">
        <x-slot:badges>
            @if ($user->isLocked())
                <x-ui.badge color="error">Vô hiệu hóa</x-ui.badge>
            @else
                <x-ui.badge color="success">Đang hoạt động</x-ui.badge>
            @endif
        </x-slot:badges>
        <x-slot:meta><span class="font-mono">{{ $user->employee_code ?? ('NV-' . str_pad($user->id, 4, '0', STR_PAD_LEFT)) }} · {{ $user->email }} · {{ $user->branch?->name ?? 'Chưa gán chi nhánh' }}</span></x-slot:meta>
        <x-slot:actions>
            @can('user.update')
                <x-ui.button variant="secondary" icon="edit" :href="route('users.edit', $user)">Sửa thông tin</x-ui.button>
            @endcan
            @can('permission.override')
                <x-ui.button icon="admin_panel_settings" :href="route('users.permissions.edit', $user)">Phân quyền chi tiết</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="max-w-5xl mx-auto space-y-6">
        {{-- Top Profile Card --}}
        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm flex flex-col md:flex-row items-center justify-between gap-6">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-full bg-orange-100 text-primary-container font-bold text-2xl flex items-center justify-center shadow-xs">
                    {{ Str::substr($user->name, 0, 1) }}
                </div>
                <div>
                    <h2 class="text-lg font-bold text-gray-900">{{ $user->name }}</h2>
                    <p class="text-xs text-gray-500">
                        {{ $user->roles->first()?->name ? \App\Helpers\AclHelper::roleLabel($user->roles->first()->name) : 'Nhân sự' }} — {{ $user->branch?->name ?? 'Chưa gán chi nhánh' }}
                    </p>
                    <div class="flex items-center gap-3 mt-2 text-xs text-gray-600">
                        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-gray-400">mail</span> {{ $user->email }}</span>
                        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-gray-400">call</span> {{ $user->phone ?? 'Chưa cập nhật' }}</span>
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
                    <span class="text-sm font-bold font-mono text-primary-container">{{ ! $canViewSensitive ? 'Ẩn' : ($user->base_salary ? number_format($user->base_salary) . 'đ' : 'Chưa cập nhật') }}</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Left 2 Cols: Detailed Staff Profile & Contract --}}
            <div class="lg:col-span-2 space-y-6">
                {{-- Section 1: Hồ sơ nhân sự --}}
                <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-5">
                    <h3 class="text-base font-bold text-gray-900 flex items-center gap-2 border-b border-gray-100 pb-3">
                        <span class="material-symbols-outlined text-primary-container">badge</span>
                        Hồ sơ nhân sự
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-1">Số CCCD (12 số)</span>
                            <span class="font-semibold text-gray-900 font-mono">{{ $canViewSensitive ? ($user->id_card_number ?? 'Chưa cập nhật') : 'Không có quyền xem' }}</span>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-1">Email cá nhân / hệ thống</span>
                            <span class="font-semibold text-gray-900">{{ $user->email }}</span>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-1">Quê quán</span>
                            <span class="font-semibold text-gray-900">{{ $canViewSensitive ? ($user->hometown ?? 'Chưa cập nhật') : 'Không có quyền xem' }}</span>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-1">Nơi ở hiện tại</span>
                            <span class="font-semibold text-gray-900">{{ $canViewSensitive ? ($user->current_address ?? 'Chưa cập nhật') : 'Không có quyền xem' }}</span>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100 md:col-span-2">
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-1">Liên lạc khẩn cấp</span>
                            <span class="font-semibold text-gray-900">{{ $canViewSensitive ? ($user->emergency_contact ?? 'Chưa cập nhật') : 'Không có quyền xem' }}</span>
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

                {{-- Section 2: Quản lý hợp đồng --}}
                <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-5">
                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                        <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-blue-600">contract</span>
                            Quản lý Hợp đồng lao động
                        </h3>
                        @php
                            $contractState = $user->contractExpiryStatus();
                            [$contractLabel, $contractTone] = match (true) {
                                ! $user->contract_type && ! $user->contract_end_date => ['Chưa cập nhật', 'bg-gray-100 text-gray-500'],
                                $contractState === 'expired' => ['Đã hết hạn', 'bg-rose-50 text-rose-700 border border-rose-200'],
                                $contractState === 'expiring' => ['Sắp hết hạn', 'bg-amber-50 text-amber-700 border border-amber-200'],
                                default => ['Đang hiệu lực', 'bg-emerald-50 text-emerald-700 border border-emerald-200'],
                            };
                        @endphp
                        <span class="text-xs px-2.5 py-0.5 rounded-full {{ $contractTone }} font-semibold">{{ $contractLabel }}</span>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-xs">
                        <div>
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-0.5">Loại Hợp đồng</span>
                            <span class="font-semibold text-gray-900">{{ $user->contract_type ?? 'Chưa cập nhật' }}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-0.5">Lương cơ bản</span>
                            <span class="font-bold text-gray-900 font-mono">{{ ! $canViewSensitive ? 'Không có quyền xem' : ($user->base_salary ? number_format($user->base_salary) . 'đ' : 'Chưa cập nhật') }}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-gray-400 font-bold uppercase block mb-0.5">Lương theo giờ dạy</span>
                            <span class="font-bold text-primary-container font-mono">{{ ! $canViewSensitive ? 'Không có quyền xem' : ($user->hourly_rate ? number_format($user->hourly_rate) . 'đ' : 'Chưa cập nhật') }}</span>
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
                            @php
                                $contractStatus = $user->contractExpiryStatus();
                            @endphp
                            <span class="font-bold {{ $contractStatus === 'expired' ? 'text-rose-600' : ($contractStatus === 'expiring' ? 'text-amber-600' : 'text-emerald-600') }}">
                                @if (! $user->contract_end_date)
                                    Chưa cập nhật
                                @elseif ($contractStatus === 'expired')
                                    Đã hết hạn
                                @elseif ($contractStatus === 'expiring')
                                    Sắp hết hạn (còn {{ (int) now()->startOfDay()->diffInDays($user->contract_end_date->copy()->startOfDay()) }} ngày)
                                @else
                                    Còn hiệu lực
                                @endif
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 pt-3 border-t border-gray-100">
                        @can('user.update')
                            <a href="{{ route('users.edit', $user) }}#contract_file" class="flex-1 py-2 bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 rounded-xl text-xs font-semibold flex items-center justify-center gap-1.5 transition">
                                <span class="material-symbols-outlined text-[16px]">upload_file</span>
                                Tải lên HĐ mới
                            </a>
                        @endcan
                        @if ($user->contract_file_path)
                            <a href="{{ route('users.contract.download', $user) }}" class="flex-1 py-2 bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200 rounded-xl text-xs font-semibold flex items-center justify-center gap-1.5 transition">
                                <span class="material-symbols-outlined text-[16px]">download</span>
                                Tải hợp đồng
                            </a>
                        @else
                            <span class="flex-1 py-2 bg-gray-50 text-gray-400 border border-gray-200 rounded-xl text-xs font-semibold flex items-center justify-center gap-1.5">
                                <span class="material-symbols-outlined text-[16px]">description</span>
                                Chưa có file hợp đồng
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Right 1 Col: Kiêm nhiệm giảng dạy & Phân quyền --}}
            <div class="space-y-6">
                {{-- Kiêm nhiệm giảng dạy — gán vai trò trong modal xong ("users-changed") khối này tự tải lại --}}
                <div id="user-roles-card" class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm space-y-4"
                     hx-get="{{ route('users.show', $user) }}" hx-trigger="users-changed from:body" hx-select="#user-roles-card" hx-swap="outerHTML" hx-disinherit="*">
                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                        <h3 class="text-sm font-bold text-gray-900 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-purple-600 text-[18px]">co_present</span>
                            Kiêm nhiệm &amp; lớp phụ trách
                        </h3>
                        @can('user.assign_role')
                            <a href="{{ route('users.roles.edit', $user) }}" hx-get="{{ route('users.roles.edit', $user) }}" hx-target="#remote-modal-body" hx-swap="innerHTML" data-modal-size="md"
                               class="text-[11px] text-primary-container font-bold hover:underline flex items-center gap-0.5">
                                <span class="material-symbols-outlined text-[14px]">add</span> Thêm
                            </a>
                        @endcan
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-orange-50 text-primary border border-orange-200" title="Vai trò chính">{{ $user->roles->first() ? \App\Helpers\AclHelper::shortRoleLabel($user->roles->first()->name) : 'Nhân sự' }}</span>
                        @forelse ($user->roles->slice(1) as $extraRole)
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">Kiêm nhiệm: {{ \App\Helpers\AclHelper::shortRoleLabel($extraRole->name) }}</span>
                        @empty
                            <span class="text-[11px] text-gray-400 italic">Không kiêm nhiệm vai trò khác</span>
                        @endforelse
                    </div>

                    <div class="space-y-2.5">
                        @forelse ($teachingClasses as $tc)
                            <div class="p-3 bg-gray-50 border border-gray-100 rounded-xl flex items-center justify-between text-xs">
                                <div>
                                    <p class="font-bold text-gray-900">{{ $tc->code }} — {{ $tc->name }}</p>
                                    <p class="text-[11px] text-gray-500 font-mono">{{ $tc->start_date?->format('d/m/Y') ?? 'Chưa cập nhật' }} - {{ $tc->end_date?->format('d/m/Y') ?? 'Chưa cập nhật' }}</p>
                                </div>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-purple-50 text-purple-700">
                                    {{ $tc->teacher_id === $user->id ? 'Giáo viên' : ($tc->foreign_teacher_id === $user->id ? 'GVNN' : 'Trợ giảng') }}
                                </span>
                            </div>
                        @empty
                            <p class="text-xs text-gray-400 italic text-center py-2">Chưa phụ trách lớp nào.</p>
                        @endforelse
                    </div>

                    <div class="p-2.5 bg-blue-50/60 border border-blue-100 rounded-xl text-[11px] text-blue-700 flex items-start gap-1.5">
                        <span class="material-symbols-outlined text-[15px] mt-0.5">info</span>
                        <span>Đợt kiêm nhiệm không phát sinh thêm khoản lương cơ bản mà tính theo giờ dạy thực tế.</span>
                    </div>
                </div>

                {{-- Thao tác nhanh --}}
                <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm space-y-3">
                    <h3 class="text-sm font-bold text-gray-900 border-b border-gray-100 pb-2">Thao tác tài khoản</h3>
                    @can('permission.override')
                        <a href="{{ route('users.permissions.edit', $user) }}" class="w-full py-2.5 bg-gray-50 hover:bg-gray-100 border border-gray-200 text-gray-800 rounded-xl text-xs font-semibold flex items-center justify-center gap-2 transition">
                            <span class="material-symbols-outlined text-[16px] text-indigo-600">admin_panel_settings</span>
                            Phân quyền chi tiết
                        </a>
                    @endcan
                    @can('user.assign_role')
                        <a href="{{ route('users.roles.edit', $user) }}" hx-get="{{ route('users.roles.edit', $user) }}" hx-target="#remote-modal-body" hx-swap="innerHTML" data-modal-size="md"
                           class="w-full py-2.5 bg-gray-50 hover:bg-gray-100 border border-gray-200 text-gray-800 rounded-xl text-xs font-semibold flex items-center justify-center gap-2 transition">
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
