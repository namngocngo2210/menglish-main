<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserPermissionOverrideRequest;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;

/**
 * Màn "Phân quyền chi tiết theo cá nhân" (mockup epic-5/phan-quyen-chi-tiet-ca-nhan):
 * ma trận module x (Xem / Thêm / Sửa / Xóa + quyền khác), mỗi module chọn
 * phạm vi áp dụng: Toàn hệ thống, theo Chi nhánh hoặc theo Lớp.
 *
 * Nơi phạm vi được kiểm tra thật (UserPermissionOverride::SCOPE_ENFORCED):
 *  - class.view/update/delete: ClassModel::scopeVisibleTo() lọc danh sách lớp,
 *    ClassModel::userCan() chặn sửa/xóa lớp ngoài phạm vi; Gate cho phép vào
 *    route khi có override phạm vi (AppServiceProvider).
 * Module khác chỉ lưu override "Toàn hệ thống" (Gate::before xử lý).
 */
class UserPermissionOverrideController extends Controller
{
    public function edit(User $user): View
    {
        $this->ensureCanOverride($user);

        $permissionsByModule = Permission::query()->orderBy('name')->get()
            ->groupBy(fn (Permission $permission) => explode('.', $permission->name)[0]);

        $rolePermissions = $user->getAllPermissions()->pluck('name')->all();

        $allOverrides = $user->permissionOverrides()->get();

        // Quyết định (allow/deny) theo module.action — lấy từ bất kỳ phạm vi nào
        // (mỗi module chỉ dùng một kiểu phạm vi).
        $overrides = $allOverrides->groupBy(fn (UserPermissionOverride $o) => "{$o->module}.{$o->action}")
            ->map(fn ($rows) => $rows->first());

        // Phạm vi đang áp dụng theo module: ['type' => all|branch|class, 'ids' => [...]]
        $scopes = $allOverrides->groupBy('module')->map(function ($rows) {
            $scoped = $rows->firstWhere('scope_type', '!=', UserPermissionOverride::SCOPE_ALL);

            return $scoped
                ? [
                    'type' => $scoped->scope_type,
                    'ids' => $rows->where('scope_type', $scoped->scope_type)->pluck('scope_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
                ]
                : ['type' => UserPermissionOverride::SCOPE_ALL, 'ids' => []];
        });

        $branches = Branch::query()->orderBy('name')->get(['id', 'name']);
        $classes = ClassModel::query()->where('status', '!=', 'cancelled')->orderBy('name')->get(['id', 'name', 'code', 'branch_id']);

        // Thẻ tổng kết (mockup): số thao tác đang được phép sau phân quyền cá nhân, số đơn vị phạm vi dữ liệu.
        $effective = $permissionsByModule->flatten()->filter(function (Permission $permission) use ($overrides, $rolePermissions) {
            $override = $overrides->get($permission->name);

            return $override ? (bool) $override->allow : in_array($permission->name, $rolePermissions, true);
        });
        $effectiveCount = $effective->count();
        $modulesWithAccess = $effective->map(fn (Permission $p) => explode('.', $p->name)[0])->unique()->values()->all();
        $scopeUnitCount = $scopes->sum(fn (array $scope) => count($scope['ids']));

        return view('users.permissions', compact(
            'user', 'permissionsByModule', 'rolePermissions', 'overrides', 'scopes', 'branches', 'classes',
            'effectiveCount', 'modulesWithAccess', 'scopeUnitCount'
        ));
    }

    public function update(UserPermissionOverrideRequest $request, User $user): RedirectResponse
    {
        $this->ensureCanOverride($user);

        $data = $request->validated('overrides', []);
        $scopeInput = $request->validated('scope', []);
        $summary = [];

        DB::transaction(function () use ($data, $scopeInput, $user, $request, &$summary) {
            foreach ($data as $module => $actions) {
                $scopeType = $scopeInput[$module]['type'] ?? UserPermissionOverride::SCOPE_ALL;
                $scopeIds = collect($scopeInput[$module]['ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->unique()->values();

                if ($scopeType !== UserPermissionOverride::SCOPE_ALL) {
                    $this->validateScope($module, $actions, $scopeType, $scopeIds->all());
                }

                foreach ($actions as $action => $decision) {
                    // Ghi lại toàn bộ override (mọi phạm vi) của quyền được gửi lên.
                    $user->permissionOverrides()->where('module', $module)->where('action', $action)->delete();

                    if ($decision === 'inherit') {
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
        });

        activity('Người dùng & Phân quyền')->causedBy($request->user())->performedOn($user)
            ->event('updated')
            ->withProperties(['overrides' => $data, 'scope' => $scopeInput, 'summary' => $summary])
            ->log('Cập nhật phân quyền chi tiết cá nhân');

        return redirect()->route('users.index')->with('status', 'Đã cập nhật phân quyền chi tiết của '.$user->name.'.');
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
     * Không cho tự cấp quyền cho chính mình; chỉ Admin được chỉnh quyền của Admin.
     */
    private function ensureCanOverride(User $target): void
    {
        $actor = auth()->user();
        abort_if($actor && (int) $actor->id === (int) $target->id && ! $actor->hasRole('admin'), 403, 'Không thể tự phân quyền cho chính mình.');
        abort_if($target->hasRole('admin') && ! $actor?->hasRole('admin'), 403, 'Chỉ Admin được chỉnh quyền của Admin.');
    }
}
