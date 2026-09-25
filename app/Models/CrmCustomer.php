<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CrmCustomer extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'crm_customers';

    /** Chỉ các stage này mới được kết quả test đẩy sang 'tested' (lead chỉ đi tiến). */
    public const TEST_ADVANCEABLE_STAGES = ['consulting', 'test_scheduled'];

    protected $fillable = [
        'code',
        'name',
        'parent_name',
        'phone',
        'phone_normalized',
        'email',
        'dob',
        'gender',
        'address',
        'branch_id',
        'course_interest',
        'source',
        'assigned_user_id',
        'stage',
        'test_decision',
        'deal_value',
        'test_score',
        'appointment_at',
        'appointment_type',
        'trial_at',
        'trial_teacher_id',
        'trial_class_id',
        'trial_mode',
        'trial_status',
        'trial_rating',
        'trial_feedback',
        'trial_notes',
        'waiting_since',
        'waiting_course_id',
        'waiting_branch_id',
        'preferred_schedule',
        'desired_start_date',
        'waiting_priority',
        'waiting_notes',
        'assigned_test_id',
        'examiner_id',
        'lost_reason',
        'lost_at',
        'converted_student_id',
        'converted_by',
        'commission_user_id',
        'converted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'appointment_at' => 'datetime',
            'trial_at' => 'datetime',
            'waiting_since' => 'date',
            'desired_start_date' => 'date',
            'waiting_priority' => 'integer',
            'deal_value' => 'decimal:2',
            'converted_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignedTest(): BelongsTo
    {
        return $this->belongsTo(PlacementTest::class, 'assigned_test_id');
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'examiner_id');
    }

    public function convertedStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'converted_student_id');
    }

    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    public function commissionUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commission_user_id');
    }

    public function trialTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trial_teacher_id');
    }

    public function trialClass(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'trial_class_id');
    }

    public function waitingCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'waiting_course_id');
    }

    public function waitingBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'waiting_branch_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(CrmCustomerHistory::class, 'customer_id')->latest();
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(PlacementTestSubmission::class, 'customer_id')->latest();
    }

    public function latestSubmission(): HasOne
    {
        return $this->hasOne(PlacementTestSubmission::class, 'customer_id')->latestOfMany();
    }

    public function getStageLabelAttribute(): string
    {
        return match ($this->stage) {
            'new' => 'Mới tiếp nhận',
            'consulting' => 'Tư vấn lộ trình',
            'test_scheduled' => 'Hẹn Test',
            'tested' => 'Đã Test đầu vào',
            'trial_scheduled' => 'Đã hẹn học thử',
            'trial_completed' => 'Đã học thử & phản hồi',
            'waiting_class' => 'Danh sách chờ lớp',
            'closing' => 'Chờ thanh toán',
            'won' => 'Chốt thành công',
            'lost' => 'Không chốt (Lost)',
            default => $this->stage,
        };
    }

    public function getStageBadgeAttribute(): string
    {
        return match ($this->stage) {
            'new' => 'bg-blue-50 text-blue-700 border-blue-200',
            'consulting' => 'bg-amber-50 text-amber-700 border-amber-200',
            'test_scheduled' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'tested' => 'bg-purple-50 text-purple-700 border-purple-200',
            'trial_scheduled' => 'bg-fuchsia-50 text-fuchsia-700 border-fuchsia-200',
            'trial_completed' => 'bg-teal-50 text-teal-700 border-teal-200',
            'waiting_class' => 'bg-yellow-50 text-yellow-700 border-yellow-200',
            'closing' => 'bg-cyan-50 text-cyan-700 border-cyan-200',
            'won' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'lost' => 'bg-rose-50 text-rose-700 border-rose-200',
            default => 'bg-gray-50 text-gray-700 border-gray-200',
        };
    }

    public static function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?: '';
    }

    public function canAdvanceToTested(): bool
    {
        return in_array($this->stage, self::TEST_ADVANCEABLE_STAGES, true);
    }

    public static function generateCode(): string
    {
        return 'KH-'.Str::upper((string) Str::ulid());
    }
}
