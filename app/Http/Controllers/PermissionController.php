<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Http\Concerns\RendersModals;
use App\Http\Requests\PermissionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\Permission\Models\Permission;

/**
 * Danh mục quyền: Sửa mở modal, Xóa qua modal xác nhận; mở thẳng URL edit → trang form đầy đủ.
 */
class PermissionController extends Controller
{
    use RendersModals;

    public function index(Request $request): InertiaResponse
    {
        $permissions = Permission::query()
            ->withCount('roles')
            ->orderBy('name')
            ->paginate($request->perPage(20))
            ->withQueryString()
            ->through(fn (Permission $permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'action_label' => AclHelper::actionLabel($permission->name),
                'module_label' => AclHelper::moduleLabel(explode('.', $permission->name)[0]),
                'roles_count' => $permission->roles_count,
            ]);

        return Inertia::render('Permissions/Index', ['permissions' => $permissions]);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('permissions.index')->with('error', 'Chỉ DEV mới được dùng tính năng này!');
    }

    public function store(PermissionRequest $request): Response|RedirectResponse
    {
        $permission = Permission::create(['name' => $request->validated('name'), 'guard_name' => 'web']);

        activity('permission')->causedBy(auth()->user())->performedOn($permission)->log('Tạo permission mới');

        return $this->modalSaved('Đã tạo permission thành công.', 'permissions-changed', route('permissions.index'));
    }

    public function edit(Permission $permission): InertiaResponse
    {
        return $this->modalPage('Permissions/Form', ['permission' => ['id' => $permission->id, 'name' => $permission->name]]);
    }

    public function update(PermissionRequest $request, Permission $permission): Response|RedirectResponse
    {
        $permission->update(['name' => $request->validated('name')]);

        activity('permission')->causedBy(auth()->user())->performedOn($permission)->log('Cập nhật permission');

        return $this->modalSaved('Đã cập nhật permission.', 'permissions-changed', route('permissions.index'));
    }

    public function destroy(Permission $permission): Response|RedirectResponse
    {
        if ($permission->roles()->exists()) {
            return $this->modalFailed('Không thể xóa permission đang được gán cho vai trò.', 'permission');
        }

        $name = $permission->name;
        $permission->delete();

        activity('permission')->causedBy(auth()->user())->withProperties(['name' => $name])->log('Xóa permission');

        return $this->modalSaved('Đã xóa permission.', 'permissions-changed', route('permissions.index'));
    }
}
