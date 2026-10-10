<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Biên bản vi phạm (BPMN 9b):
 *   pending (chờ giải trình) → explained (đã giải trình)
 *   → HT/CM chốt theo loại lỗi: confirmed (xác nhận lỗi) | fined (quyết phạt, hạn nộp 2 ngày)
 *     | resolved (đóng vụ, không phạt tiền) | cancelled (hủy, không có lỗi)
 *   fined → paid (nộp trực tiếp trong hạn) | deducted (quá hạn → trừ vào kỳ lương đã duyệt).
 */
class Penalty extends Model
{
    use HasFactory;

    /** Số ngày nhân sự phải nộp phạt kể từ khi được quyết phạt (SLA penalty.payment_due); quá hạn không ghi nhận nộp trực tiếp nữa (trừ lương). */
    public static function paymentDueDays(): int
    {
        return \App\Services\Sla\Sla::value('penalty.payment_due');
    }

    /** Vi phạm phải được ghi nhận (thủ công) trong vòng N giờ kể từ lúc xảy ra — chủ dự án chốt (SLA penalty.record_window). */
    public static function recordWindowHours(): int
    {
        return \App\Services\Sla\Sla::value('penalty.record_window');
    }

    /** Bằng chứng vi phạm: ảnh / PDF, lưu disk riêng tư (xem qua route penalties.evidence, có kiểm tra quyền). */
    public const EVIDENCE_DISK = 'local';

    public const EVIDENCE_DIRECTORY = 'penalties/evidence';

    public const EVIDENCE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

    public const EVIDENCE_MAX_KB = 10240;

    /**
     * Loại lỗi → quyền chốt. Lỗi chuyên môn: violation.decide_academic (mặc định Học thuật — HT); lỗi vận hành:
     * violation.decide_operations (mặc định Học vụ / Quản lý cơ sở — CM). Admin luôn được chốt.
     */
    public const CATEGORIES = [
        'academic' => [
            'label' => 'Lỗi chuyên môn / giảng dạy',
            'confirmer' => 'Học thuật (HT)',
            'permission' => 'violation.decide_academic',
        ],
        'operations' => [
            'label' => 'Lỗi vận hành / nội quy',
            'confirmer' => 'Học vụ / Quản lý (CM)',
            'permission' => 'violation.decide_operations',
        ],
    ];

    /** Lỗi chung của nhân sự văn phòng (Học vụ, Học thuật, Tư vấn viên, Quản lý cơ sở). */
    private const OFFICE_VIOLATIONS = [
        'Đi làm muộn không báo trước',
        'Nghỉ làm không phép',
        'Vắng họp / đào tạo bắt buộc',
        'Vi phạm nội quy trung tâm',
    ];

    /** Lỗi hạn SLA CRM của người phụ trách khách (tên trùng biên bản hệ thống tự lập, config/sla.php 'violation'). */
    private const CRM_VIOLATIONS = [
        'Quá hạn SLA liên hệ khách mới',
        'Quá hạn SLA chuyển trạng thái chăm sóc khách',
        'Quá hạn SLA trả kết quả test đầu vào',
        'Quá hạn SLA phản hồi sau học thử',
    ];

    private const TEACHER_VIOLATIONS = [
        'academic' => [
            'Gửi nhận xét sau buổi học trễ',
            'Không nộp giáo án / bài tập đúng hạn',
            'Dạy sai tiến độ giáo trình',
            'Không nhập điểm / kết quả kiểm tra đúng hạn',
            'Trả kết quả Big Test trễ',
        ],
        'operations' => [
            'Đến muộn > 15 phút không báo trước',
            'Nghỉ dạy không phép',
            'Không check-in / điểm danh đúng giờ',
            'Không thông báo kịp thời vấn đề lớp (nghỉ / đổi ca / case học sinh)',
            'Vắng họp / đào tạo bắt buộc',
            'Vi phạm nội quy trung tâm',
        ],
    ];

    /**
     * Lỗi thường gặp theo vai trò của nhân sự vi phạm, nhóm theo loại lỗi: ô "Lỗi vi phạm" chỉ gợi ý lỗi của vai trò người
     * được chọn (kiêm nhiều vai trò thì gộp), chọn lỗi có sẵn thì loại lỗi (người chốt) đi theo lỗi. Lỗi có SLA tự lập biên
     * bản dùng đúng tên trong config/sla.php để biên bản lập tay và tự động cùng một tên lỗi.
     */
    public const ROLE_VIOLATIONS = [
        Roles::TEACHER_FULLTIME => self::TEACHER_VIOLATIONS,
        Roles::TEACHER_PARTTIME => self::TEACHER_VIOLATIONS,
        Roles::ASSISTANT => [
            'academic' => [
                'Không hoàn thành nhiệm vụ trợ giảng được giao',
            ],
            'operations' => [
                'Đến muộn > 15 phút không báo trước',
                'Nghỉ trực lớp không phép',
                'Không check-in / điểm danh đúng giờ',
                'Vắng họp / đào tạo bắt buộc',
                'Vi phạm nội quy trung tâm',
            ],
        ],
        Roles::ACADEMIC_STAFF => [
            'operations' => [
                ...self::CRM_VIOLATIONS,
                'Quá hạn SLA chăm sóc học viên tháng đầu',
                'Không nhắc / follow học phí đúng quy trình',
                'Thu sai / thiếu học phí',
                'Sai sót dữ liệu học sinh / học phí trên hệ thống',
                'Nộp báo cáo ngày trễ hạn',
                'Sự cố vận hành lớp do lỗi học vụ',
                'Order học liệu quá hạn',
                ...self::OFFICE_VIOLATIONS,
            ],
        ],
        Roles::ACADEMIC_LEAD => [
            'academic' => [
                'Trễ deadline mốc dự án học thuật',
                'Quá hạn duyệt đề Big Test',
                'Order học liệu quá hạn',
                'Xử lý phản ánh về GV trễ hạn',
                'Không đào tạo GV đúng kế hoạch',
            ],
            'operations' => self::OFFICE_VIOLATIONS,
        ],
        Roles::SALES_CONSULTANT => [
            'operations' => [
                ...self::CRM_VIOLATIONS,
                'Nhập sai / thiếu dữ liệu khách hàng trên CRM',
                ...self::OFFICE_VIOLATIONS,
            ],
        ],
        Roles::MANAGER => [
            'operations' => [
                'Thu sai / thiếu học phí',
                'Tiền mặt chưa nộp về TK công ty',
                'Quá hạn xử lý hoàn phí / chuyển nhượng',
                'Nộp báo cáo trễ hạn',
                ...self::OFFICE_VIOLATIONS,
            ],
        ],
    ];

    /**
     * Lỗi thường gặp của các vai trò (lỗi => loại lỗi), theo thứ tự vai trò rồi loại lỗi; lỗi trùng giữ loại lỗi gặp đầu tiên.
     * Không có vai trò nào trong ROLE_VIOLATIONS (Admin, vai trò tự tạo) → lỗi của mọi vai trò.
     *
     * @param  iterable<string>  $roles
     * @return array<string, string>
     */
    public static function commonViolationsFor(iterable $roles): array
    {
        $roles = collect($roles)->filter(fn ($role) => isset(self::ROLE_VIOLATIONS[$role]));
        if ($roles->isEmpty()) {
            $roles = collect(array_keys(self::ROLE_VIOLATIONS));
        }

        $list = [];
        foreach ($roles as $role) {
            foreach (self::ROLE_VIOLATIONS[$role] as $category => $types) {
                foreach ($types as $type) {
                    $list[$type] ??= $category;
                }
            }
        }

        return $list;
    }

    /** Trạng thái còn mở (chưa đóng hồ sơ). */
    public const OPEN_STATUSES = ['pending', 'explained', 'confirmed', 'fined'];

    protected $table = 'penalties';

    protected $fillable = [
        'code',
        'user_id',
        'class_id',
        'work_task_id',
        'big_test_id',
        'auto_source',
        'violation_type',
        'error_category',
        'violation_date',
        'violation_at',
        'amount',
        'reporter_id',
        'status',
        'payroll_record_id',
        'notes',
        'evidence_path',
        'explanation',
        'explained_at',
        'decided_by',
        'decided_at',
        'decision_note',
        'due_date',
        'paid_at',
        'remedied_at',
        'remedied_by',
        'remedy_note',
    ];

    protected $casts = [
        'violation_date' => 'date',
        'violation_at' => 'datetime',
        'amount' => 'decimal:2',
        'explained_at' => 'datetime',
        'decided_at' => 'datetime',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'remedied_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    /** Việc (chăm sóc tháng đầu) quá SLA đã sinh ra biên bản này. */
    public function workTask(): BelongsTo
    {
        return $this->belongsTo(WorkTask::class, 'work_task_id');
    }

    /** Bản ghi lương đã trừ biên bản này ở lần tính gần nhất. */
    public function payrollRecord(): BelongsTo
    {
        return $this->belongsTo(PayrollRecord::class, 'payroll_record_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function remedier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remedied_by');
    }

    /**
     * Các bước xử lý theo mockup "Danh sách vi phạm" (lọc theo bước) → trạng thái trong hệ thống.
     * "Đã khắc phục" là mốc riêng (remedied_at) sau khi đã nộp / đã trừ lương.
     */
    public const STEPS = [
        'recorded' => 'Ghi nhận',
        'confirmed' => 'Đã chốt lỗi',
        'fined' => 'Đã chốt phạt',
        'paid' => 'Đã nộp',
        'remedied' => 'Đã khắc phục',
        'resolved' => 'Đóng - không phạt',
        'cancelled' => 'Đã hủy',
    ];

    public function scopeAtStep($query, string $step)
    {
        return match ($step) {
            'recorded' => $query->whereIn('status', ['pending', 'explained']),
            'confirmed' => $query->where('status', 'confirmed'),
            'fined' => $query->where('status', 'fined'),
            'paid' => $query->whereIn('status', ['paid', 'deducted'])->whereNull('remedied_at'),
            'remedied' => $query->whereIn('status', ['paid', 'deducted'])->whereNotNull('remedied_at'),
            'resolved' => $query->where('status', 'resolved'),
            'cancelled' => $query->where('status', 'cancelled'),
            default => $query,
        };
    }

    public function getStepAttribute(): string
    {
        return match ($this->status) {
            'pending', 'explained' => 'recorded',
            'confirmed' => 'confirmed',
            'fined' => 'fined',
            'paid', 'deducted' => $this->remedied_at ? 'remedied' : 'paid',
            'resolved' => 'resolved',
            default => 'cancelled',
        };
    }

    public function getStepLabelAttribute(): string
    {
        return self::STEPS[$this->step] ?? $this->status_label;
    }

    /** Nguồn ghi nhận: có người lập biên bản = Thủ công; hệ thống tự tạo (không có người lập) = Tự động. */
    public function getSourceLabelAttribute(): string
    {
        return $this->reporter_id ? 'Thủ công' : 'Tự động';
    }

    /**
     * "Trạng thái GV" (mockup): nhân sự đã giải trình = Đã xác nhận; còn chờ giải trình = Chờ xác nhận;
     * biên bản đã chốt / đóng mà nhân sự không giải trình = Bỏ qua.
     *
     * @return array{0: string, 1: string} [nhãn, màu badge]
     */
    public function getEmployeeStateAttribute(): array
    {
        if ($this->explanation) {
            return ['Đã xác nhận', 'success'];
        }
        if ($this->status === 'pending') {
            return ['Chờ xác nhận', 'warning'];
        }

        return ['Bỏ qua', 'neutral'];
    }

    public function getStatusBadgeAttribute(): string
    {
        if ($this->isOverdue()) {
            return 'bg-error/10 text-error border-error/30';
        }

        return match ($this->status) {
            'pending' => 'bg-warning/10 text-warning border-warning/30',
            'explained' => 'bg-secondary/10 text-secondary border-secondary/30',
            'confirmed' => 'bg-error/10 text-error border-error/30',
            'fined' => 'bg-primary-container/10 text-primary border-primary-container/30',
            'deducted' => 'bg-accent-container text-accent border-accent/30',
            'paid' => 'bg-tertiary/10 text-tertiary border-tertiary/30',
            'resolved' => 'bg-info/10 text-info border-info/30',
            'cancelled' => 'bg-surface-container-low text-on-surface-variant border-surface-container-highest',
            default => 'bg-surface-container-low text-on-surface-variant border-surface-container-highest',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->isOverdue()) {
            return 'Quá hạn nộp — sẽ trừ lương';
        }

        return self::statusLabels()[$this->status] ?? (string) $this->status;
    }

    public static function statusLabels(): array
    {
        return [
            'pending' => 'Chờ giải trình',
            'explained' => 'Đã giải trình — chờ chốt',
            'confirmed' => 'Đã xác nhận lỗi',
            'fined' => 'Đã quyết phạt (chờ nộp trong '.self::paymentDueDays().' ngày)',
            'deducted' => 'Đã trừ vào bảng lương',
            'paid' => 'Đã nộp phạt trực tiếp',
            'resolved' => 'Đã xử lý (không phạt tiền)',
            'cancelled' => 'Đã hủy biên bản',
        ];
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->error_category]['label'] ?? 'Chưa phân loại';
    }

    public function getConfirmerLabelAttribute(): string
    {
        return self::CATEGORIES[$this->error_category]['confirmer'] ?? 'HT / CM';
    }

    /** Đã quyết phạt, quá hạn nộp mà chưa nộp → bảng lương sẽ trừ. */
    public function isOverdue(): bool
    {
        return $this->status === 'fined' && $this->due_date !== null && $this->due_date->lt(today());
    }

    /**
     * Người được chốt biên bản theo loại lỗi. Người vi phạm không tự chốt biên bản của mình.
     * Biên bản cũ chưa phân loại: HT hoặc CM đều được chốt.
     */
    public function canBeDecidedBy(User $user): bool
    {
        // Quan hệ: người vi phạm không tự chốt biên bản của mình (Super Admin được chốt mọi biên bản).
        if ($user->id === $this->user_id && ! $user->isSuperAdmin()) {
            return false;
        }

        $permissions = isset(self::CATEGORIES[$this->error_category])
            ? [self::CATEGORIES[$this->error_category]['permission']]
            : collect(self::CATEGORIES)->pluck('permission')->all();

        return collect($permissions)->contains(fn (string $permission) => $user->can($permission));
    }

    /** Biên bản đã được trừ vào bản ghi lương của một kỳ đã duyệt/đã chi trả. */
    public function isLockedByPayroll(): bool
    {
        if ($this->status === 'deducted') {
            return true;
        }

        return $this->payroll_record_id !== null
            && in_array($this->payrollRecord?->period?->status, PayrollPeriod::LOCKED_STATUSES, true);
    }

    /**
     * Trạng thái mà bảng lương sẽ thu nộp khi tính kỳ lương.
     */
    public static function payableStatuses(): array
    {
        return ['fined'];
    }

    /**
     * Biên bản phải trừ vào kỳ lương [start, end] tại thời điểm tính:
     * - đã quyết phạt, chưa nộp, hạn nộp đã qua (trước hôm nay) và nằm trong/ trước kỳ;
     * - biên bản cũ chưa có hạn nộp: giữ luật cũ (ngày vi phạm trong kỳ).
     * Biên bản đã gắn vào bản ghi lương của kỳ khác thì không trừ lại.
     */
    public function scopeDeductibleFor(Builder $query, PayrollPeriod $period): Builder
    {
        $end = $period->end_date->toDateString();
        $today = today()->toDateString();

        return $query->whereIn('status', self::payableStatuses())
            ->where(function ($q) use ($period, $end, $today) {
                $q->where(fn ($due) => $due->whereNotNull('due_date')
                    ->whereDate('due_date', '<=', $end)
                    ->whereDate('due_date', '<', $today))
                    ->orWhere(fn ($legacy) => $legacy->whereNull('due_date')
                        ->whereBetween('violation_date', [$period->start_date, $period->end_date]));
            })
            ->where(fn ($q) => $q->whereNull('payroll_record_id')
                ->orWhereIn('payroll_record_id', $period->records()->select('id')));
    }

    /**
     * Mã biên bản BB-YYYY-NNN, lấy từ bộ sinh mã dùng chung (không đếm bản ghi nữa).
     */
    public static function generateCode(): string
    {
        return app(\App\Services\DocumentCodeGenerator::class)->penaltyCode();
    }
}
