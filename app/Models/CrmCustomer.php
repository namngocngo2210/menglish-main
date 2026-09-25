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

    /**
     * Pipeline 8 bước theo đúng thứ tự (BA chốt 2026-09-25). 'lost' (Thất bại) nằm ngoài pipeline.
     *
     * @var array<string, string> stage => nhãn tiếng Việt
     */
    public const PIPELINE_STAGES = [
        'new' => 'Mới',
        'consulting' => 'Đang tư vấn',
        'test_scheduled' => 'Hẹn test',
        'testing' => 'Test',
        'tested' => 'Đã test',
        'result_sent' => 'Gửi kết quả',
        'waiting_class' => 'Chờ xếp lớp',
        'won' => 'Đã chốt',
    ];

    public const STAGE_LOST = 'lost';

    /** Đã chốt (có hồ sơ học viên): không lùi bước, không chuyển sang thất bại. */
    public const CLOSED_STAGES = ['waiting_class', 'won'];

    /** Được phép "Chốt & Xếp lớp" từ các bước này (consulting = nhánh không test). */
    public const CLOSABLE_STAGES = ['consulting', 'tested', 'result_sent'];

    /** Stage lead nhận bài test online (link hoặc khớp SĐT) — lead chỉ đi tiến. */
    public const TEST_ADVANCEABLE_STAGES = ['consulting', 'test_scheduled', 'testing'];

    /** Học thử là hoạt động trong giai đoạn tư vấn, không áp dụng cho lead đã chốt / thất bại. */
    public const TRIAL_BOOKABLE_STAGES = ['consulting', 'test_scheduled', 'testing', 'tested', 'result_sent'];

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
        'fee_paid_at_closing',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'appointment_at' => 'datetime',
            'waiting_since' => 'date',
            'desired_start_date' => 'date',
            'waiting_priority' => 'integer',
            'deal_value' => 'decimal:2',
            'converted_at' => 'datetime',
            'lost_at' => 'datetime',
            'fee_paid_at_closing' => 'boolean',
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

    public function trialBookings(): HasMany
    {
        return $this->hasMany(CrmTrialBooking::class, 'customer_id');
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

    public static function stageLabel(?string $stage): string
    {
        return self::PIPELINE_STAGES[$stage] ?? ($stage === self::STAGE_LOST ? 'Thất bại' : (string) $stage);
    }

    public static function stageBadge(?string $stage): string
    {
        return match ($stage) {
            'new' => 'bg-sky-50 text-sky-700 border-sky-200',
            'consulting' => 'bg-amber-50 text-amber-700 border-amber-200',
            'test_scheduled' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'testing' => 'bg-violet-50 text-violet-700 border-violet-200',
            'tested' => 'bg-purple-50 text-purple-700 border-purple-200',
            'result_sent' => 'bg-cyan-50 text-cyan-700 border-cyan-200',
            'waiting_class' => 'bg-yellow-50 text-yellow-700 border-yellow-200',
            'won' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'lost' => 'bg-rose-50 text-rose-700 border-rose-200',
            default => 'bg-gray-50 text-gray-700 border-gray-200',
        };
    }

    /**
     * Lớp màu cho cột Kanban / thanh funnel (chuỗi đầy đủ để Tailwind quét được — xem
     * danh sách lặp lại trong crm/pipeline.blade.php).
     *
     * @return array{border: string, badge: string, bar: string, text: string}
     */
    public static function stageStyle(string $stage): array
    {
        return match ($stage) {
            'new' => ['border' => 'border-sky-500', 'badge' => 'bg-sky-50 text-sky-700', 'bar' => 'bg-sky-500', 'text' => 'text-sky-600'],
            'consulting' => ['border' => 'border-amber-500', 'badge' => 'bg-amber-50 text-amber-700', 'bar' => 'bg-amber-500', 'text' => 'text-amber-600'],
            'test_scheduled' => ['border' => 'border-indigo-500', 'badge' => 'bg-indigo-50 text-indigo-700', 'bar' => 'bg-indigo-500', 'text' => 'text-indigo-600'],
            'testing' => ['border' => 'border-violet-500', 'badge' => 'bg-violet-50 text-violet-700', 'bar' => 'bg-violet-500', 'text' => 'text-violet-600'],
            'tested' => ['border' => 'border-purple-500', 'badge' => 'bg-purple-50 text-purple-700', 'bar' => 'bg-purple-500', 'text' => 'text-purple-600'],
            'result_sent' => ['border' => 'border-cyan-500', 'badge' => 'bg-cyan-50 text-cyan-700', 'bar' => 'bg-cyan-500', 'text' => 'text-cyan-600'],
            'waiting_class' => ['border' => 'border-yellow-500', 'badge' => 'bg-yellow-50 text-yellow-700', 'bar' => 'bg-yellow-500', 'text' => 'text-yellow-600'],
            'won' => ['border' => 'border-emerald-500', 'badge' => 'bg-emerald-50 text-emerald-700', 'bar' => 'bg-emerald-500', 'text' => 'text-emerald-600'],
            default => ['border' => 'border-rose-500', 'badge' => 'bg-rose-50 text-rose-700', 'bar' => 'bg-rose-500', 'text' => 'text-rose-600'],
        };
    }

    public function getStageLabelAttribute(): string
    {
        return self::stageLabel($this->stage);
    }

    public function getStageBadgeAttribute(): string
    {
        return self::stageBadge($this->stage);
    }

    /** Lead còn nhận kết quả test (chưa qua bước Đã test, chưa chốt / thất bại). */
    public function canAdvanceToTested(): bool
    {
        return in_array($this->stage, self::TEST_ADVANCEABLE_STAGES, true);
    }

    public function isClosed(): bool
    {
        return in_array($this->stage, self::CLOSED_STAGES, true);
    }

    public static function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?: '';
    }

    public static function generateCode(): string
    {
        return 'KH-'.Str::upper((string) Str::ulid());
    }
}
