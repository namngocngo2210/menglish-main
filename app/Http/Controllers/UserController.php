<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Http\Concerns\RendersModals;
use App\Http\Requests\AssignRoleRequest;
use App\Http\Requests\UserRequest;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\User;
use App\Services\SafeUploadService;
use App\Support\Audit;
use App\Support\DataScope;
use App\Support\Money;
use App\Support\Rbac;
use App\Support\Roles;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use RendersModals;

    /**
     * Quản lý Tài khoản & Vai trò (mockup epic-5/quan-ly-tai-khoan-vai-tro): thẻ số liệu, bộ lọc, bảng nhân sự,
     * hồ sơ nhanh (drawer). Thêm / Sửa (modal 3xl), Phân quyền cá nhân (modal 4xl), Vai trò & kiêm nhiệm (modal md).
     */
    public function index(Request $request): InertiaResponse
    {
        $currentUser = auth()->user();

        // Phạm vi "user.scope_*" (DataScope): Toàn hệ thống (Admin) xem toàn bộ; Chi nhánh (Quản lý cơ sở) chỉ nhân
        // sự thuộc chi nhánh mình (branch_id chính); Của tôi (Học vụ, Học thuật) chỉ tài khoản do chính mình tạo.
        $scope = fn ($q) => DataScope::apply(
            $q, $currentUser, 'user',
            fn ($own) => $own->where('created_by', $currentUser->id),
            fn ($branch, array $branchIds) => $branch->whereIn('branch_id', $branchIds),
        );

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

        if ($status = $request->input('status')) {
            match ($status) {
                'active' => $query->where('is_active', true)->whereNull('locked_at'),
                'locked' => $query->where(fn ($q) => $q->where('is_active', false)->orWhereNotNull('locked_at')),
                'contract_expiring' => $query->whereNotNull('contract_end_date')
                    ->whereDate('contract_end_date', '<=', now()->addDays(User::contractWarningDays())->toDateString()),
                default => null,
            };
        }

        $users = $query->paginate($request->perPage(15))->withQueryString();

        // Lớp đang phụ trách của từng nhân sự trên trang (kiêm nhiệm giảng dạy trong hồ sơ nhanh) — 1 truy vấn.
        $pageIds = $users->getCollection()->modelKeys();
        $teachingByUser = [];
        ClassModel::query()
            ->whereIn('status', ['active', 'upcoming', 'pending_schedule'])
            ->where(fn ($q) => $q->whereIn('teacher_id', $pageIds)->orWhereIn('assistant_id', $pageIds)->orWhereIn('foreign_teacher_id', $pageIds))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'teacher_id', 'assistant_id', 'foreign_teacher_id'])
            ->each(function ($class) use (&$teachingByUser) {
                foreach (['teacher_id' => 'Giáo viên', 'foreign_teacher_id' => 'GVNN', 'assistant_id' => 'Trợ giảng'] as $column => $label) {
                    if ($class->{$column}) {
                        $teachingByUser[(int) $class->{$column}][] = ['code' => $class->code, 'name' => $class->name, 'role' => $label];
                    }
                }
            });

        $totalStaff = $scope(User::query())->count();
        $activeStaff = $scope(User::query())->where('is_active', true)->whereNull('locked_at')->count();
        $academicStaff = $scope(User::query())->whereHas('roles', function ($q) {
            $q->whereIn('name', self::ACADEMIC_ROLES);
        })->count();
        $lockedStaff = $scope(User::query())->where(function ($q) {
            $q->where('is_active', false)->orWhereNotNull('locked_at');
        })->count();

        $expiringContracts = $scope(User::query())
            ->whereNotNull('contract_end_date')
            ->whereDate('contract_end_date', '<=', now()->addDays(User::contractWarningDays())->toDateString())
            ->where('is_active', true)
            ->whereNull('locked_at')
            ->count();

        $branches = $this->assignableBranches();
        $roles = Role::query()->orderBy('name')->pluck('name');

        return Inertia::render('Users/Index', [
            'users' => $users->through(function (User $user) use ($currentUser, $teachingByUser) {
                $payload = self::profilePayload($user, $currentUser, $teachingByUser[$user->id] ?? []);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => User::isGeneratedStudentEmail($user->email) ? null : $user->email,
                    'employee_code' => $payload['employee_code'],
                    'roles' => $user->roles->map(fn ($role) => AclHelper::shortRoleLabel($role->name))->values()->all(),
                    'branch_name' => $user->branch?->name,
                    'locked' => $user->isLocked(),
                    'contract_status' => $user->contractExpiryStatus(),
                    'contract_end_date' => $user->contract_end_date?->format('d/m/Y'),
                    'profile' => $payload,
                ];
            }),
            'stats' => [
                'total' => $totalStaff,
                'active' => $activeStaff,
                'academic' => $academicStaff,
                'locked' => $lockedStaff,
            ],
            'expiringContracts' => $expiringContracts,
            'contractWarningDays' => User::contractWarningDays(),
            'branches' => Ui::options($branches, 'name'),
            'roles' => Ui::options($roles->mapWithKeys(fn ($r) => [$r => AclHelper::shortRoleLabel($r)])),
            'statuses' => Ui::options(['active' => 'Đang hoạt động', 'locked' => 'Vô hiệu hóa', 'contract_expiring' => 'HĐ sắp/đã hết hạn']),
        ]);
    }

    /**
     * Chi tiết nhân sự. Sửa thông tin / Tải lên HĐ mới (modal 3xl, mở sẵn tab Hợp đồng & Lương), Phân quyền chi tiết
     * (modal 4xl), Gán vai trò (modal md); lưu xong trang tự có dữ liệu mới.
     */
    public function show(User $user, Request $request)
    {
        $this->ensureCanManageTarget($user);
        $user->load(['branch', 'roles']);

        // JSON cho gọi AJAX cũ; request Inertia (cũng mang X-Requested-With) nhận trang.
        if (! $request->header('X-Inertia') && ($request->wantsJson() || $request->ajax())) {
            return response()->json([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'employee_code' => $user->employee_code ?? ('NV-'.str_pad($user->id, 4, '0', STR_PAD_LEFT)),
                'branch_name' => $user->branch?->name,
                'role_name' => $user->roles->first()?->name ? AclHelper::roleLabel($user->roles->first()->name) : 'Nhân viên',
                'base_salary' => self::canViewSensitive($request->user()) && $user->base_salary
                    ? Money::format($user->base_salary)
                    : null,
                'is_locked' => $user->isLocked(),
                'created_at' => $user->created_at->format('d/m/Y'),
            ]);
        }

        // Lớp nhân sự đang phụ trách (GV chính / GVNN / trợ giảng) — thay cho dữ liệu kiêm nhiệm mẫu.
        $teachingClasses = ClassModel::query()
            ->where(fn ($q) => $q->where('teacher_id', $user->id)
                ->orWhere('assistant_id', $user->id)
                ->orWhere('foreign_teacher_id', $user->id))
            ->whereIn('status', ['active', 'upcoming', 'pending_schedule'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'teacher_id', 'assistant_id', 'foreign_teacher_id', 'start_date', 'end_date']);

        $canViewSensitive = self::canViewSensitive($request->user());
        $primaryRole = $user->roles->first()?->name;
        $contractState = $user->contractExpiryStatus();
        $sensitive = fn ($value) => $canViewSensitive ? $value : false;

        return Inertia::render('Users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'employee_code' => $user->employee_code ?? ('NV-'.str_pad($user->id, 4, '0', STR_PAD_LEFT)),
                'branch_name' => $user->branch?->name,
                'locked' => $user->isLocked(),
                'primary_role_label' => $primaryRole ? AclHelper::roleLabel($primaryRole) : null,
                'primary_role_short' => $primaryRole ? AclHelper::shortRoleLabel($primaryRole) : null,
                'extra_roles' => $user->roles->slice(1)->map(fn ($role) => AclHelper::shortRoleLabel($role->name))->values()->all(),
                'base_salary' => $canViewSensitive ? $user->base_salary : null,
                'hourly_rate' => $canViewSensitive ? $user->hourly_rate : null,
                // [nhãn, giá trị (false = không có quyền xem), font mã]
                'profile' => [
                    ['Số CCCD (12 số)', $sensitive($user->id_card_number), true],
                    ['Liên lạc khẩn cấp', $sensitive($user->emergency_contact), false],
                    ['Quê quán', $sensitive($user->hometown), false],
                    ['Nơi ở hiện tại', $sensitive($user->current_address), false],
                    ['Tốt nghiệp', $user->graduation_school, false],
                    ['Chứng chỉ', $user->certificates, false],
                    ['Level giảng dạy / Chuyên môn', $user->teaching_level, false],
                ],
                'contract_type' => $user->contract_type,
                'contract_start_date' => $user->contract_start_date?->format('d/m/Y'),
                'contract_end_date' => $user->contract_end_date?->format('d/m/Y'),
                // [nhãn, màu badge]; hết hạn / sắp hết hạn / còn hiệu lực — "Còn N ngày" tính theo ngày hôm nay (server).
                'contract_status' => match (true) {
                    ! $user->contract_type && ! $user->contract_end_date => ['Chưa cập nhật', 'neutral'],
                    $contractState === 'expired' => ['Đã hết hạn', 'error'],
                    $contractState === 'expiring' => ['Sắp hết hạn', 'warning'],
                    default => ['Đang hiệu lực', 'success'],
                },
                'contract_remaining' => match (true) {
                    ! $user->contract_end_date => null,
                    $contractState === 'expired' => ['Đã hết hạn', 'text-error'],
                    $contractState === 'expiring' => ['Còn '.(int) now()->startOfDay()->diffInDays($user->contract_end_date->copy()->startOfDay()).' ngày', 'text-warning'],
                    default => ['Còn hiệu lực', 'text-tertiary'],
                },
                'has_contract_file' => (bool) $user->contract_file_path,
            ],
            'canViewSensitive' => $canViewSensitive,
            'teachingClasses' => $teachingClasses->map(fn ($class) => [
                'id' => $class->id,
                'code' => $class->code,
                'name' => $class->name,
                'start_date' => $class->start_date?->format('d/m/Y') ?? '?',
                'end_date' => $class->end_date?->format('d/m/Y') ?? '?',
                'role' => $class->teacher_id === $user->id ? 'Giáo viên' : ($class->foreign_teacher_id === $user->id ? 'GVNN' : 'Trợ giảng'),
            ])->values()->all(),
        ]);
    }

    /** Thêm nhân sự: mở từ danh sách → modal 3xl; mở thẳng URL → trang đầy đủ. */
    public function create(Request $request): InertiaResponse
    {
        return $this->formPage($request, new User);
    }

    public function store(UserRequest $request): Response|RedirectResponse
    {
        $this->ensureCanAssignRole($request->validated('role'));
        $this->ensureBranchInScope((int) $request->validated('branch_id'));

        Audit::describe('Tạo tài khoản nhân viên mới');

        // Tài khoản mới luôn phải đổi mật khẩu ở lần đăng nhập đầu tiên.
        $user = User::create([
            ...$request->safe()->except(['role', 'password', 'contract_file', 'concurrent_roles', 'concurrent_roles_present']),
            'password' => Hash::make($request->validated('password')),
            'must_change_password' => true,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        $user->syncRoles($this->rolesWithConcurrent($request, $request->validated('role'), []));
        $this->storeContractFile($request, $user);

        return $this->modalSaved('Đã tạo tài khoản thành công.', route('users.index'));
    }

    public function edit(Request $request, User $user): InertiaResponse
    {
        $this->ensureCanManageTarget($user);

        return $this->formPage($request, $user);
    }

    /**
     * Form Thêm / Sửa nhân sự (3 tab Tài khoản / Hồ sơ / Hợp đồng & Lương). ?tab= chọn sẵn tab (vd. "Tải lên HĐ mới" → salary).
     * Vai trò chính chỉ gồm vai trò người thao tác được gán (AclHelper::primaryRoleOptions).
     */
    private function formPage(Request $request, User $user): InertiaResponse
    {
        $assignable = Rbac::assignableRoles(auth()->user());
        $currentRole = $user->exists ? $user->getRoleNames()->first() : null;
        $tabs = ['account', 'profile', 'salary'];
        // CCCD, lương cơ bản, thù lao giờ: chỉ người xem được lương (payroll.view) đọc / sửa trên tài khoản đã có.
        $canEditSensitive = ! $user->exists || self::canViewSensitive(auth()->user());

        return $this->modalPage('Users/Form', [
            'user' => $user->exists ? [
                'id' => $user->id,
                ...$user->only([
                    'name', 'employee_code', 'email', 'phone', 'branch_id',
                    'emergency_contact', 'hometown', 'current_address', 'graduation_school', 'certificates', 'teaching_level',
                    'contract_type',
                ]),
                ...($canEditSensitive ? $user->only(self::SENSITIVE_FIELDS) : []),
                'contract_start_date' => $user->contract_start_date?->format('Y-m-d'),
                'contract_end_date' => $user->contract_end_date?->format('Y-m-d'),
                'has_contract_file' => (bool) $user->contract_file_path,
            ] : null,
            'currentRole' => $currentRole,
            'currentConcurrent' => $user->exists ? $user->getRoleNames()->slice(1)->values()->all() : [],
            'branches' => Ui::options($this->assignableBranches(), 'name'),
            'roleOptions' => Ui::options(AclHelper::primaryRoleOptions($assignable, $currentRole)),
            'concurrentOptions' => Ui::options(collect($assignable)->mapWithKeys(fn (string $role) => [$role => AclHelper::shortRoleLabel($role)])),
            'initialTab' => in_array($request->query('tab'), $tabs, true) ? $request->query('tab') : 'account',
            'canEditSensitive' => $canEditSensitive,
        ]);
    }

    /** Trường nhạy cảm trên form nhân sự: chỉ người có payroll.view đọc / sửa (canViewSensitive). */
    private const SENSITIVE_FIELDS = ['id_card_number', 'base_salary', 'hourly_rate'];

    public function update(UserRequest $request, User $user): Response|RedirectResponse
    {
        $this->ensureCanManageTarget($user);
        $this->ensureCanAssignRole($request->validated('role'));
        $this->ensureBranchInScope((int) $request->validated('branch_id'));

        Audit::describe('Cập nhật tài khoản nhân viên');

        $user->fill($request->safe()->except([
            'role', 'password', 'contract_file', 'concurrent_roles', 'concurrent_roles_present',
            ...(self::canViewSensitive(auth()->user()) ? [] : self::SENSITIVE_FIELDS),
        ]));

        if ($request->filled('password')) {
            $user->password = Hash::make($request->validated('password'));
        }

        $user->save();

        // Form chỉ chọn vai trò CHÍNH: thay vai trò chính cũ bằng vai trò mới và
        // giữ nguyên các vai trò kiêm nhiệm (gán ở màn "Gán vai trò").
        $currentRoles = $user->getRoleNames();
        $primaryRole = $currentRoles->first();
        $keptConcurrent = $currentRoles->reject(fn (string $role) => $role === $primaryRole)->values()->all();
        $user->syncRoles($this->rolesWithConcurrent($request, $request->validated('role'), $keptConcurrent));

        $this->storeContractFile($request, $user);

        return $this->modalSaved('Đã cập nhật tài khoản thành công.', route('users.index'));
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->ensureCanManageTarget($user);
        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'Bạn không thể tự xóa tài khoản của chính mình.']);
        }

        Audit::describe('Xóa (soft-delete) tài khoản nhân viên');
        $user->delete();

        return redirect()->route('users.index')->with('status', 'Đã xóa tài khoản.');
    }

    public function lock(User $user): RedirectResponse
    {
        $this->ensureCanManageTarget($user);
        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'Bạn không thể khóa tài khoản của chính mình.']);
        }

        Audit::describe('Vô hiệu hóa tài khoản');
        $user->forceFill(['locked_at' => now()])->save();

        return back()->with('status', 'Đã vô hiệu hóa tài khoản.');
    }

    public function unlock(User $user): RedirectResponse
    {
        $this->ensureCanManageTarget($user);
        Audit::describe('Kích hoạt lại tài khoản');
        $user->forceFill(['locked_at' => null])->save();

        return back()->with('status', 'Đã kích hoạt lại tài khoản.');
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $this->ensureCanManageTarget($user);
        $temporaryPassword = Str::password(12);

        // Mật khẩu tạm: bắt buộc đổi ở lần đăng nhập tiếp theo. Mật khẩu không
        // ghi vào nhật ký nên ghi thủ công 1 dòng (lưu model không qua audit).
        $user->forceFill([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
        ])->saveQuietly();

        activity('Người dùng & Phân quyền')->causedBy(auth()->user())->performedOn($user)
            ->event('updated')
            ->log('Đặt lại mật khẩu (bắt buộc đổi ở lần đăng nhập tới)');

        // Trong môi trường thực tế nên gửi mật khẩu tạm qua email thay vì hiển thị trực tiếp.
        return back()->with('status', "Đã đặt lại mật khẩu. Mật khẩu tạm thời: {$temporaryPassword}");
    }

    /** Gán vai trò: mở từ danh sách nhân sự → modal md; mở thẳng URL → trang đầy đủ. */
    public function editRoles(User $user): InertiaResponse
    {
        $this->ensureCanManageTarget($user);
        $user->loadMissing(['roles', 'branch']);

        return $this->modalPage('Users/Roles', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'branch_name' => $user->branch?->name,
                'employee_code' => $user->employee_code ?? ('NV-'.str_pad($user->id, 4, '0', STR_PAD_LEFT)),
                'roles' => $user->roles->pluck('name')->all(),
            ],
            'roles' => Role::query()->withCount('permissions')->orderBy('name')->get()->map(fn (Role $role) => [
                'name' => $role->name,
                'label' => AclHelper::roleLabel($role->name),
                'permissions_count' => $role->permissions_count,
            ])->all(),
        ]);
    }

    public function updateRoles(AssignRoleRequest $request, User $user): Response|RedirectResponse
    {
        $this->ensureCanManageTarget($user);
        $before = $user->getRoleNames()->all();
        $after = array_values(array_unique((array) $request->validated('roles')));
        // Chỉ được thêm / bớt vai trò mình được phép gán (vai trò khác của người này giữ nguyên nếu không đổi).
        foreach (array_merge(array_diff($after, $before), array_diff($before, $after)) as $roleName) {
            $this->ensureCanAssignRole($roleName);
        }
        // Không tự tước quyền quản trị phân quyền của chính mình.
        if ((int) $user->id === (int) auth()->id()) {
            Rbac::ensureKeepsAccessManagement($user, $after);
        }

        $user->syncRoles($after);
        Rbac::flushCache();

        activity('Người dùng & Phân quyền')->causedBy(auth()->user())->performedOn($user)
            ->event('updated')
            ->withProperties(['old' => ['roles' => $before], 'attributes' => ['roles' => $after], 'roles' => $after])
            ->log('Cập nhật vai trò nhân viên');

        return $this->modalSaved('Đã cập nhật vai trò.', route('users.index'));
    }

    /**
     * Tải file hợp đồng lao động (lưu ở disk riêng tư). Chỉ người quản lý được
     * tài khoản này hoặc chính nhân sự đó được tải.
     */
    public function downloadContract(User $user)
    {
        if ((int) $user->id !== (int) auth()->id()) {
            abort_unless(auth()->user()->can('user.view'), 403);
            $this->ensureCanManageTarget($user);
        }

        abort_if(! $user->contract_file_path || ! Storage::disk('local')->exists($user->contract_file_path), 404);

        $extension = pathinfo($user->contract_file_path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download(
            $user->contract_file_path,
            'hop-dong-'.Str::slug($user->name).'.'.$extension
        );
    }

    /**
     * Vai trò chính + kiêm nhiệm. Form gửi "concurrent_roles_present" (người có quyền gán vai trò)
     * thì dùng danh sách kiêm nhiệm được chọn (chỉ vai trò người thao tác được phép gán);
     * không gửi thì giữ nguyên kiêm nhiệm hiện có. Vai trò chính luôn đứng đầu.
     *
     * @param  string[]  $currentConcurrent
     * @return string[]
     */
    private function rolesWithConcurrent(UserRequest $request, string $primary, array $currentConcurrent): array
    {
        $concurrent = $currentConcurrent;
        if ($request->boolean('concurrent_roles_present') && auth()->user()?->can('user.assign_role')) {
            $concurrent = array_values(array_unique((array) $request->validated('concurrent_roles', [])));
            foreach ($concurrent as $role) {
                if (! Rbac::canAssignRole(auth()->user(), $role)) {
                    throw ValidationException::withMessages(['concurrent_roles' => 'Bạn không được phép gán vai trò kiêm nhiệm "'.AclHelper::shortRoleLabel($role).'".']);
                }
            }
        }

        return collect($concurrent)->reject(fn (string $role) => $role === $primary)->prepend($primary)->unique()->values()->all();
    }

    private function storeContractFile(UserRequest $request, User $user): void
    {
        if (! $request->hasFile('contract_file')) {
            return;
        }

        $path = SafeUploadService::store(
            $request->file('contract_file'),
            'contracts/'.$user->id,
            [...SafeUploadService::DOCUMENTS, ...SafeUploadService::IMAGES],
            'contract_file',
            'local'
        );

        $old = $user->contract_file_path;
        Audit::describe('Cập nhật file hợp đồng lao động');
        $user->forceFill(['contract_file_path' => $path])->save();

        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }
    }

    /** Chi nhánh bị giới hạn khi quản lý nhân sự: phạm vi "Chi nhánh" → chi nhánh của mình; mức khác → không giới hạn. */
    private static function branchLimit(?User $actor): ?array
    {
        return $actor && DataScope::level($actor, 'user') === DataScope::BRANCH ? $actor->branchIds() : null;
    }

    /**
     * Chi nhánh được chọn khi tạo/sửa tài khoản: phạm vi "Chi nhánh" (Quản lý cơ sở) chỉ chọn chi nhánh mình.
     */
    private function assignableBranches()
    {
        $managed = self::branchLimit(auth()->user());

        return Branch::query()->active()
            ->when($managed !== null, fn ($q) => $q->whereIn('id', $managed))
            ->orderBy('name')
            ->get();
    }

    private function ensureBranchInScope(int $branchId): void
    {
        $managed = self::branchLimit(auth()->user());

        if ($managed !== null && ! in_array($branchId, $managed, true)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Bạn chỉ được quản lý nhân sự thuộc chi nhánh của mình.',
            ]);
        }
    }

    /**
     * Chặn thao tác lên tài khoản vượt phân cấp của người thực hiện (ví dụ Học vụ/Quản lý đổi mật khẩu, hạ quyền hoặc
     * khóa tài khoản Admin). Super Admin quản lý được mọi tài khoản; người khác chỉ quản lý được tài khoản mà MỌI vai
     * trò hiện có đều nằm trong các vai trò mình được gán (user.assign_role.<vai trò>) — tài khoản Super Admin chỉ
     * Super Admin thao tác. Phạm vi "Chi nhánh" chỉ thao tác trên nhân sự thuộc chi nhánh mình.
     */
    private function ensureCanManageTarget(User $target): void
    {
        $actor = auth()->user();
        if ($actor?->isSuperAdmin()) {
            return;
        }

        $outOfScope = $target->getRoleNames()->reject(fn (string $role) => Rbac::canAssignRole($actor, $role));

        abort_if($target->isSuperAdmin() || $outOfScope->isNotEmpty(), 403, 'Bạn không có quyền thao tác trên tài khoản này.');

        $managed = self::branchLimit($actor);
        abort_if(
            $managed !== null && ! in_array((int) $target->branch_id, $managed, true),
            403,
            'Nhân sự này không thuộc chi nhánh bạn quản lý.'
        );
        // Phạm vi "Của tôi" (Học vụ, Học thuật): chỉ tài khoản do chính mình tạo — đúng như danh sách đang hiện.
        abort_if(
            DataScope::level($actor, 'user') === DataScope::OWN && (int) $target->created_by !== (int) $actor?->id,
            403,
            'Bạn chỉ thao tác được trên tài khoản do mình tạo.'
        );
    }

    /**
     * Dữ liệu hồ sơ nhanh nhúng vào danh sách nhân sự. Chỉ gồm các trường hiển
     * thị; CCCD, lương, đơn giá, địa chỉ, liên hệ khẩn chỉ gửi cho người được xem.
     */
    /** Vai trò thuộc "Khối học thuật" (thẻ thống kê Tài khoản & vai trò). */
    public const ACADEMIC_ROLES = Roles::ACADEMIC;

    /**
     * @param  array<int, array{code: string, name: string, role: string}>  $teaching  lớp đang phụ trách
     */
    public static function profilePayload(User $user, ?User $viewer, array $teaching = []): array
    {
        $roles = $user->getRoleNames();
        $contractStatus = $user->contractExpiryStatus();
        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'employee_code' => $user->employee_code ?: 'NV-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
            'branch' => $user->branch ? ['name' => $user->branch->name] : null,
            'primary_role' => $roles->first() ? AclHelper::shortRoleLabel($roles->first()) : null,
            // Kiêm nhiệm: vai trò phụ ngoài vai trò chính + lớp đang phụ trách.
            'concurrent_roles' => $roles->slice(1)->map(fn ($r) => AclHelper::shortRoleLabel($r))->values()->all(),
            'teaching' => array_values($teaching),
            'certificates' => $user->certificates,
            'graduation_school' => $user->graduation_school,
            'teaching_level' => $user->teaching_level,
            'contract_type' => $user->contract_type,
            'contract_start_date' => $user->contract_start_date?->format('d/m/Y'),
            'contract_end_date' => $user->contract_end_date?->format('d/m/Y'),
            'contract_status' => match (true) {
                ! $user->contract_type && ! $user->contract_end_date => 'Chưa cập nhật',
                $contractStatus === 'expired' => 'Đã hết hạn',
                $contractStatus === 'expiring' => 'Sắp hết hạn',
                default => 'Đang hiệu lực',
            },
            'contract_url' => $user->contract_file_path && $viewer && ((int) $viewer->id === (int) $user->id || $viewer->can('user.view'))
                ? route('users.contract.download', $user) : null,
            'show_url' => route('users.show', $user),
        ];

        if (self::canViewSensitive($viewer)) {
            $payload += $user->only(['id_card_number', 'base_salary', 'hourly_rate', 'hometown', 'current_address', 'emergency_contact']);
        }

        return $payload;
    }

    /**
     * Người được xem CCCD, lương cơ bản, đơn giá của nhân sự.
     */
    public static function canViewSensitive(?User $actor): bool
    {
        // Lương cơ bản / thù lao của người khác là thông tin lương: cần xem được mọi phiếu lương (Admin), không chỉ payroll.view.
        return (bool) $actor && $actor->can('payroll.view') && DataScope::isAll($actor, 'payroll');
    }

    /**
     * Chặn việc gán vai trò vượt phân cấp cho phép.
     */
    private function ensureCanAssignRole(string $role): void
    {
        if (! Rbac::canAssignRole(auth()->user(), $role)) {
            throw ValidationException::withMessages([
                'role' => 'Bạn không được phép tạo/gán vai trò này.',
            ]);
        }
    }
}
