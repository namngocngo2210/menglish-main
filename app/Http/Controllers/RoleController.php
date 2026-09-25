<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): View
    {
        $roles = Role::query()
            ->withCount(['permissions', 'users'])
            ->orderBy('name')
            ->paginate($request->perPage(15))
            ->withQueryString();

        return view('roles.index', compact('roles'));
    }

    public function create(): View
    {
        return view('roles.form', [
            'role' => new Role,
            'permissionsByModule' => $this->permissionsByModule(),
            'selected' => [],
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $this->authorizeAssigningPermissions($request);
        $role = Role::create(['name' => $request->validated('name'), 'guard_name' => 'web']);
        $role->syncPermissions($request->validated('permissions', []));

        activity('role')->causedBy(auth()->user())->performedOn($role)->log('Tạo vai trò mới');

        return redirect()->route('roles.index')->with('status', 'Đã tạo vai trò thành công.');
    }

    public function edit(Role $role): View
    {
        return view('roles.form', [
            'role' => $role,
            'permissionsByModule' => $this->permissionsByModule(),
            'selected' => $role->permissions->pluck('name')->all(),
        ]);
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorizeAssigningPermissions($request);
        $role->update(['name' => $request->validated('name')]);
        $role->syncPermissions($request->validated('permissions', []));

        activity('role')->causedBy(auth()->user())->performedOn($role)
            ->withProperties(['permissions' => $request->validated('permissions', [])])
            ->log('Cập nhật vai trò');

        return redirect()->route('roles.index')->with('status', 'Đã cập nhật vai trò.');
    }

    /**
     * Gán permission cho vai trò là năng lực riêng (role.assign_permission):
     * người chỉ có role.create/update nhưng không có assign_permission không được đụng vào tập quyền.
     */
    private function authorizeAssigningPermissions(RoleRequest $request): void
    {
        if (filled($request->validated('permissions', []))) {
            abort_unless($request->user()->can('role.assign_permission'), 403, 'Bạn không có quyền gán permission cho vai trò.');
        }
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->users()->exists()) {
            return back()->withErrors(['role' => 'Không thể xóa vai trò đang được gán cho nhân viên.']);
        }

        $name = $role->name;
        $role->delete();

        activity('role')->causedBy(auth()->user())->withProperties(['name' => $name])->log('Xóa vai trò');

        return redirect()->route('roles.index')->with('status', 'Đã xóa vai trò.');
    }

    /**
     * @return array<string, Collection>
     */
    protected function permissionsByModule(): array
    {
        return Permission::query()->orderBy('name')->get()
            ->groupBy(fn (Permission $permission) => explode('.', $permission->name)[0])
            ->all();
    }
}
