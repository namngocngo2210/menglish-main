<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'bank_accounts';

    /** Loại tài khoản (mockup): Công ty — chủ sở hữu chính; Khác — cá nhân / đại diện. */
    public const TYPES = [
        'company' => 'Công ty',
        'other' => 'Khác',
    ];

    protected $fillable = [
        'account_type',
        'bank_code',
        'bank_name',
        'account_number',
        'account_holder',
        'branch_location',
        'branch_id',
        'is_default_vietqr',
        'is_active',
    ];

    protected $casts = [
        'is_default_vietqr' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->account_type] ?? self::TYPES['company'];
    }

    /** Ảnh VietQR xem trước (không kèm số tiền) cho màn cấu hình tài khoản. */
    public function getVietqrPreviewUrlAttribute(): ?string
    {
        if (! $this->bank_code || ! $this->account_number) {
            return null;
        }

        return 'https://img.vietqr.io/image/'.rawurlencode($this->bank_code).'-'.rawurlencode($this->account_number)
            .'-compact2.png?accountName='.rawurlencode((string) $this->account_holder);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Tài khoản mặc định toàn hệ thống (đang hoạt động, ưu tiên cờ mặc định VietQR). */
    public static function defaultAccount(): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->orderByDesc('is_default_vietqr')
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->first();
    }

    /** Chuẩn hoá số tài khoản để so khớp (chỉ giữ chữ số và chữ cái, bỏ khoảng trắng/gạch). */
    public static function normalizeNumber(?string $number): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $number));
    }

    /**
     * Số tài khoản (hoặc tài khoản ảo) nhận tiền có thuộc một tài khoản ngân hàng đã cấu hình không.
     */
    public static function isConfiguredNumber(?string ...$numbers): bool
    {
        $wanted = collect($numbers)->map(fn ($n) => static::normalizeNumber($n))->filter()->values();
        if ($wanted->isEmpty()) {
            return false;
        }

        return static::query()->pluck('account_number')
            ->map(fn ($n) => static::normalizeNumber($n))
            ->intersect($wanted)
            ->isNotEmpty();
    }
}
