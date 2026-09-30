<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Http\Concerns\RendersModals;
use App\Http\Requests\UserPermissionOverrideRequest;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\DataScope;
use App\Support\PermissionCatalog;
use App\Support\Rbac;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Response as InertiaResponse;
use Spatie\Permission\Models\Permission;

/**
 * Màn "Phân quyền chi tiết theo cá nhân" (mockup epic-5/phan-quyen-chi-tiet-ca-nhan; RBAC — docs/rbac.md):
 * cùng ma trận với màn Vai trò; mỗi quyền: theo vai trò (kế thừa) / cho phép / chặn; mỗi module có phạm vi dữ liệu:
 * theo vai trò / Của tôi / Chi nhánh / Toàn hệ thống (lưu thành override trên quyền "module.scope_*").
 *
 * Riêng Lớp học còn "Phạm vi áp dụng" theo chi nhánh / lớp cụ thể (UserPermissionOverride::SCOPE_ENFORCED):
 * ClassModel::scopeVisibleTo() lọc danh sách lớp, ClassModel::userCan() chặn sửa/xóa lớp ngoài phạm vi.
 *
 * Mọi thay đổi ghi nhật ký trước / sau, không tự phân quyền cho chính mình, chỉ Super Admin chỉnh quyền Super Admin.
 */
class UserPermissionOverrideController extends Controller
{
    use RendersModals;

    /** Ký tự thay dấu "." trong tên action động (user.assign_role.<vai trò>) trên form (khóa mảng không chứa "."). */
    public const ACTION_DOT = ':';

    /** Mở từ danh sách nhân sự → modal 4xl; mở thẳng URL → trang đầy đủ (kèm thẻ số liệu). */
    public function edit(User $user): InertiaResponse
    {
        $this->ensureCanOverride($user);

        $allNames = $this->matrixPermissionNames();
        $groups = PermissionCatalog::grouped($allNames);

        $rolePermissions = $user->getAllPermissions()->pluck('name')->all();
        $targetIsSuperAdmin = $user->isSuperAdmin();
        if ($targetIsSuperAdmin) {
            $rolePermissions = array_values(array_unique(array_merge($rolePermissions,
                $allNames->reject(fn (string $n) => PermissionCatalog::isAudience($n))->all())));
        }

        $allOverrides = $user->permissionOverrides()->get();

        // Quyết định (allow/deny) theo module.action — lấy từ bất kỳ phạm vi nào (mỗi module chỉ dùng một kiểu phạm vi).
        $overrides = $allOverrides->groupBy(fn (UserPermissionOverride $o) => "{$o->module}.{$o->action}")
            ->map(fn ($rows) => $rows->first());

        // Phạm vi áp dụng (chi nhánh / lớp cụ thể — chỉ Lớp học): ['type' => all|branch|class, 'ids' => [...]]
        $scopes = $allOverrides->groupBy('module')->map(function ($rows) {
            $scoped = $rows->firstWhere('scope_type', '!=', UserPermissionOverride::SCOPE_ALL);

            return $scoped
                ? [
                    'type' => $scoped->scope_type,
                    'ids' => $rows->where('scope_type', $scoped->scope_type)->pluck('scope_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
                ]
                : ['type' => UserPermissionOverride::SCOPE_ALL, 'ids' => []];
        });

        // Phạm vi dữ liệu: mức theo vai trò, mức cá nhân đang chọn (override), mức hiệu lực.
        $dataScopes = collect(PermissionCatalog::scopedModules())->map(fn (array $levels, string $module) => [
            'levels' => $levels,
            'role' => $targetIsSuperAdmin ? DataScope::ALL : $this->roleLevel($levels, $module, $rolePermissions),
            'personal' => $this->personalLevel($levels, $module, $allOverrides),
            'effective' => DataScope::level($user, $module),
        ]);

        $branches = Branch::query()->orderBy('name')->get(['id', 'name']);
        $classes = ClassModel::query()->where('status', '!=', 'cancelled')->orderBy('name')->get(['id', 'name', 'code', 'branch_id']);

        // Thẻ tổng kết (mockup): số thao tác đang được phép sau phân quyền cá nhân, số đơn vị phạm vi dữ liệu.
        $effective = $allNames->filter(function (string $permission) use ($overrides, $rolePermissions) {
            $override = $overrides->get($permission);

            return $override ? (bool) $override->allow : in_array($permission, $rolePermissions, true);
        });
        $effectiveCount = $effective->count();
        $modulesWithAccess = $effective->map(fn (string $p) => PermissionCatalog::moduleOf($p))->unique()->values()->all();
        $scopeUnitCount = $scopes->sum(fn (array $scope) => count($scope['ids']));
        $moduleCount = collect($groups)->sum(fn ($modules) => count($modules));

        $user->loadMissing(['branch', 'roles']);
        $columns = PermissionCatalog::matrixColumns();
        $levelLabels = PermissionCatalog::scopeLevelLabels();

        // Một ô ma trận: quyết định hiện tại (inherit / allow / deny) + vai trò gốc có quyền không.
        $cell = function (string $module, string $permission, bool $withLabel) use ($overrides, $rolePermissions) {
            $action = PermissionCatalog::keyOf($permission);
            $override = $overrides->get($permission);

            return [
                'name' => $permission,
                'action' => $action,
                'formKey' => str_replace('.', self::ACTION_DOT, $action),
                'label' => $withLabel ? PermissionCatalog::label($permission) : null,
                'audience' => PermissionCatalog::isAudience($permission),
                'description' => PermissionCatalog::description($permission),
                'role' => in_array($permission, $rolePermissions, true),
                'decision' => $override?->allow === true ? 'allow' : ($override?->allow === false ? 'deny' : 'inherit'),
            ];
        };

        $matrix = collect($groups)->map(fn (array $modules, string $groupLabel) => [
            'label' => $groupLabel,
            'modules' => collect($modules)->map(function (array $buckets, string $module) use ($columns, $cell, $scopes, $dataScopes, $levelLabels, $modulesWithAccess, $groupLabel) {
                $byKey = collect($buckets['actions'])->keyBy(fn ($p) => PermissionCatalog::keyOf($p));
                $extra = collect($buckets['actions'])->reject(fn ($p) => array_key_exists(PermissionCatalog::keyOf($p), $columns))
                    ->merge($buckets['dynamic'])->merge($buckets['audience'])->values();
                $dataScope = $dataScopes->get($module);

                return [
                    'module' => $module,
                    'label' => PermissionCatalog::moduleLabel($module),
                    'icon' => config("permission_catalog.modules.{$module}.icon", 'apps'),
                    'extraOpen' => $groupLabel === 'Kế toán / Học phí',
                    'cells' => collect(array_keys($columns))->mapWithKeys(fn (string $key) => [
                        $key => $byKey->has($key) ? $cell($module, $byKey->get($key), false) : null,
                    ])->all(),
                    'extra' => $extra->map(fn (string $permission) => $cell($module, $permission, true))->all(),
                    'dataScope' => $dataScope ? [
                        'roleLabel' => $levelLabels[$dataScope['role']] ?? $dataScope['role'],
                        'levels' => collect($dataScope['levels'])->map(fn (string $level) => [
                            'value' => $level,
                            'label' => $levelLabels[$level] ?? $level,
                            'title' => PermissionCatalog::scopeLevelDescription($module, $level),
                        ])->all(),
                        'personal' => $dataScope['personal'],
                        'effective' => ($levelLabels[$dataScope['effective']] ?? $dataScope['effective']).' — '.PermissionCatalog::scopeLevelDescription($module, $dataScope['effective']),
                    ] : null,
                    'supportsScope' => UserPermissionOverride::supportsScope($module),
                    'hasAccess' => in_array($module, $modulesWithAccess, true),
                    'scope' => $scopes->get($module, ['type' => UserPermissionOverride::SCOPE_ALL, 'ids' => []]),
                ];
            })->values()->all(),
        ])->values()->all();

        return $this->modalPage('Users/Permissions', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_code' => $user->employee_code ?: 'NV-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
                'branch_name' => $user->branch?->name,
                'roles' => $user->roles->map(fn ($role) => AclHelper::shortRoleLabel($role->name))->values()->all(),
            ],
            'targetIsSuperAdmin' => $targetIsSuperAdmin,
            'columns' => Ui::options($columns),
            'matrix' => $matrix,
            'branches' => Ui::options($branches, 'name'),
            'classes' => Ui::options($classes, fn (ClassModel $class) => $class->name.($class->code ? " ({$class->code})" : '')),
            'stats' => [
                'modules' => $moduleCount,
                'effective' => $effectiveCount,
                'scopeUnits' => $scopeUnitCount,
            ],
        ]);
    }

    public function update(UserPermissionOverrideRequest $request, User $user): Response|RedirectResponse
    {
        $this->ensureCanOverride($user);

        $data = $request->validated('overrides', []);
        $scopeInput = $request->validated('scope', []);
        $dataScopeInput = $request->validated('data_scope', []);
        $known = Permission::query()->where('guard_name', 'web')->pluck('name')->flip();
        $before = $this->snapshot($user);
        $summary = [];

        DB::transaction(function () use ($data, $scopeInput, $dataScopeInput, $user, $request, $known, &$summary) {
            foreach ($data as $module => $actions) {
                $scopeType = $scopeInput[$module]['type'] ?? UserPermissionOverride::SCOPE_ALL;
                $scopeIds = collect($scopeInput[$module]['ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->unique()->values();

                $actions = collect($actions)->mapWithKeys(fn ($decision, $action) => [str_replace(self::ACTION_DOT, '.', (string) $action) => $decision])->all();

                if ($scopeType !== UserPermissionOverride::SCOPE_ALL) {
                    $this->validateScope($module, $actions, $scopeType, $scopeIds->all());
                }

                foreach ($actions as $action => $decision) {
                    // Ghi lại toàn bộ override (mọi phạm vi) của quyền được gửi lên.
                    $user->permissionOverrides()->where('module', $module)->where('action', $action)->delete();

                    if ($decision === 'inherit' || ! $known->has("{$module}.{$action}")) {
                        continue;
                    }

                    $targets = $scopeType === UserPermissionOverride::SCOPE_ALL ? [null] : $scopeIds->all();
                    foreach ($targets as $scopeId) {
                        $user->permissionOverrides()->create([
                            'module' => $module,
                            'action' => $action,
                            'allow' => $decision === 'allow',
                            'scope_type' => $scopeType,
                            'scope_id' => $scopeId,
                            'created_by' => $request->user()->id,
                        ]);
                    }

                    $summary[] = "{$module}.{$action}={$decision}".($scopeType !== UserPermissionOverride::SCOPE_ALL ? " ({$scopeType}: ".$scopeIds->implode(',').')' : '');
                }
            }

            foreach ($dataScopeInput as $module => $choice) {
                $levels = PermissionCatalog::scopeLevels((string) $module);
                if ($levels === [] || ! in_array($choice, ['inherit', ...$levels], true)) {
                    continue;
                }
                $user->permissionOverrides()->where('module', $module)->where('action', 'like', 'scope_%')->delete();
                if ($choice === 'inherit') {
                    continue;
                }
                $rank = array_search($choice, $levels, true);
                foreach ($levels as $i => $level) {
                    // Mức đã chọn: cho phép; mức cao hơn: chặn (kể cả khi vai trò cấp); mức thấp hơn: không cần.
                    if ($i < $rank || ($i === 0 && $rank === 0 && ! $known->has(PermissionCatalog::scopePermission((string) $module, $level)))) {
                        continue;
                    }
                    $user->permissionOverrides()->create([
                        'module' => $module,
                        'action' => "scope_{$level}",
                        'allow' => $i === $rank,
                        'scope_type' => UserPermissionOverride::SCOPE_ALL,
                        'scope_id' => null,
                        'created_by' => $request->user()->id,
                    ]);
                }
                $summary[] = "{$module}.scope={$choice}";
            }
        });

        $user->unsetRelation('permissionOverrides');
        Rbac::flushCache();
        $after = $this->snapshot($user);

        activity('Người dùng & Phân quyền')->causedBy($request->user())->performedOn($user)
            ->event('updated')
            ->withProperties([
                'old' => ['overrides' => $before],
                'attributes' => ['overrides' => $after],
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
                'summary' => $summary,
            ])
            ->log('Cập nhật phân quyền chi tiết cá nhân');

        return $this->modalSaved('Đã cập nhật phân quyền chi tiết của '.$user->name.'.', 'users-changed', route('users.index'));
    }

    /** Mọi quyền hiển thị trên ma trận (trừ quyền gán vai trò Super Admin). */
    private function matrixPermissionNames(): Collection
    {
        return Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')
            ->reject(fn (string $name) => $name === Rbac::assignRolePermission(Rbac::SUPER_ADMIN))
            ->values();
    }

    /** @param  list<string>  $levels  @param  list<string>  $rolePermissions */
    private function roleLevel(array $levels, string $module, array $rolePermissions): string
    {
        foreach (array_reverse($levels) as $level) {
            if (in_array(PermissionCatalog::scopePermission($module, $level), $rolePermissions, true)) {
                return $level;
            }
        }

        return $levels[0];
    }

    /** Mức phạm vi cá nhân đang đặt (override) hoặc 'inherit'. */
    private function personalLevel(array $levels, string $module, Collection $overrides): string
    {
        $rows = $overrides->filter(fn (UserPermissionOverride $o) => $o->module === $module && str_starts_with($o->action, 'scope_')
            && $o->scope_type === UserPermissionOverride::SCOPE_ALL);
        if ($rows->isEmpty()) {
            return 'inherit';
        }
        $allowed = $rows->where('allow', true)->map(fn ($o) => substr($o->action, 6))->filter(fn ($l) => in_array($l, $levels, true));

        return collect($levels)->reverse()->first(fn ($l) => $allowed->contains($l)) ?? $levels[0];
    }

    /** @return list<string> "module.action[@scope:id]=allow|deny" */
    private function snapshot(User $user): array
    {
        return $user->permissionOverrides()->orderBy('module')->orderBy('action')->get()
            ->map(fn (UserPermissionOverride $o) => "{$o->module}.{$o->action}"
                .($o->scope_type !== UserPermissionOverride::SCOPE_ALL ? "@{$o->scope_type}:{$o->scope_id}" : '')
                .'='.($o->allow ? 'allow' : 'deny'))
            ->values()->all();
    }

    private function validateScope(string $module, array $actions, string $scopeType, array $ids): void
    {
        if (! UserPermissionOverride::supportsScope($module)) {
            throw ValidationException::withMessages([
                "scope.{$module}.type" => 'Phân hệ này chưa hỗ trợ phân quyền theo chi nhánh/lớp, chỉ áp dụng "Toàn hệ thống".',
            ]);
        }

        foreach ($actions as $action => $decision) {
            if ($decision !== 'inherit' && ! UserPermissionOverride::supportsScope($module, $action)) {
                throw ValidationException::withMessages([
                    "scope.{$module}.type" => "Quyền \"{$module}.{$action}\" chỉ áp dụng được cho toàn hệ thống.",
                ]);
            }
        }

        if (empty($ids)) {
            throw ValidationException::withMessages([
                "scope.{$module}.ids" => 'Vui lòng chọn ít nhất một '.($scopeType === UserPermissionOverride::SCOPE_BRANCH ? 'chi nhánh' : 'lớp').' cho phạm vi áp dụng.',
            ]);
        }

        $model = $scopeType === UserPermissionOverride::SCOPE_BRANCH ? Branch::class : ClassModel::class;
        if ($model::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages([
                "scope.{$module}.ids" => 'Phạm vi áp dụng chứa chi nhánh/lớp không tồn tại.',
            ]);
        }
    }

    /**
     * Không cho tự cấp quyền cho chính mình (Super Admin luôn toàn quyền nên không tự khóa được); chỉ Super Admin được
     * chỉnh quyền của Super Admin.
     */
    private function ensureCanOverride(User $target): void
    {
        $actor = auth()->user();
        abort_if($actor && (int) $actor->id === (int) $target->id && ! $actor->isSuperAdmin(), 403, 'Không thể tự phân quyền cho chính mình.');
        abort_if($target->isSuperAdmin() && ! $actor?->isSuperAdmin(), 403, 'Chỉ Admin được chỉnh quyền của Admin.');
    }
}
