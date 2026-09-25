<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
    public function resolveBankAccount(): ?BankAccount
    {
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
     * Số buổi thật của khoản học phí: tổng buổi theo khóa (course.total_lessons) hoặc số buổi đã lên lịch của lớp,
     * số buổi đã học theo điểm danh (có mặt / đi muộn). Không đủ dữ liệu -> null (màn hình ẩn khối số buổi).
     *
     * @return array{total: int, attended: int, remaining: int, source: string}|null
     */
    public function sessionStats(): ?array
    {
        $class = $this->classModel ?? $this->student?->currentClass;
        $total = (int) ($class?->course?->total_lessons ?? 0);
        $source = 'course';

        if ($total <= 0 && $class) {
            $total = ClassSession::query()->where('class_id', $class->id)->where('status', '!=', 'cancelled')->count();
            $source = 'schedule';
        }

        if ($total <= 0) {
            return null;
        }

        $attended = StudentAttendance::query()
            ->where('student_id', $this->student_id)
            ->when($class, fn ($q) => $q->where('class_id', $class->id))
            ->whereIn('status', ['present', 'late'])
            ->count();

        return [
            'total' => $total,
            'attended' => $attended,
            'remaining' => max(0, $total - $attended),
            'source' => $source,
        ];
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'paid' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'partial' => 'bg-amber-50 text-amber-700 border-amber-200',
            'overdue' => 'bg-rose-50 text-rose-700 border-rose-200',
            default => 'bg-gray-50 text-gray-700 border-gray-200',
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
