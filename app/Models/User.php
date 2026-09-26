<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use AuditsChanges, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_code',
        'branch_id',
        'created_by',
        'base_salary',
        'hourly_rate',
        'department',
        'teacher_rate_id',
        'name',
        'email',
        'phone',
        'id_card_number',
        'hometown',
        'current_address',
        'emergency_contact',
        'graduation_school',
        'certificates',
        'teaching_level',
        'contract_type',
        'contract_start_date',
        'contract_end_date',
        'contract_file_path',
        'password',
        'must_change_password',
        'is_active',
        'locked_at',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'base_salary' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'locked_at' => 'datetime',
            'last_login_at' => 'datetime',
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
        ];
    }

    /**
     * Người tạo tài khoản.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Danh sách tài khoản do người này tạo.
     */
    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    /**
     * Chi nhánh chính của nhân viên.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Danh sách chi nhánh nhân viên được cấp quyền truy cập (ngoài chi nhánh chính).
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branches');
    }

    /**
     * Các quyền override cá nhân (phân quyền chi tiết theo cá nhân).
     */
    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    public function assignedCustomers(): HasMany
    {
        return $this->hasMany(CrmCustomer::class, 'assigned_user_id');
    }

    public function crmCustomers(): HasMany
    {
        return $this->assignedCustomers();
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(TeacherTimesheet::class, 'user_id');
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(Penalty::class, 'user_id');
    }

    public function payrollRecords(): HasMany
    {
        return $this->hasMany(PayrollRecord::class, 'user_id');
    }

    public function teacherRate(): BelongsTo
    {
        return $this->belongsTo(TeacherRate::class, 'teacher_rate_id');
    }

    public function isLocked(): bool
    {
        return ! $this->is_active || $this->locked_at !== null;
    }

    /** Số ngày trước khi hết hạn hợp đồng bắt đầu cảnh báo. */
    public const CONTRACT_WARNING_DAYS = 30;

    /**
     * Trạng thái hợp đồng để cảnh báo: 'expired' (đã hết hạn), 'expiring'
     * (hết hạn trong CONTRACT_WARNING_DAYS ngày tới) hoặc null.
     */
    public function contractExpiryStatus(): ?string
    {
        if (! $this->contract_end_date) {
            return null;
        }

        $end = $this->contract_end_date->copy()->startOfDay();
        $today = now()->startOfDay();

        if ($end->lt($today)) {
            return 'expired';
        }

        return $end->lte($today->copy()->addDays(self::CONTRACT_WARNING_DAYS)) ? 'expiring' : null;
    }

    /**
     * Super Admin (vai trò `admin`): luôn có mọi quyền thao tác (Gate::before), vai trò bất biến. Đây là nơi DUY NHẤT
     * trong code nghiệp vụ kiểm tra tên vai trò (xem tests/Feature/RbacNoHardcodedRolesTest.php); mọi chỗ khác kiểm
     * tra permission (`can()`), phạm vi dữ liệu (App\Support\DataScope) hoặc quan hệ (người lập, GV của lớp…).
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(\App\Support\Rbac::SUPER_ADMIN);
    }

    /**
     * Chi nhánh của người dùng: chi nhánh chính (branch_id) + chi nhánh được cấp thêm (user_branches).
     * Mức phạm vi dữ liệu "Chi nhánh" (DataScope) dùng danh sách này.
     *
     * @return list<int>
     */
    public function branchIds(): array
    {
        $extra = $this->relationLoaded('branches')
            ? $this->getRelation('branches')->pluck('id')
            : $this->branches()->pluck('branches.id');

        return $extra->push($this->branch_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Id phạm vi (chi nhánh hoặc lớp) mà người dùng được cấp riêng quyền
     * "module.action" qua phân quyền cá nhân (override allow có scope cụ thể).
     *
     * @return int[]
     */
    public function scopedOverrideIds(string $module, string $action, string $scopeType): array
    {
        $overrides = $this->relationLoaded('permissionOverrides')
            ? $this->getRelation('permissionOverrides')
            : $this->permissionOverrides()->get();

        return $overrides
            ->filter(fn (UserPermissionOverride $o) => $o->module === $module
                && $o->action === $action
                && $o->scope_type === $scopeType
                && $o->allow
                && $o->scope_id !== null)
            ->map(fn (UserPermissionOverride $o) => (int) $o->scope_id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Kiểm tra quyền "module.action", có tính đến lớp override cá nhân
     * (bảng user_permission_overrides) theo đúng thiết kế màn "Phân quyền
     * chi tiết cá nhân":
     *  1. Nếu có override khớp đúng scope được yêu cầu (hoặc scope "all")
     *     => dùng giá trị allow của override đó (có thể mở rộng hoặc thu
     *     hẹp so với quyền theo role).
     *  2. Nếu không có override => fallback về quyền theo role (Spatie).
     */
    public function hasModuleAction(string $module, string $action, ?string $scopeType = null, ?int $scopeId = null): bool
    {
        $permission = "{$module}.{$action}";

        if ($this->isSuperAdmin() && ! \App\Support\PermissionCatalog::isAudience($permission)) {
            return true;
        }

        $candidates = $this->permissionOverrides()
            ->where('module', $module)
            ->where('action', $action)
            ->get();

        // Ưu tiên override khớp đúng scope cụ thể được yêu cầu trước,
        // sau đó mới đến override áp dụng cho scope "all".
        if ($scopeType !== null) {
            $specific = $candidates->first(
                fn (UserPermissionOverride $o) => $o->scope_type === $scopeType && (int) $o->scope_id === $scopeId
            );

            if ($specific !== null) {
                return $specific->allow;
            }
        }

        $general = $candidates->first(
            fn (UserPermissionOverride $o) => $o->scope_type === UserPermissionOverride::SCOPE_ALL
        );

        if ($general !== null) {
            return $general->allow;
        }

        try {
            return $this->hasPermissionTo($permission);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
