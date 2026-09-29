{{-- Chi tiết nhân sự. Sửa thông tin / Tải lên HĐ mới (modal 3xl, mở sẵn tab Hợp đồng & Lương), Phân quyền chi tiết (modal 4xl),
     Gán vai trò (modal md) đều mở bằng htmx; lưu xong server phát "users-changed" → #user-detail tự tải lại tại chỗ. --}}
@php
    $canViewSensitive = \App\Http\Controllers\UserController::canViewSensitive(auth()->user());
    $employeeCode = $user->employee_code ?? ('NV-'.str_pad($user->id, 4, '0', STR_PAD_LEFT));
    $primaryRole = $user->roles->first()?->name;
    $empty = 'Chưa cập nhật';
    $hidden = 'Không có quyền xem';
    // Giá trị trống / bị ẩn hiển thị chữ mờ, nghiêng (không tô màu như dữ liệu thật).
    $contractState = $user->contractExpiryStatus();
    [$contractLabel, $contractTone] = match (true) {
        ! $user->contract_type && ! $user->contract_end_date => [$empty, 'neutral'],
        $contractState === 'expired' => ['Đã hết hạn', 'error'],
        $contractState === 'expiring' => ['Sắp hết hạn', 'warning'],
        default => ['Đang hiệu lực', 'success'],
    };
    $remaining = match (true) {
        ! $user->contract_end_date => null,
        $contractState === 'expired' => ['Đã hết hạn', 'text-error'],
        $contractState === 'expiring' => ['Còn '.(int) now()->startOfDay()->diffInDays($user->contract_end_date->copy()->startOfDay()).' ngày', 'text-warning'],
        default => ['Còn hiệu lực', 'text-tertiary'],
    };
    $hasMoney = fn ($v) => $v !== null && (float) $v > 0;
@endphp
<x-app-layout :title="$user->name">
<div id="user-detail" hx-get="{{ route('users.show', $user) }}" hx-trigger="users-changed from:body" hx-select="#user-detail" hx-swap="outerHTML" hx-disinherit="*">
    <x-ui.page-header :title="$user->name" :back="route('users.index')">
        <x-slot:badges>
            @if ($user->isLocked())
                <x-ui.badge color="error">Vô hiệu hóa</x-ui.badge>
            @else
                <x-ui.badge color="success">Đang hoạt động</x-ui.badge>
            @endif
        </x-slot:badges>
        <x-slot:meta><span class="break-all font-code">{{ $employeeCode }} · {{ $user->email }} · {{ $user->branch?->name ?? 'Chưa gán chi nhánh' }}</span></x-slot:meta>
        <x-slot:actions>
            @can('user.update')
                <x-ui.button variant="secondary" icon="edit" :href="route('users.edit', $user)" modal="3xl">Sửa thông tin</x-ui.button>
            @endcan
            @can('permission.override')
                <x-ui.button icon="admin_panel_settings" :href="route('users.permissions.edit', $user)" modal="4xl">Phân quyền chi tiết</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-lg">
        @if ($errors->any())
            <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
        @endif

        {{-- Thẻ tóm tắt --}}
        <section class="flex flex-col gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg md:flex-row md:items-center md:justify-between">
            <div class="flex min-w-0 items-center gap-md">
                <x-ui.avatar :name="$user->name" size="lg" />
                <div class="min-w-0 space-y-xs">
                    <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ $primaryRole ? \App\Helpers\AclHelper::roleLabel($primaryRole) : 'Người dùng' }}</p>
                    <div class="flex flex-wrap items-center gap-x-md gap-y-xs font-body-small text-body-small text-on-surface-variant">
                        <span class="inline-flex min-w-0 items-center gap-xs"><span class="material-symbols-outlined shrink-0 text-[16px]" aria-hidden="true">mail</span><span class="break-all">{{ $user->email }}</span></span>
                        <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">call</span><span class="{{ $user->phone ? 'font-code' : 'italic' }}">{{ $user->phone ?? $empty }}</span></span>
                        <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">apartment</span>{{ $user->branch?->name ?? 'Chưa gán chi nhánh' }}</span>
                    </div>
                </div>
            </div>
            <dl class="flex min-w-0 items-start gap-lg md:shrink-0 border-t border-surface-container pt-md md:border-l md:border-t-0 md:pl-lg md:pt-0">
                <div class="min-w-0">
                    <dt class="font-label text-label uppercase text-on-surface-variant">Mã NV</dt>
                    <dd class="break-all font-code font-semibold text-on-surface">{{ $employeeCode }}</dd>
                </div>
                <div>
                    <dt class="whitespace-nowrap font-label text-label uppercase text-on-surface-variant">Lương cơ bản</dt>
                    <dd>
                        @if (! $canViewSensitive)
                            <span class="italic text-on-surface-variant">Ẩn</span>
                        @elseif ($hasMoney($user->base_salary))
                            <x-ui.money :value="$user->base_salary" align="left" tone="primary" class="font-semibold" />
                        @else
                            <span class="font-body-small text-body-small italic text-on-surface-variant">{{ $empty }}</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-3">
            <div class="space-y-lg lg:col-span-2">
                {{-- Hồ sơ nhân sự --}}
                <section class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                    <header class="flex items-center justify-between gap-sm border-b border-surface-container px-lg py-md">
                        <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">badge</span>Hồ sơ người dùng</h2>
                        @can('user.update')
                            <x-ui.button variant="ghost" size="sm" icon="edit" :href="route('users.edit', ['user' => $user, 'tab' => 'profile'])" modal="3xl">Cập nhật hồ sơ</x-ui.button>
                        @endcan
                    </header>
                    @php
                        $profileFields = [
                            ['Số CCCD (12 số)', $canViewSensitive ? $user->id_card_number : false, true],
                            ['Liên lạc khẩn cấp', $canViewSensitive ? $user->emergency_contact : false, false],
                            ['Quê quán', $canViewSensitive ? $user->hometown : false, false],
                            ['Nơi ở hiện tại', $canViewSensitive ? $user->current_address : false, false],
                            ['Tốt nghiệp', $user->graduation_school, false],
                            ['Chứng chỉ', $user->certificates, false],
                            ['Level giảng dạy / Chuyên môn', $user->teaching_level, false],
                        ];
                    @endphp
                    <dl class="grid grid-cols-1 gap-x-lg gap-y-md p-lg font-body-small text-body-small sm:grid-cols-2">
                        @foreach ($profileFields as [$label, $value, $code])
                            <div class="{{ $loop->last ? 'sm:col-span-2' : '' }}">
                                <dt class="mb-xs font-label text-label uppercase text-on-surface-variant">{{ $label }}</dt>
                                @if ($value === false)
                                    <dd class="italic text-on-surface-variant">{{ $hidden }}</dd>
                                @elseif (blank($value))
                                    <dd class="italic text-on-surface-variant">{{ $empty }}</dd>
                                @else
                                    <dd class="font-medium text-on-surface {{ $code ? 'font-code' : '' }}">{{ $value }}</dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>
                </section>

                {{-- Hợp đồng lao động --}}
                <section class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                    <header class="flex items-center justify-between gap-sm border-b border-surface-container px-lg py-md">
                        <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-secondary" aria-hidden="true">contract</span>Hợp đồng lao động</h2>
                        <x-ui.badge :color="$contractTone" :dot="false">{{ $contractLabel }}</x-ui.badge>
                    </header>
                    <dl class="grid grid-cols-2 gap-x-lg gap-y-md p-lg font-body-small text-body-small md:grid-cols-3">
                        <div>
                            <dt class="mb-xs font-label text-label uppercase text-on-surface-variant">Loại hợp đồng</dt>
                            <dd class="{{ $user->contract_type ? 'font-medium text-on-surface' : 'italic text-on-surface-variant' }}">{{ $user->contract_type ?? $empty }}</dd>
                        </div>
                        @foreach (['Lương cơ bản' => $user->base_salary, 'Thù lao giờ dạy' => $user->hourly_rate] as $label => $amount)
                            <div>
                                <dt class="mb-xs font-label text-label uppercase text-on-surface-variant">{{ $label }}</dt>
                                <dd>
                                    @if (! $canViewSensitive)
                                        <span class="italic text-on-surface-variant">{{ $hidden }}</span>
                                    @elseif ($hasMoney($amount))
                                        <x-ui.money :value="$amount" align="left" class="font-semibold" />
                                    @else
                                        <span class="italic text-on-surface-variant">{{ $empty }}</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                        <div>
                            <dt class="mb-xs font-label text-label uppercase text-on-surface-variant">Ngày bắt đầu</dt>
                            <dd class="{{ $user->contract_start_date ? 'font-code text-on-surface' : 'italic text-on-surface-variant' }}">{{ $user->contract_start_date?->format('d/m/Y') ?? $empty }}</dd>
                        </div>
                        <div>
                            <dt class="mb-xs font-label text-label uppercase text-on-surface-variant">Ngày kết thúc</dt>
                            <dd class="{{ $user->contract_end_date ? 'font-code text-on-surface' : 'italic text-on-surface-variant' }}">{{ $user->contract_end_date?->format('d/m/Y') ?? $empty }}</dd>
                        </div>
                        <div>
                            <dt class="mb-xs font-label text-label uppercase text-on-surface-variant">Thời hạn còn lại</dt>
                            @if ($remaining)
                                <dd class="font-semibold {{ $remaining[1] }}">{{ $remaining[0] }}</dd>
                            @else
                                <dd class="italic text-on-surface-variant">{{ $empty }}</dd>
                            @endif
                        </div>
                    </dl>
                    <footer class="flex flex-col gap-sm border-t border-surface-container px-lg py-md sm:flex-row sm:items-center sm:justify-between">
                        @if ($user->contract_file_path)
                            <span class="inline-flex items-center gap-xs font-body-small text-body-small text-on-surface"><span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">description</span>Đã có file hợp đồng</span>
                        @else
                            <span class="inline-flex items-center gap-xs font-body-small text-body-small italic text-on-surface-variant"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">description</span>Chưa có file hợp đồng</span>
                        @endif
                        <div class="flex flex-wrap gap-sm">
                            @if ($user->contract_file_path)
                                <x-ui.button variant="secondary" size="sm" icon="download" :href="route('users.contract.download', $user)" hx-boost="false">Tải hợp đồng</x-ui.button>
                            @endif
                            @can('user.update')
                                <x-ui.button variant="secondary" size="sm" icon="upload_file" :href="route('users.edit', ['user' => $user, 'tab' => 'salary'])" modal="3xl">{{ $user->contract_file_path ? 'Tải lên HĐ mới' : 'Tải lên hợp đồng' }}</x-ui.button>
                            @endcan
                        </div>
                    </footer>
                </section>
            </div>

            <div class="space-y-lg">
                {{-- Vai trò, kiêm nhiệm & lớp phụ trách --}}
                <section id="user-roles-card" class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                    <header class="flex items-center justify-between gap-sm border-b border-surface-container px-lg py-md">
                        <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-secondary" aria-hidden="true">co_present</span>Kiêm nhiệm &amp; lớp</h2>
                        @can('user.assign_role')
                            <x-ui.button variant="ghost" size="sm" icon="add" :href="route('users.roles.edit', $user)" modal="md">Thêm</x-ui.button>
                        @endcan
                    </header>
                    <div class="space-y-md p-lg">
                        <div class="flex flex-wrap gap-xs">
                            <x-ui.badge color="primary" :dot="false" title="Vai trò chính">{{ $primaryRole ? \App\Helpers\AclHelper::shortRoleLabel($primaryRole) : 'Người dùng' }}</x-ui.badge>
                            @forelse ($user->roles->slice(1) as $extraRole)
                                <x-ui.badge color="secondary" :dot="false">Kiêm nhiệm: {{ \App\Helpers\AclHelper::shortRoleLabel($extraRole->name) }}</x-ui.badge>
                            @empty
                                <span class="font-caption text-caption italic text-on-surface-variant">Không kiêm nhiệm vai trò khác</span>
                            @endforelse
                        </div>

                        <ul class="space-y-sm">
                            @forelse ($teachingClasses as $tc)
                                <li class="flex items-center justify-between gap-sm rounded-lg bg-surface-container-low px-md py-sm">
                                    <div class="min-w-0">
                                        <p class="truncate font-body-small text-body-small font-semibold text-on-surface" title="{{ $tc->code }} — {{ $tc->name }}">{{ $tc->code }} — {{ $tc->name }}</p>
                                        <p class="font-code text-caption text-on-surface-variant">{{ $tc->start_date?->format('d/m/Y') ?? '?' }} – {{ $tc->end_date?->format('d/m/Y') ?? '?' }}</p>
                                    </div>
                                    <x-ui.badge color="secondary" :dot="false" class="shrink-0">
                                        {{ $tc->teacher_id === $user->id ? 'Giáo viên' : ($tc->foreign_teacher_id === $user->id ? 'GVNN' : 'Trợ giảng') }}
                                    </x-ui.badge>
                                </li>
                            @empty
                                <li class="py-sm text-center font-body-small text-body-small italic text-on-surface-variant">Chưa phụ trách lớp nào.</li>
                            @endforelse
                        </ul>

                        <p class="flex items-start gap-xs font-caption text-caption text-on-surface-variant">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">info</span>
                            Kiêm nhiệm không phát sinh thêm lương cơ bản; tính theo giờ dạy thực tế.
                        </p>
                    </div>
                </section>

                {{-- Thao tác tài khoản --}}
                <section class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                    <header class="border-b border-surface-container px-lg py-md">
                        <h2 class="font-h3 text-h3 text-on-surface">Thao tác tài khoản</h2>
                    </header>
                    <div class="flex flex-col gap-sm p-lg">
                        @can('user.update')
                            <x-ui.button variant="secondary" icon="edit" :href="route('users.edit', $user)" modal="3xl" class="w-full">Sửa thông tin</x-ui.button>
                        @endcan
                        @can('user.assign_role')
                            <x-ui.button variant="secondary" icon="badge" :href="route('users.roles.edit', $user)" modal="md" class="w-full">Gán vai trò chức vụ</x-ui.button>
                        @endcan
                        @can('permission.override')
                            <x-ui.button variant="secondary" icon="admin_panel_settings" :href="route('users.permissions.edit', $user)" modal="4xl" class="w-full">Phân quyền chi tiết</x-ui.button>
                        @endcan
                        @can('user.reset_password')
                            <form action="{{ route('users.reset-password', $user) }}" method="POST" data-confirm="Đặt lại mật khẩu cho {{ $user->name }}?">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" icon="key" class="w-full">Đặt lại mật khẩu</x-ui.button>
                            </form>
                        @endcan
                        @can('user.lock')
                            <form action="{{ $user->isLocked() ? route('users.unlock', $user) : route('users.lock', $user) }}" method="POST"
                                  data-confirm="{{ $user->isLocked() ? 'Kích hoạt lại' : 'Vô hiệu hóa' }} tài khoản {{ $user->name }}?">
                                @csrf
                                <x-ui.button type="submit" :variant="$user->isLocked() ? 'secondary' : 'danger-text'" :icon="$user->isLocked() ? 'check_circle' : 'block'" class="w-full">
                                    {{ $user->isLocked() ? 'Kích hoạt lại tài khoản' : 'Vô hiệu hóa tài khoản' }}
                                </x-ui.button>
                            </form>
                        @endcan
                        @if (! auth()->user()->canAny(['user.update', 'user.assign_role', 'permission.override', 'user.reset_password', 'user.lock']))
                            <p class="py-sm text-center font-body-small text-body-small italic text-on-surface-variant">Bạn chỉ có quyền xem thông tin.</p>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
</x-app-layout>
