<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use App\Services\NotificationService;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CrmCustomer extends Model
{
    use AuditsChanges, HasFactory, SoftDeletes;

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

    /** Khách đang chăm sóc (chưa chốt, chưa thất bại) — áp dụng cảnh báo bỏ quên. */
    public const ACTIVE_STAGES = ['new', 'consulting', 'test_scheduled', 'testing', 'tested', 'result_sent'];

    /** Trường hợp đồng bị khóa khi khách đã Chờ xếp lớp / Đã chốt (audit A3). */
    public const CONTRACT_LOCKED_FIELDS = [
        'deal_value' => 'Giá trị hợp đồng',
        'branch_id' => 'Cơ sở đăng ký',
        'course_interest' => 'Khóa học đăng ký',
    ];

    /**
     * Checklist chăm sóc tháng đầu cho khách đã chốt — 3 mốc của gate hoa hồng A6 (tick đủ 3/3):
     * Buổi 1, Buổi 4–5, Đủ 30 ngày (xem FirstMonthCareService).
     */
    public const CARE_CHECKLIST_ITEMS = [
        'session_1' => 'Buổi 1 — Hỏi phản hồi sau buổi học đầu tiên',
        'session_4_5' => 'Buổi 4–5 — Trao đổi tiến độ với phụ huynh',
        'day_30' => 'Đủ 30 ngày — Đánh giá cuối tháng đầu, mức độ hài lòng',
    ];

    /**
     * Khóa checklist cũ (5 mục, trước 01/10/2026) → mốc mới; null = bỏ (không thuộc gate A6). Migration
     * `2026_10_01_100300_rework_first_month_care_milestones` chuyển dữ liệu theo bảng này.
     */
    public const LEGACY_CARE_CHECKLIST_MAP = [
        'welcome_call' => null,
        'first_session_feedback' => 'session_1',
        'materials_check' => null,
        'week2_parent_update' => 'session_4_5',
        'month_end_review' => 'day_30',
    ];

    /** Nguồn khách mặc định theo mockup Thêm khách mới (khi chưa cấu hình danh mục "lead_source"). */
    public const DEFAULT_SOURCES = ['Landing page', 'Marketing', 'Giới thiệu', 'Vãng lai', 'Tiktok', 'Facebook', 'Google Ads', 'Chị Liên'];

    /** SLA liên hệ khách mới (PRD R13, FR-CRM-02): lần liên hệ đầu trong 24h kể từ lúc tạo. */
    public const FIRST_CONTACT_SLA_HOURS = 24;

    /**
     * Đồng hồ SLA chuyển vàng ("Sắp hết hạn") khi còn dưới tỉ lệ này của khung giờ: 6h với khung 24h, 18h với khung 72h.
     * Hết hạn → đỏ ("Quá hạn"). Chỉ hiển thị, không chặn thao tác nào.
     */
    public const SLA_WARNING_RATIO = 0.25;

    protected $fillable = [
        'code',
        'name',
        'parent_name',
        'parent_phone',
        'phone',
        'phone_normalized',
        'email',
        'deleted_email',
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
        'next_follow_up_at',
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
        'care_checklist',
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
            'next_follow_up_at' => 'datetime',
            'care_checklist' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // SĐT chuẩn hoá phục vụ tìm kiếm / chặn trùng: luôn khớp với SĐT, kể cả khi khách được tạo ngoài form CRM (seeder, script...).
        static::saving(function (CrmCustomer $customer): void {
            if ($customer->trashed() || blank($customer->phone)) {
                return;
            }
            if ($customer->isDirty('phone') || $customer->phone_normalized === null) {
                $customer->phone_normalized = self::normalizePhone($customer->phone);
            }
        });

        // Khách bị xóa (soft delete) nhả SĐT / email khỏi ràng buộc UNIQUE để tạo lại được khách mới.
        static::deleting(function (CrmCustomer $customer): void {
            if ($customer->isForceDeleting()) {
                return;
            }
            $customer->forceFill([
                'phone_normalized' => null,
                'deleted_email' => $customer->email,
                'email' => null,
            ])->saveQuietly();
        });
    }

    /** Khách "Mới" quá 24h chưa được tiếp nhận (SLA liên hệ 24h, cùng tiêu chí cảnh báo stale_lead_24h). */
    public function scopeStaleNew(Builder $query): Builder
    {
        // Sales không đổi giai đoạn (lead vẫn "Mới" sau khi gọi) → đã có nhật ký liên hệ thì không tính là chưa liên hệ.
        return $query->where('stage', 'new')->where('created_at', '<=', now()->subHours(24))
            ->whereDoesntHave('histories', fn (Builder $history) => $history->counted()->whereIn('type', CrmCustomerHistory::CARE_TYPES));
    }

    /** Nạp sẵn thời điểm chăm sóc gần nhất (cột ảo last_care_at) cho đồng hồ SLA trên danh sách — tránh N+1. */
    public function scopeWithLastCare(Builder $query): Builder
    {
        return $query->withMax(['histories as last_care_at' => fn (Builder $history) => $history->counted()->whereIn('type', CrmCustomerHistory::CARE_TYPES)], 'created_at');
    }

    /**
     * Phạm vi dữ liệu CRM theo quyền "lead.scope_*" (DataScope; mặc định theo BA 2026-09-25):
     * - Toàn hệ thống (Admin): mọi khách.
     * - Chi nhánh (Quản lý cơ sở / Học vụ / Học thuật): khách thuộc chi nhánh của mình (branch_id + user_branches).
     * - Của tôi (Sales): khách được giao phụ trách.
     * Không đăng nhập (job / lệnh nền): không lọc.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query;
        }

        $model = $query->getModel();

        return DataScope::apply(
            $query, $user, 'lead',
            fn (Builder $q) => $q->where($model->qualifyColumn('assigned_user_id'), $user->id),
            fn (Builder $q, array $branchIds) => $q->whereIn($model->qualifyColumn('branch_id'), $branchIds),
        );
    }

    /** @return list<int> */
    public static function branchIdsOf(User $user): array
    {
        return $user->branchIds();
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

    /** Hủy các buổi học thử đang chờ (khách thất bại / bị xóa) để giáo viên không còn thấy khách trong lịch học thử. */
    public function cancelPendingTrialBookings(string $reason): int
    {
        return $this->trialBookings()->where('status', 'scheduled')->get()
            ->each(fn (CrmTrialBooking $booking) => $booking->update([
                'status' => 'cancelled',
                'notes' => trim(($booking->notes ? $booking->notes."\n" : '').'Hủy: '.$reason),
            ]))->count();
    }

    public function waitingCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'waiting_course_id');
    }

    public function waitingBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'waiting_branch_id');
    }

    public function branchTransfers(): HasMany
    {
        return $this->hasMany(CrmBranchTransfer::class, 'customer_id')->latest();
    }

    /** Yêu cầu chuyển cơ sở đang chờ Admin duyệt (mỗi khách tối đa 1). */
    public function pendingBranchTransfer(): HasOne
    {
        return $this->hasOne(CrmBranchTransfer::class, 'customer_id')->where('status', CrmBranchTransfer::STATUS_PENDING)->latestOfMany();
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

    /** Nhãn giai đoạn dạng "soft badge" theo màu cột mockup Pipeline. */
    public static function stageBadge(?string $stage): string
    {
        return match ($stage) {
            'new' => 'bg-secondary/10 text-secondary border-secondary/20',
            'consulting' => 'bg-tertiary/10 text-tertiary border-tertiary/20',
            'test_scheduled' => 'bg-primary/10 text-primary border-primary/20',
            'testing' => 'bg-info/10 text-info border-info/20',
            'tested' => 'bg-accent/10 text-accent border-accent/20',
            'result_sent' => 'bg-warning/10 text-warning border-warning/20',
            'waiting_class' => 'bg-on-info-container/10 text-on-info-container border-on-info-container/20',
            'won' => 'bg-tertiary/10 text-tertiary border-tertiary/20',
            'lost' => 'bg-error/10 text-error border-error/20',
            default => 'bg-on-surface-variant/10 text-on-surface-variant border-outline-variant',
        };
    }

    /**
     * Lớp màu cho cột Kanban / thanh funnel theo mockup pipeline-tong-quan-giai-doan
     * (chuỗi đầy đủ để Tailwind quét được — tailwind.config.js quét app/**).
     *
     * @return array{border: string, badge: string, bar: string, text: string, header_border: string, source_badge: string}
     */
    public static function stageStyle(string $stage): array
    {
        return match ($stage) {
            'new' => ['border' => 'border-secondary', 'badge' => 'bg-secondary/10 text-secondary', 'bar' => 'bg-secondary', 'text' => 'text-secondary', 'header_border' => 'border-secondary/20', 'source_badge' => 'bg-secondary/10 text-secondary'],
            'consulting' => ['border' => 'border-tertiary', 'badge' => 'bg-tertiary/10 text-tertiary', 'bar' => 'bg-tertiary', 'text' => 'text-tertiary', 'header_border' => 'border-tertiary/20', 'source_badge' => 'bg-tertiary/10 text-tertiary'],
            'test_scheduled' => ['border' => 'border-primary', 'badge' => 'bg-primary/10 text-primary', 'bar' => 'bg-primary', 'text' => 'text-primary', 'header_border' => 'border-primary/20', 'source_badge' => 'bg-primary/10 text-primary'],
            'testing' => ['border' => 'border-info', 'badge' => 'bg-info/10 text-info', 'bar' => 'bg-info', 'text' => 'text-info', 'header_border' => 'border-info/20', 'source_badge' => 'bg-info/10 text-info'],
            'tested' => ['border' => 'border-accent', 'badge' => 'bg-accent/10 text-accent', 'bar' => 'bg-accent', 'text' => 'text-accent', 'header_border' => 'border-accent/20', 'source_badge' => 'bg-accent/10 text-accent'],
            'result_sent' => ['border' => 'border-warning', 'badge' => 'bg-warning/10 text-warning', 'bar' => 'bg-warning', 'text' => 'text-warning', 'header_border' => 'border-warning/20', 'source_badge' => 'bg-warning/10 text-warning'],
            'waiting_class' => ['border' => 'border-on-info-container', 'badge' => 'bg-on-info-container/10 text-on-info-container', 'bar' => 'bg-on-info-container', 'text' => 'text-on-info-container', 'header_border' => 'border-on-info-container/20', 'source_badge' => 'bg-on-info-container/10 text-on-info-container'],
            'won' => ['border' => 'border-tertiary', 'badge' => 'bg-tertiary/10 text-tertiary', 'bar' => 'bg-tertiary', 'text' => 'text-tertiary', 'header_border' => 'border-tertiary/20', 'source_badge' => 'bg-tertiary/10 text-tertiary'],
            default => ['border' => 'border-error', 'badge' => 'bg-error/10 text-error', 'bar' => 'bg-error', 'text' => 'text-error', 'header_border' => 'border-error/20', 'source_badge' => 'bg-error/10 text-error'],
        };
    }

    /**
     * Mã khách để hiển thị: mã dài KH-<ULID> rút gọn còn KH-<6 ký tự cuối> (vẫn tìm được vì ô tìm khớp một phần mã);
     * mã đầy đủ giữ trong `code` (tooltip, nội dung chuyển khoản).
     */
    public function getShortCodeAttribute(): string
    {
        $code = (string) $this->code;

        return preg_match('/^KH-[0-9A-Z]{26}$/', $code) ? 'KH-'.substr($code, -6) : $code;
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

    /**
     * Chuẩn hoá SĐT về chuỗi số; đầu số quốc tế +84 / 84 (11 số) đổi về 0.
     */
    public static function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';
        // +84 9x xxx xxxx (11 số) hoặc máy bàn +84 24 xxxx xxxx (12 số).
        if (in_array(strlen($digits), [11, 12], true) && str_starts_with($digits, '84')) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }

    /**
     * SĐT Việt Nam hợp lệ: di động 10 số (03/05/07/08/09) hoặc cố định 11 số (02x),
     * chấp nhận khoảng trắng / dấu chấm / gạch và tiền tố +84.
     */
    public static function isValidVietnamesePhone(?string $phone): bool
    {
        $raw = trim((string) $phone);
        if ($raw === '' || preg_match('/[^\d\s.\-+()]/', $raw)) {
            return false;
        }

        return (bool) preg_match('/^(0[35789]\d{8}|02\d{9})$/', self::normalizePhone($raw));
    }

    /** Khung SLA "chăm sóc tiếp theo" (giờ) = ngưỡng khách bị bỏ quên, mặc định 3 ngày = 72h (system_settings.crm_neglect_days). */
    public static function followUpSlaHours(): int
    {
        return app(NotificationService::class)->neglectThresholdDays() * 24;
    }

    /** Lần chăm sóc gần nhất (CrmCustomerHistory::CARE_TYPES): cột ảo scopeWithLastCare, histories đã nạp, hoặc 1 truy vấn. */
    public function lastCareAt(): ?Carbon
    {
        if (array_key_exists('last_care_at', $this->attributes)) {
            return $this->attributes['last_care_at'] ? Carbon::parse($this->attributes['last_care_at']) : null;
        }
        $latest = $this->relationLoaded('histories')
            ? $this->histories->whereIn('type', CrmCustomerHistory::CARE_TYPES)->where('outcome', '!=', CrmCustomerHistory::OUTCOME_FAILED)->max('created_at')
            : $this->histories()->counted()->whereIn('type', CrmCustomerHistory::CARE_TYPES)->max('created_at');

        return $latest ? Carbon::parse($latest) : null;
    }

    /**
     * Đồng hồ SLA liên hệ (PRD R13, FR-CRM-02), chỉ cho khách đang chăm sóc (ACTIVE_STAGES):
     * - Khách "Mới" chưa được chăm sóc lần nào: hạn = lúc tạo + 24h ("Hạn liên hệ lần đầu").
     * - Còn lại: hạn = lần chăm sóc gần nhất (không có thì lúc tạo) + 72h ("Hạn chăm sóc tiếp theo").
     * - Có "Hạn liên hệ tiếp theo" (người phụ trách tự hẹn) sớm hơn thì lấy hạn hẹn.
     * state: on_time (xanh) | due_soon (vàng, còn < 25% khung) | overdue (đỏ). Chỉ hiển thị, không chặn thao tác.
     *
     * @return array{kind: string, label: string, deadline: Carbon, warn_seconds: int, state: string, remaining: string}|null
     */
    public function contactSla(?Carbon $now = null): ?array
    {
        if (! in_array($this->stage, self::ACTIVE_STAGES, true) || ! $this->created_at) {
            return null;
        }
        $now ??= now();
        $lastCare = $this->lastCareAt();
        if ($lastCare === null && $this->stage === 'new') {
            [$kind, $label, $hours, $anchor] = ['first', 'Hạn liên hệ lần đầu', self::FIRST_CONTACT_SLA_HOURS, $this->created_at];
        } else {
            [$kind, $label, $hours, $anchor] = ['follow_up', 'Hạn chăm sóc tiếp theo', self::followUpSlaHours(), $lastCare ?? $this->created_at];
        }
        $deadline = $anchor->copy()->addHours($hours);
        if ($this->next_follow_up_at && $this->next_follow_up_at->lt($deadline)) {
            [$kind, $label, $deadline] = ['appointment', $this->stage === 'new' ? 'Hạn liên hệ (đã hẹn)' : 'Hạn chăm sóc tiếp theo (đã hẹn)', $this->next_follow_up_at->copy()];
        }
        $warnSeconds = (int) round($hours * 3600 * self::SLA_WARNING_RATIO);
        $state = match (true) {
            $deadline->lt($now) => 'overdue',
            $now->diffInSeconds($deadline) < $warnSeconds => 'due_soon',
            default => 'on_time',
        };

        return [
            'kind' => $kind,
            'label' => $label,
            'deadline' => $deadline,
            'warn_seconds' => $warnSeconds,
            'state' => $state,
            'remaining' => self::remainingLabel($deadline, $now),
        ];
    }

    /** Hạn liên hệ theo đồng hồ SLA: overdue (Quá hạn) | due_soon (Sắp hết hạn) | null (còn hạn / không áp dụng). */
    public function followUpStatus(?Carbon $now = null): ?string
    {
        $state = $this->contactSla($now)['state'] ?? null;

        return $state === 'on_time' ? null : $state;
    }

    /** Đồng hồ đếm ngược: "Còn 2 giờ 14 phút" / "Quá hạn 1 ngày 3 giờ". */
    public static function remainingLabel(\Carbon\CarbonInterface $at, ?\Carbon\CarbonInterface $now = null): string
    {
        $diff = ($now ?? now())->diff($at);
        $parts = array_filter([
            $diff->days ? $diff->days.' ngày' : null,
            $diff->h ? $diff->h.' giờ' : null,
            ! $diff->days && $diff->i ? $diff->i.' phút' : null,
        ]);
        $text = $parts ? implode(' ', $parts) : 'dưới 1 phút';

        return $diff->invert ? 'Quá hạn '.$text : 'Còn '.$text;
    }

    /** Dữ liệu đồng hồ SLA gửi xuống Vue (<CrmSlaCountdown>), null khi không áp dụng. */
    public function contactSlaPayload(?Carbon $now = null): ?array
    {
        $sla = $this->contactSla($now);

        return $sla ? [
            'kind' => $sla['kind'],
            'label' => $sla['label'],
            'deadline' => $sla['deadline']->toIso8601String(),
            'deadline_label' => $sla['deadline']->format('H:i d/m/Y'),
            'warn_seconds' => $sla['warn_seconds'],
            'state' => $sla['state'],
            'remaining' => $sla['remaining'],
        ] : null;
    }

    public function isContractLocked(): bool
    {
        return $this->isClosed() || (bool) $this->converted_student_id;
    }

    public static function generateCode(): string
    {
        return 'KH-'.Str::upper((string) Str::ulid());
    }
}
