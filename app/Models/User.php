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
