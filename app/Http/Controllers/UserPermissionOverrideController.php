<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserPermissionOverrideRequest;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;

class UserPermissionOverrideController extends Controller
{
    /**
     * Màn "Phân quyền chi tiết theo cá nhân": ma trận module x action, so
     * sánh với quyền đang có theo role để hiển thị rõ đâu là quyền gốc,
     * đâu là quyền đã được điều chỉnh riêng cho cá nhân này.
     */
    public function edit(User $user): View
    {
        $permissionsByModule = Permission::query()->orderBy('name')->get()
            ->groupBy(fn (Permission $permission) => explode('.', $permission->name)[0]);

        $rolePermissions = $user->getAllPermissions()->pluck('name')->all();

        $overrides = $user->permissionOverrides()
            ->where('scope_type', UserPermissionOverride::SCOPE_ALL)
            ->get()
            ->keyBy(fn (UserPermissionOverride $o) => "{$o->module}.{$o->action}");

        return view('users.permissions', compact('user', 'permissionsByModule', 'rolePermissions', 'overrides'));
    }

    public function update(UserPermissionOverrideRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated('overrides', []);

        foreach ($data as $module => $actions) {
            foreach ($actions as $action => $decision) {
                if ($decision === 'inherit') {
                    $user->permissionOverrides()
                        ->where('module', $module)->where('action', $action)
                        ->where('scope_type', UserPermissionOverride::SCOPE_ALL)
                        ->delete();

                    continue;
                }

                $user->permissionOverrides()->updateOrCreate(
                    [
                        'module' => $module,
                        'action' => $action,
                        'scope_type' => UserPermissionOverride::SCOPE_ALL,
                        'scope_id' => null,
                    ],
                    [
                        'allow' => $decision === 'allow',
                        'created_by' => $request->user()->id,
                    ]
                );
            }
        }

        activity('permission_override')->causedBy($request->user())->performedOn($user)
            ->withProperties(['overrides' => $data])
            ->log('Cập nhật phân quyền chi tiết cá nhân');

        return redirect()->route('users.index')->with('status', 'Đã cập nhật phân quyền chi tiết.');
    }
}
