<?php

namespace App\Models;

use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TuitionReceipt extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** Phiếu đã duyệt nhưng bị hủy hóa đơn: giữ nguyên số HĐ, không còn tính vào công nợ/doanh thu. */
    public const STATUS_CANCELLED = 'cancelled';

    /** Trạng thái người lập còn được sửa và gửi duyệt lại. */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_REJECTED];

    protected $table = 'tuition_receipts';

    protected $fillable = [
        'receipt_number',
        'invoice_number',
        'student_tuition_id',
        'student_id',
        'amount',
        'tuition_amount',
        'surcharge_amount',
        'surcharge_reason',
        'discount_amount',
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
        'status',
        'notes',
        'rejection_reason',
        'split_details',
        'collected_items',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tuition_amount' => 'decimal:2',
        'surcharge_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'is_vat_invoice' => 'boolean',
        'payment_date' => 'date',
        'approved_at' => 'datetime',
        'split_details' => 'array',
        'collected_items' => 'array',
    ];

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

    /**
     * Phần tiền của phiếu cấn vào học phí (không gồm phụ thu).
     * Phiếu hoàn/chuyển nhượng có amount âm và không có phụ thu nên phần này cũng âm.
     */
    public function tuitionPortion(): float
    {
        return (float) $this->amount - (float) $this->surcharge_amount;
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

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'bg-slate-100 text-slate-700 border-slate-200',
            self::STATUS_APPROVED => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::STATUS_REJECTED => 'bg-rose-50 text-rose-700 border-rose-200',
            self::STATUS_CANCELLED => 'bg-gray-100 text-gray-500 border-gray-300 line-through',
            default => 'bg-amber-50 text-amber-700 border-amber-200',
        };
    }

    public static function generateReceiptNumber(): string
    {
        return 'PT-'.date('Y').'-'.Str::ulid();
    }

    protected static function booted(): void
    {
        // Mốc duyệt phiếu = tháng tính hoa hồng tuyển sinh (A6). Ghi tự động ở mọi
        // luồng duyệt (duyệt tay, SePay, phiếu hoàn/chuyển nhượng) mà không phải sửa từng controller.
        static::saving(function (TuitionReceipt $receipt) {
            if ($receipt->status === self::STATUS_APPROVED && $receipt->approved_at === null) {
                $receipt->approved_at = now();
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
