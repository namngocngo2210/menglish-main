<?php

namespace App\Http\Controllers;

use App\Http\Requests\PermissionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function index(Request $request): View
    {
        $permissions = Permission::query()
            ->withCount('roles')
            ->orderBy('name')
            ->paginate($request->perPage(20))
            ->withQueryString();

        return view('permissions.index', compact('permissions'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('permissions.index')->with('error', 'Chỉ DEV mới được dùng tính năng này!');
    }

    public function store(PermissionRequest $request): RedirectResponse
    {
        $permission = Permission::create(['name' => $request->validated('name'), 'guard_name' => 'web']);

        activity('permission')->causedBy(auth()->user())->performedOn($permission)->log('Tạo permission mới');

        return redirect()->route('permissions.index')->with('status', 'Đã tạo permission thành công.');
    }

    public function edit(Permission $permission): View
    {
        return view('permissions.form', compact('permission'));
    }

    public function update(PermissionRequest $request, Permission $permission): RedirectResponse
    {
        $permission->update(['name' => $request->validated('name')]);

        activity('permission')->causedBy(auth()->user())->performedOn($permission)->log('Cập nhật permission');

        return redirect()->route('permissions.index')->with('status', 'Đã cập nhật permission.');
    }

    public function destroy(Permission $permission): RedirectResponse
    {
        if ($permission->roles()->exists()) {
            return back()->withErrors(['permission' => 'Không thể xóa permission đang được gán cho vai trò.']);
        }

        $name = $permission->name;
        $permission->delete();

        activity('permission')->causedBy(auth()->user())->withProperties(['name' => $name])->log('Xóa permission');

        return redirect()->route('permissions.index')->with('status', 'Đã xóa permission.');
    }
}
