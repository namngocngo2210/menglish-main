{{-- Phân quyền cá nhân (mockup epic-5/phan-quyen-chi-tiet-ca-nhan; RBAC — docs/rbac.md): cùng ma trận với màn Vai trò.
     Mỗi quyền: theo vai trò / cấp thêm / thu hồi (checkbox); mỗi module: phạm vi dữ liệu theo vai trò hoặc riêng người này;
     Lớp học còn "Phạm vi áp dụng" theo chi nhánh / lớp cụ thể. --}}
@php
    use App\Support\PermissionCatalog;
    $columns = PermissionCatalog::matrixColumns();
    $levelLabels = PermissionCatalog::scopeLevelLabels();
@endphp
<x-app-layout title="Phân quyền cá nhân">
    <x-ui.page-header :title="'Cấu hình quyền chi tiết — '.$user->name">
        <x-slot:breadcrumbs>
            <a href="{{ route('users.index') }}" class="inline-flex items-center gap-xs hover:text-primary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">home</span>Nhân sự</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span>Phân quyền cá nhân</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @can('activity_log.view')
                <x-ui.button variant="secondary" icon="history" :href="route('activity-logs.index', ['search' => $user->name, 'log_name' => 'Người dùng & Phân quyền'])">Xem nhật ký</x-ui.button>
            @endcan
            <x-ui.button type="submit" form="permissionOverrideForm" icon="save">Lưu thay đổi</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert type="info" title="Ghi chú bảo mật quan trọng" class="mb-md">
        Mọi thay đổi về phân quyền sẽ được hệ thống tự động ghi lại vào Nhật ký vận hành bao gồm: Người thực hiện, Thời gian, và Nội dung thay đổi chi tiết (trước / sau). Phân quyền cá nhân thắng quyền theo vai trò: "Thu hồi" chặn quyền vai trò đang cấp, "Cấp thêm" mở quyền vai trò không có.
    </x-ui.alert>
    @if ($user->isSuperAdmin())
        <x-ui.alert type="warning" class="mb-md">Tài khoản Super Admin luôn có toàn quyền thao tác (phân quyền cá nhân không thu hẹp được) — chỉ các quyền "đối tượng" có tác dụng.</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="mb-md flex flex-wrap items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
        <x-ui.avatar :name="$user->name" />
        <div class="min-w-0 flex-1">
            <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ $user->name }} <span class="font-code text-caption text-on-surface-variant">· {{ $user->employee_code ?: 'NV-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT) }}</span></p>
            <p class="font-body-small text-body-small text-on-surface-variant">{{ $user->branch?->name ?? 'Chưa gán chi nhánh' }} · {{ $user->email }}</p>
        </div>
        <div class="flex flex-wrap gap-xs">
            @foreach ($user->roles as $i => $role)
                <x-ui.badge :color="$i === 0 ? 'primary' : 'neutral'" :dot="false">{{ \App\Helpers\AclHelper::shortRoleLabel($role->name) }}{{ $i > 0 ? ' (kiêm nhiệm)' : '' }}</x-ui.badge>
            @endforeach
        </div>
        @can('user.assign_role')
            <x-ui.button size="sm" variant="ghost" icon="tune" :href="route('users.roles.edit', $user)">Vai trò</x-ui.button>
        @endcan
    </div>

    <form id="permissionOverrideForm" method="POST" action="{{ route('users.permissions.update', $user) }}">
        @csrf
        @method('PUT')
        <x-ui.data-table min-width="1100px">
            <x-slot:header>
                <div class="flex w-full flex-wrap items-center justify-between gap-sm">
                    <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">grid_view</span>Ma trận phân quyền chi tiết
                    </h2>
                    <div class="flex items-center gap-xs font-body-small text-body-small">
                        <span class="text-on-surface-variant">Chọn nhanh:</span>
                        <x-ui.button size="sm" variant="secondary" x-data x-on:click="$dispatch('perm-set-all', true)">Chọn tất cả</x-ui.button>
                        <x-ui.button size="sm" variant="secondary" x-data x-on:click="$dispatch('perm-set-all', false)">Bỏ chọn</x-ui.button>
                    </div>
                </div>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Danh mục Module</th>
                        @foreach ($columns as $actionKey => $actionLabel)
                            <th class="cursor-pointer select-none text-center" title="Nhấn đúp để áp dụng nhanh cho toàn bộ cột"
                                x-data="{ v: true }" x-on:dblclick="$dispatch('perm-col', { action: @js($actionKey), value: v }); v = !v">{{ $actionLabel }}</th>
                        @endforeach
                        <th class="min-w-[180px]">Phạm vi dữ liệu</th>
                        <th>Phạm vi áp dụng</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $groupLabel => $modules)
                    <tr class="bg-surface-container-low" data-permission-group="{{ $groupLabel }}">
                        <td colspan="{{ 3 + count($columns) }}" class="font-label text-label uppercase text-on-surface-variant">{{ $groupLabel }}</td>
                    </tr>
                    @foreach ($modules as $module => $buckets)
                        @php
                            $byKey = collect($buckets['actions'])->keyBy(fn ($p) => PermissionCatalog::keyOf($p));
                            $extra = collect($buckets['actions'])->reject(fn ($p) => array_key_exists(PermissionCatalog::keyOf($p), $columns))
                                ->merge($buckets['dynamic'])->merge($buckets['audience'])->values();
                            $scope = $scopes->get($module, ['type' => 'all', 'ids' => []]);
                            $scopeType = old("scope.{$module}.type", $scope['type']);
                            $scopeIds = collect(old("scope.{$module}.ids", $scope['ids']))->map(fn ($id) => (int) $id)->all();
                            $supportsScope = \App\Models\UserPermissionOverride::supportsScope($module);
                            $hasAccess = in_array($module, $modulesWithAccess, true);
                            $dataScope = $dataScopes->get($module);
                        @endphp
                        <tr class="align-top" x-data="{ scopeType: @js($scopeType) }" data-module="{{ $module }}">
                            <td>
                                <div class="flex items-start gap-sm">
                                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">{{ config("permission_catalog.modules.{$module}.icon", 'apps') }}</span>
                                    <div>
                                        <div class="font-semibold text-on-surface">{{ PermissionCatalog::moduleLabel($module) }}</div>
                                        @if ($extra->isNotEmpty())
                                            <details class="mt-xs" @if ($groupLabel === 'Kế toán / Học phí') open @endif>
                                                <summary class="cursor-pointer font-caption text-caption font-semibold text-secondary">Thao tác khác ({{ $extra->count() }})</summary>
                                                <div class="mt-xs space-y-xs">
                                                    @foreach ($extra as $permission)
                                                        @include('users.partials.permission-cell', ['module' => $module, 'action' => PermissionCatalog::keyOf($permission), 'label' => PermissionCatalog::label($permission), 'inline' => true])
                                                    @endforeach
                                                </div>
                                            </details>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            @foreach (array_keys($columns) as $action)
                                <td class="text-center">
                                    @if ($byKey->has($action))
                                        @include('users.partials.permission-cell', ['module' => $module, 'action' => $action, 'label' => null, 'inline' => false])
                                    @else
                                        <span class="text-outline" title="Module không có quyền này">—</span>
                                    @endif
                                </td>
                            @endforeach
                            <td>
                                @if ($dataScope)
                                    @php $chosen = old("data_scope.{$module}", $dataScope['personal']); @endphp
                                    <select name="data_scope[{{ $module }}]" aria-label="Phạm vi dữ liệu {{ PermissionCatalog::moduleLabel($module) }}"
                                            class="w-full rounded-lg border border-outline-variant py-xs pl-sm pr-lg font-body-small text-body-small">
                                        <option value="inherit" @selected($chosen === 'inherit')>Theo vai trò ({{ $levelLabels[$dataScope['role']] ?? $dataScope['role'] }})</option>
                                        @foreach ($dataScope['levels'] as $level)
                                            <option value="{{ $level }}" @selected($chosen === $level) title="{{ PermissionCatalog::scopeLevelDescription($module, $level) }}">{{ $levelLabels[$level] ?? $level }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-xs font-caption text-caption text-on-surface-variant">Hiệu lực: {{ $levelLabels[$dataScope['effective']] ?? $dataScope['effective'] }} — {{ PermissionCatalog::scopeLevelDescription($module, $dataScope['effective']) }}</p>
                                @else
                                    <span class="font-body-small text-body-small text-on-surface-variant">—</span>
                                @endif
                            </td>
                            <td class="min-w-[230px]">
                                @if ($supportsScope)
                                    <select name="scope[{{ $module }}][type]" x-model="scopeType" aria-label="Phạm vi áp dụng"
                                            class="w-full rounded-lg border border-outline-variant py-xs pl-sm pr-lg font-body-small text-body-small">
                                        <option value="all">Toàn hệ thống (Mặc định)</option>
                                        <option value="branch">Theo chi nhánh</option>
                                        <option value="class">Theo lớp</option>
                                    </select>
                                    <select name="scope[{{ $module }}][ids][]" multiple x-show="scopeType === 'branch'" :disabled="scopeType !== 'branch'" aria-label="Chi nhánh"
                                            class="mt-xs h-20 w-full rounded-lg border border-outline-variant font-body-small text-body-small">
                                        @foreach ($branches as $branch)
                                            <option value="{{ $branch->id }}" @selected($scopeType === 'branch' && in_array($branch->id, $scopeIds, true))>{{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                    <select name="scope[{{ $module }}][ids][]" multiple x-show="scopeType === 'class'" :disabled="scopeType !== 'class'" aria-label="Lớp"
                                            class="mt-xs h-20 w-full rounded-lg border border-outline-variant font-body-small text-body-small">
                                        @foreach ($classes as $class)
                                            <option value="{{ $class->id }}" @selected($scopeType === 'class' && in_array($class->id, $scopeIds, true))>{{ $class->name }}{{ $class->code ? " ({$class->code})" : '' }}</option>
                                        @endforeach
                                    </select>
                                    @if ($scopeType !== 'all' && ! empty($scopeIds))
                                        <div class="mt-xs flex flex-wrap gap-xs">
                                            @foreach (($scopeType === 'branch' ? $branches : $classes)->whereIn('id', $scopeIds) as $unit)
                                                <span class="rounded bg-secondary-fixed/60 px-sm font-caption text-caption text-secondary">{{ $unit->name }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    <p class="mt-xs font-caption text-caption text-on-surface-variant">Cấp riêng Xem / Sửa / Xóa cho chi nhánh / lớp cụ thể. Giữ Ctrl/⌘ để chọn nhiều.</p>
                                @elseif ($hasAccess)
                                    <span class="inline-flex items-center gap-xs font-body-small text-body-small text-on-surface-variant" title="Phân hệ này không cấp riêng theo chi nhánh/lớp cụ thể — dùng Phạm vi dữ liệu">
                                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">language</span>Toàn hệ thống (Mặc định)
                                    </span>
                                @else
                                    <span class="font-body-small text-body-small italic text-on-surface-variant">Không có quyền truy cập</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @endforeach
                </tbody>
            </table>
            <x-slot:footer>
                <div class="flex flex-col gap-sm px-md py-md md:flex-row md:items-center md:justify-between">
                    <p class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">info</span>
                        Nhấn đúp vào tiêu đề cột để áp dụng nhanh cho toàn bộ cột. "Phạm vi dữ liệu" (Của tôi / Chi nhánh / Toàn hệ thống) thay mức của vai trò cho riêng người này.
                    </p>
                    <div class="flex gap-sm">
                        <x-ui.button variant="secondary" x-data x-on:click="$dispatch('perm-reset')">Đặt lại mặc định</x-ui.button>
                        <x-ui.button type="submit">Lưu phân quyền</x-ui.button>
                    </div>
                </div>
            </x-slot:footer>
        </x-ui.data-table>
    </form>

    <div class="mt-md grid grid-cols-1 gap-md sm:grid-cols-3">
        <x-ui.stat-card label="Tổng số Module" :value="str_pad((string) $moduleCount, 2, '0', STR_PAD_LEFT).' danh mục'" icon="apps" />
        <x-ui.stat-card label="Quyền truy cập" :value="$effectiveCount.' thao tác cho phép'" icon="verified_user" tone="primary" />
        <x-ui.stat-card label="Phạm vi dữ liệu" :value="str_pad((string) $scopeUnitCount, 2, '0', STR_PAD_LEFT).' đơn vị quản lý'" icon="domain" tone="secondary"
                        :hint="$scopeUnitCount === 0 ? 'Toàn hệ thống theo vai trò' : null" />
    </div>
</x-app-layout>
