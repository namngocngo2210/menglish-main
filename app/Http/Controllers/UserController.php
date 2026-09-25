<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Http\Requests\AssignRoleRequest;
use App\Http\Requests\UserRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $currentUser = auth()->user();
        abort_if($currentUser && ($currentUser->hasRole('student') || $currentUser->hasRole('teacher')), 403);

        // Admin & Manager xem toàn bộ; các vai trò khác (ví dụ học vụ) chỉ
        // được thấy tài khoản do chính mình tạo (created_by).
        $isPrivileged = $currentUser && ($currentUser->hasRole('admin') || $currentUser->hasRole('manager'));
        $scope = function ($q) use ($currentUser, $isPrivileged) {
            if (! $isPrivileged && $currentUser) {
                $q->where('created_by', $currentUser->id);
            }

            return $q;
        };

        $query = User::query()->with(['branch', 'roles'])->orderBy('name');
        $scope($query);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%");
            });
        }

        if ($branchId = $request->input('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        if ($role = $request->input('role')) {
            $query->whereHas('roles', function ($q) use ($role) {
                $q->where('name', $role);
            });
        }

        $users = $query->paginate($request->perPage(15))->withQueryString();

        $totalStaff = $scope(User::query())->count();
        $activeStaff = $scope(User::query())->where('is_active', true)->whereNull('locked_at')->count();
        $academicStaff = $scope(User::query())->whereHas('roles', function ($q) {
            $q->whereIn('name', ['teacher', 'assistant', 'academic_staff']);
        })->count();
        $lockedStaff = $scope(User::query())->where(function ($q) {
            $q->where('is_active', false)->orWhereNotNull('locked_at');
        })->count();

        $branches = Branch::query()->active()->orderBy('name')->get();
        $roles = Role::query()->orderBy('name')->pluck('name');

        return view('users.index', compact(
            'users',
            'totalStaff',
            'activeStaff',
            'academicStaff',
            'lockedStaff',
            'branches',
            'roles'
        ));
    }

    public function show(User $user, Request $request)
    {
        $user->load(['branch', 'roles']);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? '0912 345 678',
                'employee_code' => $user->employee_code ?? ('NV-'.str_pad($user->id, 4, '0', STR_PAD_LEFT)),
                'branch_name' => $user->branch?->name ?? 'Cơ sở Cầu Giấy',
                'role_name' => $user->roles->first()?->name ? AclHelper::roleLabel($user->roles->first()->name) : 'Nhân viên',
                'base_salary' => $user->base_salary ? number_format($user->base_salary).' VND' : '15,000,000 VND',
                'is_locked' => $user->isLocked(),
                'created_at' => $user->created_at->format('d/m/Y'),
            ]);
        }

        return view('users.show', compact('user'));
    }

    public function create(): View
    {
        return view('users.form', [
            'user' => new User,
            'branches' => Branch::query()->active()->orderBy('name')->get(),
            'roles' => collect($this->creatableRoles(auth()->user())),
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $this->ensureCanAssignRole($request->validated('role'));

        $user = User::create([
            ...$request->safe()->except(['role', 'password']),
            'password' => Hash::make($request->validated('password')),
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        $user->syncRoles([$request->validated('role')]);

        activity('user')->causedBy($request->user())->performedOn($user)
            ->withProperties(['after' => $user->only(['name', 'email', 'branch_id'])])
            ->log('Tạo tài khoản nhân viên mới');

        return redirect()->route('users.index')->with('status', 'Đã tạo tài khoản thành công.');
    }

    public function edit(User $user): View
    {
        return view('users.form', [
            'user' => $user,
            'branches' => Branch::query()->active()->orderBy('name')->get(),
            'roles' => collect($this->creatableRoles(auth()->user())),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $before = $user->only(['name', 'email', 'branch_id', 'phone', 'employee_code']);

        $this->ensureCanAssignRole($request->validated('role'));

        $user->fill($request->safe()->except(['role', 'password']));

        if ($request->filled('password')) {
            $user->password = Hash::make($request->validated('password'));
        }

        $user->save();
        $user->syncRoles([$request->validated('role')]);

        activity('user')->causedBy($request->user())->performedOn($user)
            ->withProperties(['before' => $before, 'after' => $user->only(['name', 'email', 'branch_id', 'phone', 'employee_code'])])
            ->log('Cập nhật tài khoản nhân viên');

        return redirect()->route('users.index')->with('status', 'Đã cập nhật tài khoản thành công.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'Bạn không thể tự xóa tài khoản của chính mình.']);
        }

        $user->delete();

        activity('user')->causedBy(auth()->user())
            ->withProperties(['user_id' => $user->id, 'email' => $user->email])
            ->log('Xóa (soft-delete) tài khoản nhân viên');

        return redirect()->route('users.index')->with('status', 'Đã xóa tài khoản.');
    }

    public function lock(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'Bạn không thể khóa tài khoản của chính mình.']);
        }

        $user->forceFill(['locked_at' => now()])->save();

        activity('user')->causedBy(auth()->user())->performedOn($user)->log('Vô hiệu hóa tài khoản');

        return back()->with('status', 'Đã vô hiệu hóa tài khoản.');
    }

    public function unlock(User $user): RedirectResponse
    {
        $user->forceFill(['locked_at' => null])->save();

        activity('user')->causedBy(auth()->user())->performedOn($user)->log('Kích hoạt lại tài khoản');

        return back()->with('status', 'Đã kích hoạt lại tài khoản.');
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $temporaryPassword = Str::password(12);
        $user->forceFill(['password' => Hash::make($temporaryPassword)])->save();

        activity('user')->causedBy(auth()->user())->performedOn($user)->log('Đặt lại mật khẩu');

        // Trong môi trường thực tế nên gửi mật khẩu tạm qua email thay vì hiển thị trực tiếp.
        return back()->with('status', "Đã đặt lại mật khẩu. Mật khẩu tạm thời: {$temporaryPassword}");
    }

    public function editRoles(User $user): View
    {
        return view('users.roles', [
            'user' => $user,
            'roles' => Role::query()->orderBy('name')->get(),
        ]);
    }

    public function updateRoles(AssignRoleRequest $request, User $user): RedirectResponse
    {
        foreach ((array) $request->validated('roles') as $roleName) {
            $this->ensureCanAssignRole($roleName);
        }

        $user->syncRoles($request->validated('roles'));

        activity('user')->causedBy(auth()->user())->performedOn($user)
            ->withProperties(['roles' => $request->validated('roles')])
            ->log('Cập nhật vai trò nhân viên');

        return redirect()->route('users.index')->with('status', 'Đã cập nhật vai trò.');
    }

    /**
     * Danh sách vai trò mà người dùng hiện tại được phép tạo / gán theo
     * phân cấp:
     *  - admin: toàn bộ
     *  - manager: tất cả trừ admin
     *  - academic_staff (học vụ): trợ giảng, giáo viên (fulltime/parttime), học viên
     *  - academic_lead (học thuật độc lập): chỉ giáo viên
     *  - các vai trò khác: không được tạo tài khoản
     */
    private function creatableRoles(?User $actor): array
    {
        if (! $actor) {
            return [];
        }
        if ($actor->hasRole('admin')) {
            return Role::query()->orderBy('name')->pluck('name')->all();
        }
        if ($actor->hasRole('manager')) {
            return Role::query()->where('name', '!=', 'admin')->orderBy('name')->pluck('name')->all();
        }
        if ($actor->hasRole('academic_staff')) {
            return ['assistant', 'teacher', 'teacher_fulltime', 'teacher_parttime', 'student'];
        }
        if ($actor->hasRole('academic_lead')) {
            return ['teacher', 'teacher_fulltime', 'teacher_parttime'];
        }

        return [];
    }

    /**
     * Chặn việc gán vai trò vượt phân cấp cho phép.
     */
    private function ensureCanAssignRole(string $role): void
    {
        if (! in_array($role, $this->creatableRoles(auth()->user()), true)) {
            throw ValidationException::withMessages([
                'role' => 'Bạn không được phép tạo/gán vai trò này.',
            ]);
        }
    }
}
