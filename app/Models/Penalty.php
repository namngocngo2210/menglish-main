<?php

namespace App\Models;

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

    /** Số ngày nhân sự phải nộp phạt kể từ khi được quyết phạt. */
    public const PAYMENT_DUE_DAYS = 2;

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

    /** Lỗi thường gặp, nhóm theo loại lỗi (gợi ý khi lập biên bản). */
    public const COMMON_VIOLATIONS = [
        'academic' => [
            'Chậm nộp nhận xét buổi học (> 24h)',
            'Không nộp giáo án / bài tập đúng hạn',
            'Dạy sai tiến độ giáo trình',
            'Không nhập điểm / kết quả kiểm tra đúng hạn',
        ],
        'operations' => [
            'Đến muộn > 15 phút không báo trước',
            'Nghỉ dạy không phép',
            'Không check-in / điểm danh đúng giờ',
            'Vi phạm nội quy trung tâm',
        ],
    ];

    /** Trạng thái còn mở (chưa đóng hồ sơ). */
    public const OPEN_STATUSES = ['pending', 'explained', 'confirmed', 'fined'];

    protected $table = 'penalties';

    protected $fillable = [
        'code',
        'user_id',
        'class_id',
        'violation_type',
        'error_category',
        'violation_date',
        'amount',
        'reporter_id',
        'status',
        'payroll_record_id',
        'notes',
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
            return 'bg-red-50 text-red-700 border-red-300';
        }

        return match ($this->status) {
            'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
            'explained' => 'bg-blue-50 text-blue-700 border-blue-200',
            'confirmed' => 'bg-rose-50 text-rose-700 border-rose-200',
            'fined' => 'bg-orange-50 text-orange-700 border-orange-200',
            'deducted' => 'bg-purple-50 text-purple-700 border-purple-200',
            'paid' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'resolved' => 'bg-sky-50 text-sky-700 border-sky-200',
            'cancelled' => 'bg-gray-50 text-gray-700 border-gray-200',
            default => 'bg-gray-50 text-gray-700 border-gray-200',
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
            'fined' => 'Đã quyết phạt (chờ nộp trong 2 ngày)',
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
