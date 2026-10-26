<?php

namespace App\Models;

use App\Services\Tuition\SessionLedger;
use App\Support\TransferMemo;
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
        'session_count',
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
        'session_count' => 'integer',
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

    protected static function booted(): void
    {
        // Mọi luồng tạo khoản học phí (chốt khách, import, ...) đều có sẵn nội dung CK để SePay đối soát.
        static::saving(function (StudentTuition $tuition) {
            if (blank($tuition->transfer_memo) && $tuition->student_id) {
                $tuition->transfer_memo = $tuition->currentTransferMemo() ?: null;
            }
            // Số buổi của khoản học phí (sổ buổi): mặc định theo khóa của lớp (lớp đang học), chưa có thì 24.
            if (! $tuition->session_count) {
                $tuition->session_count = SessionLedger::courseSessions($tuition->classModel ?? $tuition->student?->currentClass);
            }
        });
    }

    /** Nội dung CK theo mẫu chung: tên + mã học sinh + lớp của khoản học phí (hoặc lớp đang học). */
    public function currentTransferMemo(): string
    {
        $student = $this->student;
        if (! $student) {
            return (string) $this->transfer_memo;
        }

        return TransferMemo::build($student->code, $student->name, ($this->classModel ?? $student->currentClass)?->name);
    }

    /** Cập nhật nội dung CK đã lưu theo mẫu hiện hành (đổi lớp / dữ liệu cũ) và trả về nội dung. */
    public function syncTransferMemo(): string
    {
        $memo = $this->currentTransferMemo();
        if ($memo !== '' && $this->transfer_memo !== $memo) {
            $this->forceFill(['transfer_memo' => $memo])->saveQuietly();
        }

        return $memo;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /** Ưu đãi áp dụng khi chốt khách (giảm trừ nằm ở discount_amount của hợp đồng). */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
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

    /** Học phí của khoản sau ưu đãi khi chốt (không gồm "Thu khác"). */
    public function tuitionNet(): float
    {
        return max(0.0, round((float) $this->total_amount - (float) $this->discount_amount, 2));
    }

    /** Đơn giá một buổi của khoản học phí = học phí sau ưu đãi / số buổi của khoản. */
    public function sessionUnitPrice(): float
    {
        $sessions = (int) $this->session_count;

        return $sessions > 0 ? round($this->tuitionNet() / $sessions, 2) : 0.0;
    }

    /** Tiền đã gạch nợ của khoản (đã nộp + giảm trừ trên phiếu đã duyệt) = giá trị hợp đồng − công nợ còn lại. */
    public function coveredAmount(): float
    {
        return max(0.0, round((float) $this->final_amount - (float) $this->debt_amount, 2));
    }

    /** "Thu khác" lúc chốt (sách, đồng phục...) còn nợ: tiền gạch nợ được tính vào thu khác trước. */
    public function feeRemaining(): float
    {
        $fees = max(0.0, (float) $this->other_fees);

        return round($fees - min($fees, $this->coveredAmount()), 2);
    }

    /** Phần học phí (theo buổi) đã gạch nợ. */
    public function tuitionCovered(): float
    {
        $fees = max(0.0, (float) $this->other_fees);

        return min($this->tuitionNet(), max(0.0, round($this->coveredAmount() - min($fees, $this->coveredAmount()), 2)));
    }

    /** Phần học phí (theo buổi) còn nợ. */
    public function tuitionRemaining(): float
    {
        return max(0.0, round($this->tuitionNet() - $this->tuitionCovered(), 2));
    }

    /** Số buổi đã đóng của khoản: phần học phí đã gạch nợ / đơn giá buổi (làm tròn xuống buổi nguyên). */
    public function paidSessions(): int
    {
        $sessions = (int) $this->session_count;
        $net = $this->tuitionNet();
        if ($sessions <= 0) {
            return 0;
        }
        if ($net <= 0) {
            return $sessions;
        }

        return min($sessions, (int) floor(round($sessions * $this->tuitionCovered() / $net, 2)));
    }

    /** Số buổi của khoản chưa đóng. */
    public function remainingSessions(): int
    {
        return max(0, (int) $this->session_count - $this->paidSessions());
    }

    /**
     * Tài khoản nhận tiền cho mã QR của khoản học phí: tài khoản gắn với hợp đồng (nếu còn dùng được cho cơ sở)
     * → tài khoản gắn cơ sở → tài khoản dùng chung (xem BankAccount::resolveFor()). Chỉ dùng tài khoản đang hoạt động.
     * Truyền sẵn BankAccount::activeForResolve() khi hiển thị nhiều khoản học phí trên một trang.
     */
    public function resolveBankAccount(?Collection $activeAccounts = null): ?BankAccount
    {
        $branchId = $this->branch_id ?? $this->student?->branch_id;

        return BankAccount::resolveFor($branchId ? (int) $branchId : null, $this->bank_account_id ? (int) $this->bank_account_id : null, $activeAccounts);
    }

    /** Số buổi của khoản (sổ buổi): số buổi đã chốt của khoản, chưa có thì theo khóa của lớp (mặc định 24). */
    public function sessionSize(): int
    {
        return (int) $this->session_count > 0
            ? (int) $this->session_count
            : SessionLedger::courseSessions($this->classModel ?? $this->student?->currentClass);
    }

    /**
     * Số buổi của khoản học phí theo sổ buổi (SessionLedger): tổng = số buổi của khoản, đã học = buổi lớp đã diễn ra
     * trong thời gian học viên thuộc lớp (vắng vẫn trừ, bảo lưu không trừ; chia theo thứ tự các khoản của học viên),
     * còn lại = tổng − đã học. Cùng con số với hồ sơ học viên / lập phiếu thu, dùng cho hoàn phí, bảo lưu, công nợ.
     *
     * @return array{total: int, attended: int, remaining: int, source: string}|null
     */
    public function sessionStats(): ?array
    {
        if ($this->preloadedSessionStats !== false) {
            return $this->preloadedSessionStats;
        }
        if (! $this->exists || ! $this->student_id) {
            return null;
        }

        return $this->buildSessionStats(app(SessionLedger::class)->tuitionUsage([(int) $this->student_id]));
    }

    /**
     * Tính sẵn sessionStats() cho cả danh sách bằng các truy vấn gộp theo học viên (thay vì từng khoản).
     *
     * @param  iterable<StudentTuition|null>  $tuitions
     */
    public static function preloadSessionStats(iterable $tuitions): void
    {
        $tuitions = collect($tuitions)->filter()->values();
        if ($tuitions->isEmpty()) {
            return;
        }

        $usage = app(SessionLedger::class)->tuitionUsage($tuitions->pluck('student_id')->filter()->unique()->values());
        foreach ($tuitions as $tuition) {
            $tuition->preloadedSessionStats = $tuition->exists ? $tuition->buildSessionStats($usage) : null;
        }
    }

    /** Kết quả sessionStats() đã tính sẵn (false = chưa tính). */
    protected array|null|false $preloadedSessionStats = false;

    /** @param  array<int, int>  $usage  id khoản => số buổi đã trừ (SessionLedger::tuitionUsage) */
    private function buildSessionStats(array $usage): ?array
    {
        $total = $this->sessionSize();
        if ($total <= 0) {
            return null;
        }
        $attended = min($total, (int) ($usage[(int) $this->id] ?? 0));

        return [
            'total' => $total,
            'attended' => $attended,
            'remaining' => max(0, $total - $attended),
            'source' => 'ledger',
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

    /** Màu <UiBadge> theo trạng thái công nợ. */
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
