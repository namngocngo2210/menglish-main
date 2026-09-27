<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StudentTuition extends Model
{
    use HasFactory;

    protected $table = 'student_tuitions';

    protected $fillable = [
        'student_id',
        'class_id',
        'branch_id',
        'bank_account_id',
        'promotion_id',
        'total_amount',
        'discount_amount',
        'other_fees',
        'fee_items',
        'prepaid_amount',
        'final_amount',
        'paid_amount',
        'debt_amount',
        'due_date',
        'reminder_paused_until',
        'deferred_from',
        'deferred_until',
        'frozen_remaining_sessions',
        'frozen_debt_amount',
        'status',
        'debt_status',
        'notes',
        'transfer_memo',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'other_fees' => 'decimal:2',
        'fee_items' => 'array',
        'prepaid_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'debt_amount' => 'decimal:2',
        'due_date' => 'date',
        'reminder_paused_until' => 'date',
        'deferred_from' => 'date',
        'deferred_until' => 'date',
        'frozen_remaining_sessions' => 'integer',
        'frozen_debt_amount' => 'decimal:2',
    ];

    /** Nhắc nợ đang tạm dừng (khất nợ / bảo lưu) tại ngày $on (mặc định hôm nay). */
    public function remindersPausedOn($on = null): bool
    {
        $day = ($on ? Carbon::parse($on) : now())->startOfDay();

        return $this->reminder_paused_until !== null && $this->reminder_paused_until->copy()->startOfDay()->gt($day);
    }

    /**
     * Quá hạn thực tế: còn nợ, đã qua hạn và không trong thời gian tạm dừng nhắc nợ — cùng quy tắc màn
     * "Thu phí quá hạn". Cột status chỉ cập nhật khi tính lại công nợ nên không dùng để đếm/lọc.
     */
    public function scopeOverdueNow(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('debt_amount', '>', 0)
            ->whereNotNull('due_date')->whereDate('due_date', '<', $today)
            ->where(fn ($q) => $q->whereNull('reminder_paused_until')->orWhereDate('reminder_paused_until', '<=', $today));
    }

    /** Đang trong thời gian bảo lưu (công nợ & số buổi được đóng băng). */
    public function isDeferredOn($on = null): bool
    {
        $day = ($on ? Carbon::parse($on) : now())->startOfDay();

        return $this->deferred_from !== null && $this->deferred_until !== null
            && $this->deferred_from->copy()->startOfDay()->lte($day)
            && $this->deferred_until->copy()->startOfDay()->gte($day);
    }

    /** Lọc các khoản học phí không bị tạm dừng nhắc nợ tại ngày $day (Y-m-d). */
    public function scopeRemindable($query, ?string $day = null)
    {
        $day ??= now()->toDateString();

        return $query->where(function ($q) use ($day) {
            $q->whereNull('reminder_paused_until')->orWhereDate('reminder_paused_until', '<=', $day);
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(TuitionReceipt::class, 'student_tuition_id');
    }

    public function contactLogs(): HasMany
    {
        return $this->hasMany(TuitionContactLog::class, 'student_tuition_id')->latest('contacted_at');
    }

    /** Số ngày quá hạn so với hôm nay (âm = còn bao nhiêu ngày tới hạn); null nếu chưa có hạn. */
    public function daysOverdue(): ?int
    {
        if (! $this->due_date) {
            return null;
        }

        return (int) $this->due_date->copy()->startOfDay()->diffInDays(now()->startOfDay(), false);
    }

    /**
     * Tính lại công nợ từ các phiếu đã duyệt (nguồn sự thật duy nhất):
     * - paid_amount = Σ phần học phí của phiếu approved (amount − surcharge_amount; phụ thu không cấn nợ,
     *   phiếu hoàn/chuyển nhượng âm tự trừ vào).
     * - debt_amount = max(0, final_amount − Σ discount_amount của phiếu approved − paid_amount).
     * Phiếu pending/draft/rejected/cancelled không được tính.
     */
    public function recalculateDebt(): void
    {
        $totals = $this->receipts()
            ->where('status', TuitionReceipt::STATUS_APPROVED)
            ->selectRaw('COALESCE(SUM(amount - COALESCE(surcharge_amount, 0)), 0) AS paid_total')
            ->selectRaw('COALESCE(SUM(COALESCE(discount_amount, 0)), 0) AS discount_total')
            ->toBase()
            ->first();

        $this->paid_amount = round((float) $totals->paid_total, 2);
        $this->debt_amount = max(0, round((float) $this->final_amount - (float) $totals->discount_total - (float) $this->paid_amount, 2));

        if ($this->debt_amount <= 0) {
            $this->status = 'paid';
        } elseif ($this->paid_amount > 0) {
            $this->status = 'partial';
        } else {
            // Đang khất nợ / bảo lưu (tạm dừng nhắc nợ) thì chưa tính là quá hạn.
            $this->status = ($this->due_date && $this->due_date < now() && ! $this->remindersPausedOn()) ? 'overdue' : 'unpaid';
        }

        $this->save();
    }

    /**
     * Tài khoản nhận tiền cho mã QR của khoản học phí: tài khoản gắn với hợp đồng → tài khoản của chi nhánh
     * (ưu tiên mặc định VietQR) → tài khoản mặc định toàn hệ thống. Chỉ dùng tài khoản đang hoạt động.
     */
    public function resolveBankAccount(?Collection $activeAccounts = null): ?BankAccount
    {
        if ($activeAccounts !== null) {
            return $this->resolveBankAccountFrom($activeAccounts);
        }

        if ($this->bank_account_id) {
            $own = BankAccount::query()->whereKey($this->bank_account_id)->where('is_active', true)->first();
            if ($own) {
                return $own;
            }
        }

        $branchId = $this->branch_id ?? $this->student?->branch_id;
        if ($branchId) {
            $branchAccount = BankAccount::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->orderByDesc('is_default_vietqr')
                ->orderBy('id')
                ->first();
            if ($branchAccount) {
                return $branchAccount;
            }
        }

        return BankAccount::defaultAccount();
    }

    /**
     * Cùng thứ tự ưu tiên như resolveBankAccount() nhưng chọn trong danh sách tài khoản đang hoạt động đã nạp sẵn
     * (BankAccount::activeForResolve()) — dùng khi hiển thị nhiều khoản học phí trên một trang.
     */
    private function resolveBankAccountFrom(Collection $activeAccounts): ?BankAccount
    {
        if ($this->bank_account_id && ($own = $activeAccounts->firstWhere('id', (int) $this->bank_account_id))) {
            return $own;
        }

        $branchId = $this->branch_id ?? $this->student?->branch_id;
        if ($branchId) {
            $branchAccount = $activeAccounts
                ->filter(fn (BankAccount $a) => (int) $a->branch_id === (int) $branchId)
                ->sortBy([fn ($a, $b) => (int) $b->is_default_vietqr <=> (int) $a->is_default_vietqr, fn ($a, $b) => $a->id <=> $b->id])
                ->first();
            if ($branchAccount) {
                return $branchAccount;
            }
        }

        return $activeAccounts
            ->sortBy([
                fn ($a, $b) => (int) $b->is_default_vietqr <=> (int) $a->is_default_vietqr,
                fn ($a, $b) => ($a->branch_id === null ? 0 : 1) <=> ($b->branch_id === null ? 0 : 1),
                fn ($a, $b) => $a->id <=> $b->id,
            ])
            ->first();
    }

    /**
     * Số buổi thật của khoản học phí: tổng buổi theo khóa (course.total_lessons) hoặc số buổi đã lên lịch của lớp,
     * số buổi đã học theo điểm danh (có mặt / đi muộn). Không đủ dữ liệu -> null (màn hình ẩn khối số buổi).
     *
     * @return array{total: int, attended: int, remaining: int, source: string}|null
     */
    public function sessionStats(): ?array
    {
        if ($this->preloadedSessionStats !== false) {
            return $this->preloadedSessionStats;
        }

        return $this->computeSessionStats(
            fn (int $classId) => ClassSession::query()->where('class_id', $classId)->where('status', '!=', 'cancelled')->count(),
            fn (?int $classId) => StudentAttendance::query()
                ->where('student_id', $this->student_id)
                ->when($classId, fn ($q) => $q->where('class_id', $classId))
                ->whereIn('status', ['present', 'late'])
                ->count(),
        );
    }

    /**
     * Tính sẵn sessionStats() cho cả danh sách bằng 2 truy vấn gộp (thay vì 1–2 truy vấn cho mỗi khoản học phí).
     * Nên nạp sẵn classModel.course và student.currentClass.course trước khi gọi.
     *
     * @param  iterable<StudentTuition|null>  $tuitions
     */
    public static function preloadSessionStats(iterable $tuitions): void
    {
        $tuitions = collect($tuitions)->filter()->values();
        if ($tuitions->isEmpty()) {
            return;
        }

        $scheduleClassIds = $tuitions
            ->map(fn (self $t) => $t->classModel ?? $t->student?->currentClass)
            ->filter(fn ($class) => $class && (int) ($class->course?->total_lessons ?? 0) <= 0)
            ->pluck('id')->unique()->values();
        $scheduled = $scheduleClassIds->isEmpty() ? collect() : ClassSession::query()
            ->whereIn('class_id', $scheduleClassIds)
            ->where('status', '!=', 'cancelled')
            ->groupBy('class_id')
            ->selectRaw('class_id, count(*) as aggregate')
            ->pluck('aggregate', 'class_id');

        $attendance = StudentAttendance::query()
            ->whereIn('student_id', $tuitions->pluck('student_id')->filter()->unique()->values())
            ->whereIn('status', ['present', 'late'])
            ->groupBy('student_id', 'class_id')
            ->selectRaw('student_id, class_id, count(*) as aggregate')
            ->get()
            ->groupBy('student_id');

        foreach ($tuitions as $tuition) {
            $rows = $attendance->get($tuition->student_id, collect());
            $tuition->preloadedSessionStats = $tuition->computeSessionStats(
                fn (int $classId) => (int) ($scheduled[$classId] ?? 0),
                fn (?int $classId) => (int) ($classId
                    ? $rows->where('class_id', $classId)->sum('aggregate')
                    : $rows->sum('aggregate')),
            );
        }
    }

    /** Kết quả sessionStats() đã tính sẵn (false = chưa tính). */
    protected array|null|false $preloadedSessionStats = false;

    /**
     * @param  \Closure(int): int  $scheduledCount  số buổi (không huỷ) của lớp
     * @param  \Closure(?int): int  $attendedCount  số buổi có mặt / đi muộn của học viên (trong lớp, hoặc mọi lớp khi null)
     */
    private function computeSessionStats(\Closure $scheduledCount, \Closure $attendedCount): ?array
    {
        $class = $this->classModel ?? $this->student?->currentClass;
        $total = (int) ($class?->course?->total_lessons ?? 0);
        $source = 'course';

        if ($total <= 0 && $class) {
            $total = $scheduledCount((int) $class->id);
            $source = 'schedule';
        }

        if ($total <= 0) {
            return null;
        }

        $attended = $attendedCount($class ? (int) $class->id : null);

        return [
            'total' => $total,
            'attended' => $attended,
            'remaining' => max(0, $total - $attended),
            'source' => $source,
        ];
    }

    /** Tên khoản thu hiển thị (mockup "Khoản thu"): học phí theo khóa / lớp, kèm số khoản giáo trình – đồ dùng. */
    public function getFeeLabelAttribute(): string
    {
        $class = $this->classModel;
        $label = 'Học phí '.($class?->course?->name ?? $class?->name ?? 'khóa học');
        $items = is_array($this->fee_items) ? count($this->fee_items) : 0;

        return $items > 0 ? $label." + {$items} khoản phụ" : $label;
    }

    /** Màu <x-ui.badge> theo trạng thái công nợ. */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'paid' => 'success',
            'partial' => 'warning',
            'overdue' => 'error',
            default => 'neutral',
        };
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'paid' => 'bg-tertiary/10 text-tertiary border-tertiary/30',
            'partial' => 'bg-warning/10 text-warning border-warning/30',
            'overdue' => 'bg-error/10 text-error border-error/30',
            default => 'bg-surface-container-low text-on-surface-variant border-surface-container-highest',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'paid' => 'Đã hoàn thành',
            'partial' => 'Đang nợ (Đã cọc)',
            'overdue' => 'Quá hạn',
            default => 'Chưa nộp',
        };
    }
}
