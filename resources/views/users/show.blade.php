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
        <div class="bg-surface-container-lowest border border-surface-container-highest rounded-2xl p-6 shadow-sm flex flex-col md:flex-row items-center justify-between gap-6">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-full bg-primary-container/10 text-primary-container font-bold text-2xl flex items-center justify-center shadow-xs">
                    {{ Str::substr($user->name, 0, 1) }}
                </div>
                <div>
                    <h2 class="text-lg font-bold text-on-surface">{{ $user->name }}</h2>
                    <p class="text-xs text-on-surface-variant">
                        {{ $user->roles->first()?->name ? \App\Helpers\AclHelper::roleLabel($user->roles->first()->name) : 'Nhân sự' }} — {{ $user->branch?->name ?? 'Chưa gán chi nhánh' }}
                    </p>
                    <div class="flex items-center gap-3 mt-2 text-xs text-on-surface-variant">
                        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-on-surface-variant/70">mail</span> {{ $user->email }}</span>
                        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-on-surface-variant/70">call</span> {{ $user->phone ?? 'Chưa cập nhật' }}</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-4 border-t md:border-t-0 md:border-l border-surface-container-highest pt-4 md:pt-0 md:pl-6 text-center">
                <div>
                    <span class="block text-[11px] font-bold text-on-surface-variant/70 uppercase">Mã NV</span>
                    <span class="text-sm font-bold font-mono text-on-surface">{{ $user->employee_code ?? ('NV-' . str_pad($user->id, 4, '0', STR_PAD_LEFT)) }}</span>
                </div>
                <div class="h-8 w-px bg-surface-container-high"></div>
                <div>
                    <span class="block text-[11px] font-bold text-on-surface-variant/70 uppercase">Lương cơ bản</span>
                    @if (! $canViewSensitive)
                        <span class="text-sm font-bold font-mono text-primary-container">Ẩn</span>
                    @elseif ($user->base_salary)
                        <x-ui.money :value="$user->base_salary" align="left" class="!text-center font-bold !text-primary-container" />
                    @else
                        <span class="text-sm font-bold font-mono text-primary-container">Chưa cập nhật</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Left 2 Cols: Detailed Staff Profile & Contract --}}
            <div class="lg:col-span-2 space-y-6">
                {{-- Section 1: Hồ sơ nhân sự --}}
                <div class="bg-surface-container-lowest border border-surface-container-highest rounded-2xl p-6 shadow-sm space-y-5">
                    <h3 class="text-base font-bold text-on-surface flex items-center gap-2 border-b border-surface-container-highest pb-3">
                        <span class="material-symbols-outlined text-primary-container">badge</span>
                        Hồ sơ nhân sự
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest">
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-1">Số CCCD (12 số)</span>
                            <span class="font-semibold text-on-surface font-mono">{{ $canViewSensitive ? ($user->id_card_number ?? 'Chưa cập nhật') : 'Không có quyền xem' }}</span>
                        </div>
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest">
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-1">Email cá nhân / hệ thống</span>
                            <span class="font-semibold text-on-surface">{{ $user->email }}</span>
                        </div>
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest">
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-1">Quê quán</span>
                            <span class="font-semibold text-on-surface">{{ $canViewSensitive ? ($user->hometown ?? 'Chưa cập nhật') : 'Không có quyền xem' }}</span>
                        </div>
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest">
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-1">Nơi ở hiện tại</span>
                            <span class="font-semibold text-on-surface">{{ $canViewSensitive ? ($user->current_address ?? 'Chưa cập nhật') : 'Không có quyền xem' }}</span>
                        </div>
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest md:col-span-2">
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-1">Liên lạc khẩn cấp</span>
                            <span class="font-semibold text-on-surface">{{ $canViewSensitive ? ($user->emergency_contact ?? 'Chưa cập nhật') : 'Không có quyền xem' }}</span>
                        </div>
                    </div>

                    <div class="border-t border-surface-container-highest pt-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest">
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-1">Tốt nghiệp</span>
                            <span class="font-semibold text-on-surface">{{ $user->graduation_school ?? 'Chưa cập nhật' }}</span>
                        </div>
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest">
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-1">Chứng chỉ</span>
                            <span class="font-semibold text-tertiary font-bold">{{ $user->certificates ?? 'Chưa cập nhật' }}</span>
                        </div>
                        <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest md:col-span-2">
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-1">Level giảng dạy / Chuyên môn</span>
                            <span class="font-semibold text-on-surface">{{ $user->teaching_level ?? 'Chưa cập nhật' }}</span>
                        </div>
                    </div>
                </div>

                {{-- Section 2: Quản lý hợp đồng --}}
                <div class="bg-surface-container-lowest border border-surface-container-highest rounded-2xl p-6 shadow-sm space-y-5">
                    <div class="flex items-center justify-between border-b border-surface-container-highest pb-3">
                        <h3 class="text-base font-bold text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-secondary">contract</span>
                            Quản lý Hợp đồng lao động
                        </h3>
                        @php
                            $contractState = $user->contractExpiryStatus();
                            [$contractLabel, $contractTone] = match (true) {
                                ! $user->contract_type && ! $user->contract_end_date => ['Chưa cập nhật', 'neutral'],
                                $contractState === 'expired' => ['Đã hết hạn', 'error'],
                                $contractState === 'expiring' => ['Sắp hết hạn', 'warning'],
                                default => ['Đang hiệu lực', 'success'],
                            };
                        @endphp
                        <x-ui.badge :color="$contractTone" :pill="true" :dot="false" class="font-semibold">{{ $contractLabel }}</x-ui.badge>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-xs">
                        <div>
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-0.5">Loại Hợp đồng</span>
                            <span class="font-semibold text-on-surface">{{ $user->contract_type ?? 'Chưa cập nhật' }}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-0.5">Lương cơ bản</span>
                            @if (! $canViewSensitive)
                                <span class="font-bold text-on-surface font-mono">Không có quyền xem</span>
                            @elseif ($user->base_salary)
                                <x-ui.money :value="$user->base_salary" align="left" class="font-bold" />
                            @else
                                <span class="font-bold text-on-surface font-mono">Chưa cập nhật</span>
                            @endif
                        </div>
                        <div>
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-0.5">Lương theo giờ dạy</span>
                            @if (! $canViewSensitive)
                                <span class="font-bold text-primary-container font-mono">Không có quyền xem</span>
                            @elseif ($user->hourly_rate)
                                <x-ui.money :value="$user->hourly_rate" align="left" class="font-bold !text-primary-container" />
                            @else
                                <span class="font-bold text-primary-container font-mono">Chưa cập nhật</span>
                            @endif
                        </div>
                        <div>
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-0.5">Ngày bắt đầu</span>
                            <span class="font-semibold text-on-surface">{{ $user->contract_start_date ? $user->contract_start_date->format('d/m/Y') : 'Chưa cập nhật' }}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-0.5">Ngày kết thúc</span>
                            <span class="font-semibold text-on-surface">{{ $user->contract_end_date ? $user->contract_end_date->format('d/m/Y') : 'Chưa cập nhật' }}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-on-surface-variant/70 font-bold uppercase block mb-0.5">Thời hạn còn lại</span>
                            @php
                                $contractStatus = $user->contractExpiryStatus();
                            @endphp
                            <span class="font-bold {{ $contractStatus === 'expired' ? 'text-error' : ($contractStatus === 'expiring' ? 'text-warning' : 'text-tertiary') }}">
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

                    <div class="flex items-center gap-3 pt-3 border-t border-surface-container-highest">
                        @can('user.update')
                            <x-ui.button variant="secondary" size="sm" icon="upload_file" :href="route('users.edit', $user).'#contract_file'" class="flex-1">Tải lên HĐ mới</x-ui.button>
                        @endcan
                        @if ($user->contract_file_path)
                            <x-ui.button variant="secondary" size="sm" icon="download" :href="route('users.contract.download', $user)" class="flex-1 !border-secondary/30 !bg-secondary/10 !text-secondary hover:!bg-secondary/20">Tải hợp đồng</x-ui.button>
                        @else
                            <span class="flex-1 py-2 bg-surface-container-low text-on-surface-variant/70 border border-surface-container-highest rounded-xl text-xs font-semibold flex items-center justify-center gap-1.5">
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
                <div id="user-roles-card" class="bg-surface-container-lowest border border-surface-container-highest rounded-2xl p-5 shadow-sm space-y-4"
                     hx-get="{{ route('users.show', $user) }}" hx-trigger="users-changed from:body" hx-select="#user-roles-card" hx-swap="outerHTML" hx-disinherit="*">
                    <div class="flex items-center justify-between border-b border-surface-container-highest pb-3">
                        <h3 class="text-sm font-bold text-on-surface flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-secondary text-[18px]">co_present</span>
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
                        <x-ui.badge color="primary" :pill="true" :dot="false" class="font-semibold" title="Vai trò chính">{{ $user->roles->first() ? \App\Helpers\AclHelper::shortRoleLabel($user->roles->first()->name) : 'Nhân sự' }}</x-ui.badge>
                        @forelse ($user->roles->slice(1) as $extraRole)
                            <x-ui.badge color="secondary" :pill="true" :dot="false" class="font-semibold">Kiêm nhiệm: {{ \App\Helpers\AclHelper::shortRoleLabel($extraRole->name) }}</x-ui.badge>
                        @empty
                            <span class="text-[11px] text-on-surface-variant/70 italic">Không kiêm nhiệm vai trò khác</span>
                        @endforelse
                    </div>

                    <div class="space-y-2.5">
                        @forelse ($teachingClasses as $tc)
                            <div class="p-3 bg-surface-container-low border border-surface-container-highest rounded-xl flex items-center justify-between text-xs">
                                <div>
                                    <p class="font-bold text-on-surface">{{ $tc->code }} — {{ $tc->name }}</p>
                                    <p class="text-[11px] text-on-surface-variant font-mono">{{ $tc->start_date?->format('d/m/Y') ?? 'Chưa cập nhật' }} - {{ $tc->end_date?->format('d/m/Y') ?? 'Chưa cập nhật' }}</p>
                                </div>
                                <x-ui.badge color="secondary" :pill="true" :dot="false" class="font-semibold">
                                    {{ $tc->teacher_id === $user->id ? 'Giáo viên' : ($tc->foreign_teacher_id === $user->id ? 'GVNN' : 'Trợ giảng') }}
                                </x-ui.badge>
                            </div>
                        @empty
                            <p class="text-xs text-on-surface-variant/70 italic text-center py-2">Chưa phụ trách lớp nào.</p>
                        @endforelse
                    </div>

                    <div class="p-2.5 bg-info-container border border-secondary/30 rounded-xl text-[11px] text-info flex items-start gap-1.5">
                        <span class="material-symbols-outlined text-[15px] mt-0.5">info</span>
                        <span>Đợt kiêm nhiệm không phát sinh thêm khoản lương cơ bản mà tính theo giờ dạy thực tế.</span>
                    </div>
                </div>

                {{-- Thao tác nhanh --}}
                <div class="bg-surface-container-lowest border border-surface-container-highest rounded-2xl p-5 shadow-sm space-y-3">
                    <h3 class="text-sm font-bold text-on-surface border-b border-surface-container-highest pb-2">Thao tác tài khoản</h3>
                    @can('permission.override')
                        <x-ui.button variant="secondary" icon="admin_panel_settings" :href="route('users.permissions.edit', $user)" class="w-full">Phân quyền chi tiết</x-ui.button>
                    @endcan
                    @can('user.assign_role')
                        <x-ui.button variant="secondary" icon="badge" :href="route('users.roles.edit', $user)" hx-get="{{ route('users.roles.edit', $user) }}" hx-target="#remote-modal-body" hx-swap="innerHTML" data-modal-size="md" class="w-full">Gán vai trò chức vụ</x-ui.button>
                    @endcan
                    @cannot('permission.override')
                        @cannot('user.assign_role')
                            <p class="text-xs text-on-surface-variant/70 italic text-center py-2">Bạn chỉ có quyền xem thông tin.</p>
                        @endcannot
                    @endcannot
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
