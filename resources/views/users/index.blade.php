{{-- Quản lý Tài khoản & Vai trò (mockup epic-5/quan-ly-tai-khoan-vai-tro).
     "Vai trò & kiêm nhiệm" mở modal (htmx); lưu xong server phát "users-changed" → #user-list tự tải lại. Xóa tài khoản qua modal xác nhận. --}}
@php
    $viewer = auth()->user();
    $filtered = request()->hasAny(['search', 'branch_id', 'role', 'status']);
@endphp
<x-app-layout title="Quản lý Tài khoản & Vai trò">
    <div x-data="{ drawerOpen: false, activeUser: null, del: { url: '', name: '' }, openProfile(u) { this.activeUser = u; this.drawerOpen = true; } }">
        <x-ui.page-header title="Quản lý Tài khoản & Vai trò" description="Danh sách nhân sự, vai trò chính và kiêm nhiệm, hợp đồng lao động.">
            <x-slot:actions>
                @can('user.create')
                    <x-ui.button icon="person_add" :href="route('users.create')">Thêm nhân viên mới</x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>

        @if ($errors->any())
            <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
        @endif

        <div class="mb-md grid grid-cols-2 gap-md md:grid-cols-4">
            <x-ui.stat-card label="Tổng nhân sự" :value="$totalStaff" icon="group" />
            <x-ui.stat-card label="Đang hoạt động" :value="$activeStaff" icon="check_circle" tone="success" />
            <x-ui.stat-card label="Khối học thuật" :value="$academicStaff" icon="school" tone="secondary" />
            <x-ui.stat-card label="Vô hiệu hóa" :value="$lockedStaff" icon="block" tone="error" />
        </div>

        @if ($expiringContracts > 0)
            <x-ui.alert type="warning" class="mb-md">
                {{ $expiringContracts }} nhân sự có hợp đồng đã hết hạn hoặc hết hạn trong {{ \App\Models\User::CONTRACT_WARNING_DAYS }} ngày tới —
                <a class="font-semibold underline" href="{{ route('users.index', ['status' => 'contract_expiring']) }}">lọc danh sách</a>.
            </x-ui.alert>
        @endif

        <div id="user-list" hx-get="{{ route('users.index', request()->query()) }}" hx-trigger="users-changed from:body" hx-select="#user-list" hx-swap="outerHTML">
        <x-ui.data-table min-width="860px">
            <x-slot:header>
                <form method="GET" action="{{ route('users.index') }}" class="flex w-full flex-col gap-sm md:flex-row md:items-center">
                    <div class="flex-1"><x-ui.input name="search" icon="search" :value="request('search')" placeholder="Tìm kiếm nhân viên (họ tên, email, SĐT, mã NV)..." aria-label="Tìm kiếm" /></div>
                    <x-ui.select name="branch_id" :options="$branches->pluck('name', 'id')" placeholder="Tất cả cơ sở" aria-label="Cơ sở" />
                    <x-ui.select name="role" :options="$roles->mapWithKeys(fn ($r) => [$r => \App\Helpers\AclHelper::shortRoleLabel($r)])" placeholder="Tất cả vai trò" aria-label="Vai trò" />
                    <x-ui.select name="status" :options="['active' => 'Đang hoạt động', 'locked' => 'Vô hiệu hóa', 'contract_expiring' => 'HĐ sắp/đã hết hạn']" placeholder="Mọi trạng thái" aria-label="Trạng thái" />
                    <x-ui.button type="submit" variant="secondary" icon="filter_list">Bộ lọc</x-ui.button>
                    @if ($filtered)
                        <x-ui.button variant="ghost" icon="close" :href="route('users.index')" aria-label="Xóa bộ lọc" />
                    @endif
                </form>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Họ và tên</th>
                        <th>Vai trò</th>
                        <th>Cơ sở</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        @php
                            $payload = \App\Http\Controllers\UserController::profilePayload($user, $viewer, $teachingByUser[$user->id] ?? []);
                            $contractStatus = $user->contractExpiryStatus();
                        @endphp
                        <tr>
                            <td>
                                <div class="flex items-center gap-sm">
                                    <x-ui.avatar :name="$user->name" />
                                    <div class="min-w-0">
                                        <a href="{{ route('users.show', $user) }}" class="font-semibold text-on-surface hover:text-primary">{{ $user->name }}</a>
                                        <div class="font-code text-caption text-on-surface-variant">{{ $user->email }} · {{ $payload['employee_code'] }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-xs">
                                    @foreach ($user->roles as $i => $role)
                                        <x-ui.badge :color="$i === 0 ? 'primary' : 'neutral'" :dot="false" title="{{ $i === 0 ? 'Vai trò chính' : 'Kiêm nhiệm' }}">{{ \App\Helpers\AclHelper::shortRoleLabel($role->name) }}{{ $i > 0 ? ' (kiêm nhiệm)' : '' }}</x-ui.badge>
                                    @endforeach
                                </div>
                            </td>
                            <td>{{ $user->branch?->name ?? 'Chưa gán chi nhánh' }}</td>
                            <td>
                                <div class="flex flex-col items-start gap-xs">
                                    @if ($user->isLocked())
                                        <x-ui.badge color="error">Vô hiệu hóa</x-ui.badge>
                                    @else
                                        <x-ui.badge color="success">Đang hoạt động</x-ui.badge>
                                    @endif
                                    @if ($contractStatus === 'expired')
                                        <x-ui.badge color="error" :dot="false" title="Hợp đồng kết thúc {{ $user->contract_end_date->format('d/m/Y') }}">HĐ đã hết hạn</x-ui.badge>
                                    @elseif ($contractStatus === 'expiring')
                                        <x-ui.badge color="warning" :dot="false">HĐ sắp hết hạn {{ $user->contract_end_date->format('d/m/Y') }}</x-ui.badge>
                                    @endif
                                </div>
                            </td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-xs">
                                    @can('permission.override')
                                        <x-ui.button variant="ghost" size="sm" icon="admin_panel_settings" :href="route('users.permissions.edit', $user)" title="Phân quyền cá nhân" aria-label="Phân quyền cá nhân" />
                                    @endcan
                                    @can('user.lock')
                                        <form action="{{ $user->isLocked() ? route('users.unlock', $user) : route('users.lock', $user) }}" method="POST" class="inline">
                                            @csrf
                                            <x-ui.button type="submit" variant="ghost" size="sm" :icon="$user->isLocked() ? 'check_circle' : 'block'"
                                                         :title="$user->isLocked() ? 'Kích hoạt lại' : 'Vô hiệu hóa'" :aria-label="$user->isLocked() ? 'Kích hoạt lại' : 'Vô hiệu hóa'" />
                                        </form>
                                    @endcan
                                    <x-ui.button variant="ghost" size="sm" icon="visibility" title="Xem hồ sơ nhanh" aria-label="Xem hồ sơ nhanh"
                                                 x-on:click="openProfile({{ \Illuminate\Support\Js::from($payload) }})" />
                                    <div class="relative" x-data="{ more: false }">
                                        <x-ui.button variant="ghost" size="sm" icon="more_vert" aria-label="Thao tác khác" x-on:click="more = !more" x-on:click.outside="more = false" />
                                        <div x-show="more" x-cloak class="absolute right-0 z-20 mt-xs w-48 rounded-lg border border-outline-variant bg-surface-container-lowest py-xs text-left shadow-lg">
                                            @can('user.update')
                                                <a href="{{ route('users.edit', $user) }}" class="flex items-center gap-sm px-md py-xs font-body-small text-body-small hover:bg-surface-container-low"><span class="material-symbols-outlined text-[16px]">edit</span>Sửa thông tin</a>
                                            @endcan
                                            @can('user.assign_role')
                                                <a href="{{ route('users.roles.edit', $user) }}" hx-get="{{ route('users.roles.edit', $user) }}" hx-target="#remote-modal-body" hx-swap="innerHTML" data-modal-size="md" x-on:click="more = false"
                                                   class="flex items-center gap-sm px-md py-xs font-body-small text-body-small hover:bg-surface-container-low"><span class="material-symbols-outlined text-[16px]">badge</span>Vai trò & kiêm nhiệm</a>
                                            @endcan
                                            @can('user.reset_password')
                                                <form action="{{ route('users.reset-password', $user) }}" method="POST" onsubmit="return confirm('Đặt lại mật khẩu cho nhân viên này?');">
                                                    @csrf
                                                    <button type="submit" class="flex w-full items-center gap-sm px-md py-xs font-body-small text-body-small hover:bg-surface-container-low"><span class="material-symbols-outlined text-[16px]">key</span>Đặt lại mật khẩu</button>
                                                </form>
                                            @endcan
                                            @can('user.delete')
                                                <button type="button" class="flex w-full items-center gap-sm px-md py-xs font-body-small text-body-small text-error hover:bg-error-container/40"
                                                        data-url="{{ route('users.destroy', $user) }}" data-name="{{ $user->name }}"
                                                        x-on:click="more = false; del = { url: $el.dataset.url, name: $el.dataset.name }; $dispatch('open-modal', 'delete-user')"><span class="material-symbols-outlined text-[16px]">delete</span>Xóa tài khoản</button>
                                            @endcan
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="person_search" title="Không tìm thấy nhân viên nào phù hợp với bộ lọc" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>
                <x-ui.pagination :paginator="$users" unit="nhân viên" />
            </x-slot:footer>
        </x-ui.data-table>
        </div>

        {{-- Xác nhận xóa tài khoản (form thường: controller giữ redirect + flash như cũ) --}}
        @can('user.delete')
            <x-ui.modal name="delete-user" title="Xóa tài khoản nhân viên?" max-width="md">
                <p>Xóa tài khoản <strong class="font-semibold" x-text="del.name"></strong>?</p>
                <form id="delete-user-form" method="POST" :action="del.url">
                    @csrf @method('DELETE')
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'delete-user')">Hủy</x-ui.button>
                    <x-ui.button variant="danger" type="submit" form="delete-user-form" icon="delete">Xóa tài khoản</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endcan

        {{-- Hồ sơ nhanh (drawer) --}}
        <div x-show="drawerOpen" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="Hồ sơ nhân sự">
            <div class="absolute inset-0 bg-on-surface/40" x-on:click="drawerOpen = false"></div>
            <aside class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-surface-container-lowest shadow-xl" x-show="drawerOpen" x-transition>
                <header class="flex items-center justify-between border-b border-surface-container px-md py-sm">
                    <h2 class="font-h3 text-h3 text-on-surface">Hồ sơ nhân sự</h2>
                    <x-ui.button variant="ghost" icon="close" aria-label="Đóng" x-on:click="drawerOpen = false" />
                </header>
                <template x-if="activeUser">
                    <div class="flex-1 space-y-md overflow-y-auto p-md font-body-small text-body-small">
                        <div class="flex items-center gap-md rounded-lg bg-primary-light p-md">
                            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-primary-container font-h2 text-h2 text-white" x-text="activeUser.name.charAt(0)"></span>
                            <div class="min-w-0">
                                <p class="font-h3 text-h3 text-on-surface" x-text="activeUser.name"></p>
                                <p class="text-on-surface-variant" x-text="(activeUser.primary_role || 'Nhân viên') + ' · ' + (activeUser.branch ? activeUser.branch.name : 'Chưa gán chi nhánh')"></p>
                                <p class="font-code text-caption text-on-surface-variant" x-text="activeUser.email + ' · ' + activeUser.employee_code"></p>
                            </div>
                        </div>

                        <section class="space-y-sm rounded-lg border border-outline-variant p-md">
                            <h3 class="flex items-center gap-xs font-label text-label uppercase text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary-container">badge</span>Thông tin nhân sự</h3>
                            <dl class="grid grid-cols-2 gap-sm">
                                <div><dt class="text-on-surface-variant">Số điện thoại</dt><dd class="font-code" x-text="activeUser.phone || 'Chưa cập nhật'"></dd></div>
                                <div><dt class="text-on-surface-variant">Số CCCD</dt><dd class="font-code" x-text="'id_card_number' in activeUser ? (activeUser.id_card_number || 'Chưa cập nhật') : 'Không có quyền xem'"></dd></div>
                                <div><dt class="text-on-surface-variant">Tốt nghiệp</dt><dd x-text="activeUser.graduation_school || 'Chưa cập nhật'"></dd></div>
                                <div><dt class="text-on-surface-variant">Chứng chỉ</dt><dd x-text="activeUser.certificates || 'Chưa cập nhật'"></dd></div>
                                <div class="col-span-2"><dt class="text-on-surface-variant">Level giảng dạy</dt><dd x-text="activeUser.teaching_level || 'Chưa cập nhật'"></dd></div>
                            </dl>
                        </section>

                        <section class="space-y-sm rounded-lg border border-outline-variant p-md">
                            <div class="flex items-center justify-between">
                                <h3 class="flex items-center gap-xs font-label text-label uppercase text-on-surface"><span class="material-symbols-outlined text-[16px] text-secondary">contract</span>Hợp đồng lao động</h3>
                                <span class="rounded-full px-sm font-caption text-caption"
                                      :class="{ 'bg-error-container text-error': activeUser.contract_status === 'Đã hết hạn', 'bg-amber-100 text-amber-800': activeUser.contract_status === 'Sắp hết hạn', 'bg-tertiary-fixed/50 text-tertiary': activeUser.contract_status === 'Đang hiệu lực', 'bg-surface-container-high text-on-surface-variant': activeUser.contract_status === 'Chưa cập nhật' }"
                                      x-text="activeUser.contract_status"></span>
                            </div>
                            <dl class="grid grid-cols-2 gap-sm">
                                <div><dt class="text-on-surface-variant">Loại HĐ</dt><dd x-text="activeUser.contract_type || 'Chưa cập nhật'"></dd></div>
                                <div><dt class="text-on-surface-variant">Lương cơ bản</dt><dd class="font-code" x-text="'base_salary' in activeUser ? (activeUser.base_salary ? Number(activeUser.base_salary).toLocaleString('vi-VN') + 'đ' : 'Chưa cập nhật') : 'Không có quyền xem'"></dd></div>
                                <div><dt class="text-on-surface-variant">Ngày bắt đầu</dt><dd x-text="activeUser.contract_start_date || 'Chưa cập nhật'"></dd></div>
                                <div><dt class="text-on-surface-variant">Ngày kết thúc</dt><dd x-text="activeUser.contract_end_date || 'Chưa cập nhật'"></dd></div>
                            </dl>
                            <a x-show="activeUser.contract_url" :href="activeUser.contract_url" class="inline-flex items-center gap-xs font-semibold text-secondary hover:underline"><span class="material-symbols-outlined text-[16px]">download</span>Tải file hợp đồng</a>
                            <p x-show="!activeUser.contract_url" class="italic text-on-surface-variant">Chưa có file hợp đồng.</p>
                        </section>

                        <section class="space-y-sm rounded-lg border border-outline-variant p-md">
                            <h3 class="flex items-center gap-xs font-label text-label uppercase text-on-surface"><span class="material-symbols-outlined text-[16px] text-tertiary">co_present</span>Kiêm nhiệm</h3>
                            <div class="flex flex-wrap gap-xs">
                                <template x-for="r in activeUser.concurrent_roles" :key="r"><span class="rounded bg-surface-container-high px-sm font-caption text-caption" x-text="r"></span></template>
                                <span x-show="activeUser.concurrent_roles.length === 0" class="italic text-on-surface-variant">Không kiêm nhiệm vai trò khác.</span>
                            </div>
                            <ul class="space-y-xs">
                                <template x-for="c in activeUser.teaching" :key="c.code + c.role">
                                    <li class="flex items-center justify-between gap-sm rounded bg-surface-container-low px-sm py-xs">
                                        <span class="truncate" x-text="c.code + ' — ' + c.name"></span>
                                        <span class="shrink-0 font-caption text-caption text-secondary" x-text="c.role"></span>
                                    </li>
                                </template>
                            </ul>
                            <p x-show="activeUser.teaching.length === 0" class="italic text-on-surface-variant">Chưa phụ trách lớp nào.</p>
                        </section>
                    </div>
                </template>
                <footer class="flex gap-sm border-t border-surface-container bg-surface-container-low p-md">
                    <template x-if="activeUser"><x-ui.button variant="secondary" class="flex-1" x-bind:href="activeUser.show_url" href="#">Xem chi tiết</x-ui.button></template>
                    @can('permission.override')
                        <template x-if="activeUser"><a :href="'{{ url('/users') }}/' + activeUser.id + '/permissions'" class="inline-flex flex-1 items-center justify-center rounded-lg bg-primary-container px-md py-sm font-body-medium text-body-medium text-white hover:bg-primary">Phân quyền</a></template>
                    @endcan
                </footer>
            </aside>
        </div>
    </div>
</x-app-layout>
