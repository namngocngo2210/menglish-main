<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use App\Services\Merchandise\StockService;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TuitionReceipt extends Model
{
    use AuditsChanges, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** Phiếu đã duyệt nhưng bị hủy hóa đơn: giữ nguyên số HĐ, không còn tính vào công nợ/doanh thu. */
    public const STATUS_CANCELLED = 'cancelled';

    /** Trạng thái người lập còn được sửa và gửi duyệt lại. */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_REJECTED];

    /** Hình thức thu qua ngân hàng (có thể trùng với giao dịch SePay tự động). */
    public const TRANSFER_METHODS = ['transfer', 'vietqr'];

    /** Nhãn hình thức thu hiển thị trên các màn học phí / file xuất. */
    public const METHOD_LABELS = [
        'transfer' => 'Chuyển khoản',
        'vietqr' => 'Chuyển khoản VietQR',
        'cash' => 'Tiền mặt',
        'pos' => 'Quẹt thẻ POS',
    ];

    /**
     * Hình thức được chọn khi lập phiếu thu mới. POS / kết hợp đã bỏ (trung tâm không dùng) —
     * nhãn 'pos' vẫn giữ ở METHOD_LABELS để phiếu cũ hiển thị đúng.
     */
    public const INPUT_METHODS = ['transfer', 'vietqr', 'cash'];

    /** Hình thức thu bắt buộc minh chứng khi gửi duyệt (tiền mặt được miễn). */
    public const PROOF_REQUIRED_METHODS = ['transfer', 'vietqr', 'pos'];

    /** Trạng thái giữ chỗ mã giao dịch ngân hàng (transfer_reference) để không ghi nhận 2 lần. */
    public const REFERENCE_HOLDING_STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED];

    /** Hạn nộp tiền thu trong ngày về tài khoản công ty (giờ hệ thống, cùng ngày thu) — SLA tuition.cash_deposit, mặc định 19:00. */
    public static function depositCutoff(): string
    {
        return \App\Services\Sla\Sla::time('tuition.cash_deposit');
    }

    protected $table = 'tuition_receipts';

    protected $fillable = [
        'receipt_number',
        'invoice_number',
        'student_tuition_id',
        'student_id',
        'amount',
        'tuition_amount',
        'session_count',
        'session_unit_price',
        'material_fee',
        'exam_fee',
        'other_fee',
        'other_fee_reason',
        'surcharge_amount',
        'surcharge_reason',
        'discount_amount',
        'promotion_id',
        'discount_reason',
        'payment_method',
        'transaction_code',
        'payer_name',
        'payer_phone',
        'is_vat_invoice',
        'paper_invoice_number',
        'proof_image',
        'payment_date',
        'creator_id',
        'approver_id',
        'approved_at',
        'deposited_at',
        'deposited_by',
        'status',
        'notes',
        'rejection_reason',
        'split_details',
        'collected_items',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tuition_amount' => 'decimal:2',
        'session_count' => 'integer',
        'session_unit_price' => 'decimal:2',
        'material_fee' => 'decimal:2',
        'exam_fee' => 'decimal:2',
        'other_fee' => 'decimal:2',
        'surcharge_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'is_vat_invoice' => 'boolean',
        'payment_date' => 'date',
        'approved_at' => 'datetime',
        'deposited_at' => 'datetime',
        'split_details' => 'array',
        'collected_items' => 'array',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function tuition(): BelongsTo
    {
        return $this->belongsTo(StudentTuition::class, 'student_tuition_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function depositor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deposited_by');
    }

    /**
     * Phiếu thu tiền mặt còn hiệu lực (chờ duyệt / đã duyệt, số tiền dương) phải nộp về TK công ty trước 19:00 cùng ngày.
     * Chuyển khoản / VietQR / POS đã vào thẳng tài khoản nên không áp dụng.
     */
    public function requiresDeposit(): bool
    {
        return $this->payment_method === 'cash'
            && in_array($this->status, self::REFERENCE_HOLDING_STATUSES, true)
            && (float) $this->amount > 0;
    }

    /** Thời điểm hết hạn nộp tiền: 19:00 của ngày thu. */
    public function depositDeadline(): ?\Carbon\CarbonInterface
    {
        return $this->payment_date?->copy()->setTimeFromTimeString(self::depositCutoff());
    }

    /** Đã nộp nhưng sau hạn, hoặc chưa nộp mà đã qua hạn. */
    public function isDepositLate(?\Carbon\CarbonInterface $now = null): bool
    {
        $deadline = $this->depositDeadline();
        if (! $deadline) {
            return false;
        }

        return ($this->deposited_at ?? $now ?? now())->gt($deadline);
    }

    /** Trạng thái nộp tiền để hiển thị: null (không áp dụng) | pending | on_time | late. */
    public function depositState(): ?string
    {
        if (! $this->requiresDeposit()) {
            return null;
        }
        if ($this->deposited_at === null) {
            return 'pending';
        }

        return $this->isDepositLate() ? 'late' : 'on_time';
    }

    /**
     * Phần tiền của phiếu cấn vào học phí (không gồm phụ thu).
     * Phiếu hoàn/chuyển nhượng có amount âm và không có phụ thu nên phần này cũng âm.
     */
    public function tuitionPortion(): float
    {
        return (float) $this->amount - (float) $this->surcharge_amount;
    }

    /** Phiếu lập theo sổ buổi (có số buổi thu). */
    public function isSessionBased(): bool
    {
        return $this->session_count !== null;
    }

    /**
     * Bảng kê phiếu thu theo buổi: tiền buổi, học liệu, thi, khác, tổng trước giảm, giảm, tổng phải thu, phụ thu.
     * Phiếu cũ (thu theo số tiền) → null.
     *
     * @return array<string, float|int|string|null>|null
     */
    public function sessionBreakdown(): ?array
    {
        if (! $this->isSessionBased()) {
            return null;
        }

        $material = (float) $this->material_fee;
        $exam = (float) $this->exam_fee;
        $other = (float) $this->other_fee;
        $discount = (float) $this->discount_amount;
        // Cấn nợ = tiền buổi + học liệu còn nợ lúc chốt − giảm; phần ngoài cấn nợ = hàng hóa mới + thi + khác + phụ thu.
        $itemsTotal = (float) array_sum(array_column(array_filter((array) $this->collected_items, 'is_array'), 'amount'));
        $extra = max(0.0, round((float) $this->surcharge_amount - $itemsTotal - $exam - $other, 2));
        $feeDue = max(0.0, round($material - $itemsTotal, 2));
        $sessionValue = round($this->tuitionPortion() + $discount - $feeDue, 2);
        $subtotal = $sessionValue + $material + $exam + $other;

        return [
            'session_count' => (int) $this->session_count,
            'session_unit_price' => (float) $this->session_unit_price,
            'session_value' => $sessionValue,
            'material_fee' => $material,
            'exam_fee' => $exam,
            'other_fee' => $other,
            'other_fee_reason' => $this->other_fee_reason,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total_due' => $subtotal - $discount,
            'extra' => $extra,
            'amount' => (float) $this->amount,
        ];
    }

    /** Chi nhánh ghi nhận phiếu: theo hợp đồng học phí, fallback chi nhánh học viên / lớp đang học. */
    public function resolveBranchId(): ?int
    {
        $tuition = $this->tuition;
        $student = $tuition?->student ?? $this->student;
        $branchId = $tuition?->branch_id ?? $student?->branch_id ?? $student?->currentClass?->branch_id;

        return $branchId ? (int) $branchId : null;
    }

    /**
     * Phiếu tiền mặt đã được cấp số hóa đơn giấy từ dải của chi nhánh ngay khi lập (chưa duyệt): số này là số
     * ghi trên hóa đơn giấy, giữ nguyên khi sửa / gửi duyệt lại và khi duyệt.
     */
    public function hasIssuedPaperInvoice(): bool
    {
        return $this->invoice_number !== null
            && $this->paper_invoice_number !== null
            && $this->invoice_number === $this->paper_invoice_number;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Bản nháp',
            self::STATUS_APPROVED => 'Đã duyệt thu',
            self::STATUS_REJECTED => 'Bị từ chối',
            self::STATUS_CANCELLED => 'Đã hủy hóa đơn',
            default => 'Chờ duyệt',
        };
    }

    /** Màu <UiBadge> theo trạng thái phiếu. */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'neutral',
            self::STATUS_APPROVED => 'success',
            self::STATUS_REJECTED => 'error',
            self::STATUS_CANCELLED => 'neutral',
            default => 'warning',
        };
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'bg-surface-container text-on-surface-variant border-surface-container-highest',
            self::STATUS_APPROVED => 'bg-tertiary/10 text-tertiary border-tertiary/30',
            self::STATUS_REJECTED => 'bg-error/10 text-error border-error/30',
            self::STATUS_CANCELLED => 'bg-surface-container text-on-surface-variant border-outline-variant line-through',
            default => 'bg-warning/10 text-warning border-warning/30',
        };
    }

    public static function generateReceiptNumber(): string
    {
        return 'PT-'.date('Y').'-'.Str::ulid();
    }

    /** Chuẩn hoá mã giao dịch ngân hàng để so trùng (bỏ khoảng trắng, không phân biệt hoa thường). */
    public static function normalizeReference(?string $code): ?string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', (string) $code));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Khoá duy nhất cho mã giao dịch chuyển khoản: chỉ giữ khi phiếu chuyển khoản đang chờ duyệt / đã duyệt.
     * Cột transfer_reference có UNIQUE nên cùng một mã giao dịch không thể được ghi nhận 2 lần.
     */
    public function computeTransferReference(): ?string
    {
        if (! in_array($this->payment_method, self::TRANSFER_METHODS, true)
            || ! in_array($this->status, self::REFERENCE_HOLDING_STATUSES, true)) {
            return null;
        }

        return self::normalizeReference($this->transaction_code);
    }

    /**
     * Phiếu khác (chờ duyệt / đã duyệt) đang dùng cùng mã giao dịch chuyển khoản.
     */
    public static function findByTransferReference(?string $code, ?int $ignoreId = null): ?self
    {
        $normalized = self::normalizeReference($code);
        if ($normalized === null) {
            return null;
        }

        return static::query()
            ->where('transfer_reference', $normalized)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();
    }

    protected static function booted(): void
    {
        // Mốc duyệt phiếu = tháng tính hoa hồng tuyển sinh (A6). Ghi tự động ở mọi
        // luồng duyệt (duyệt tay, SePay, phiếu hoàn/chuyển nhượng) mà không phải sửa từng controller.
        static::saving(function (TuitionReceipt $receipt) {
            if ($receipt->status === self::STATUS_APPROVED && $receipt->approved_at === null) {
                $receipt->approved_at = now();
            }

            $receipt->transfer_reference = $receipt->computeTransferReference();
        });

        // Xuất kho sách / hàng hóa khi phiếu được duyệt, hoàn kho khi hủy hóa đơn (mọi luồng: duyệt tay, SePay...).
        static::saved(function (TuitionReceipt $receipt) {
            if ($receipt->wasRecentlyCreated || $receipt->wasChanged('status')) {
                app(StockService::class)->syncReceipt($receipt);
            }
        });

        static::created(function (TuitionReceipt $receipt) {
            // Không tự động gửi email khi đang chạy Seeder / Artisan Console (tránh spam mail)
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                return;
            }

            if ($receipt->amount > 0 && $receipt->status === 'approved') {
                try {
                    app(NotificationService::class)->notifyTransactionReceipt($receipt);
                } catch (\Throwable $e) {
                    Log::warning('Lỗi gửi email thông báo giao dịch mới: '.$e->getMessage());
                }
            }
        });
    }
}
