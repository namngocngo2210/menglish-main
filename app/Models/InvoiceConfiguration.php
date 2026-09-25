<?php

namespace App\Models;

use App\Exceptions\InvoiceRangeExhaustedException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Dải số hóa đơn điện tử. Mỗi chi nhánh có dải riêng (branch_id); dải branch_id = NULL là dải
 * mặc định dùng chung khi chi nhánh chưa có dải riêng hoặc dải riêng đã hết số.
 * current_number là SỐ KẾ TIẾP sẽ cấp. Số đã cấp không bao giờ được cấp lại
 * (UNIQUE tuition_receipts.invoice_number + không cho lùi current_number dưới số lớn nhất đã cấp).
 */
class InvoiceConfiguration extends Model
{
    use HasFactory;

    /** Số chữ số phần số thứ tự (C26MEN-0001001). */
    public const NUMBER_PAD = 7;

    /** Còn ít hơn ngưỡng này thì cảnh báo "Sắp hết số". */
    public const LOW_REMAINING_THRESHOLD = 50;

    protected $table = 'invoice_configurations';

    protected $fillable = [
        'branch_id',
        'template_code',
        'series_code',
        'start_number',
        'end_number',
        'current_number',
        'provider',
        'auto_issue',
        'is_active',
    ];

    protected $casts = [
        'start_number' => 'integer',
        'end_number' => 'integer',
        'current_number' => 'integer',
        'auto_issue' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public static function format(string $seriesCode, int $number): string
    {
        return $seriesCode.'-'.str_pad((string) $number, self::NUMBER_PAD, '0', STR_PAD_LEFT);
    }

    /** Số còn lại trong dải (null = không giới hạn). */
    public function remaining(): ?int
    {
        if ($this->end_number === null) {
            return null;
        }

        return max(0, (int) $this->end_number - (int) $this->current_number + 1);
    }

    public function isExhausted(): bool
    {
        return $this->end_number !== null && (int) $this->current_number > (int) $this->end_number;
    }

    /**
     * Số lớn nhất đã cấp theo ký hiệu (series) của dải này, đọc từ phiếu thu thật (kể cả phiếu đã hủy HĐ).
     */
    public function maxIssuedNumber(): ?int
    {
        return static::maxIssuedNumberFor((string) $this->series_code, (int) ($this->start_number ?? 1), $this->end_number);
    }

    /**
     * Số lớn nhất đã cấp của ký hiệu trong khoảng [start, end] (các dải cùng ký hiệu không chồng lấn,
     * nên khoảng của dải chỉ chứa số do chính dải đó cấp).
     */
    public static function maxIssuedNumberFor(string $seriesCode, int $start = 1, ?int $end = null): ?int
    {
        // Phần số luôn đệm đủ NUMBER_PAD chữ số -> so sánh chuỗi đúng thứ tự số; bỏ qua số "-DUPxx".
        $latest = static::issuedInvoicesQuery($seriesCode, $start, $end)
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        return $latest ? (int) substr($latest, strlen($seriesCode) + 1) : null;
    }

    /** Phiếu thu có số HĐ thuộc khoảng [start, end] của ký hiệu. */
    public static function issuedInvoicesQuery(string $seriesCode, int $start = 1, ?int $end = null)
    {
        return TuitionReceipt::query()
            ->where('invoice_number', 'like', $seriesCode.'-'.str_repeat('_', self::NUMBER_PAD))
            ->whereBetween('invoice_number', [
                static::format($seriesCode, max(0, $start)),
                static::format($seriesCode, $end ?? (int) str_repeat('9', self::NUMBER_PAD)),
            ]);
    }

    /**
     * Dải khác cùng ký hiệu mà khoảng số chồng lấn với [start, end] (end null = vô hạn).
     */
    public static function overlapping(string $seriesCode, int $start, ?int $end, ?int $ignoreId = null): ?self
    {
        return static::query()
            ->where('series_code', $seriesCode)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->first(function (self $other) use ($start, $end) {
                $otherStart = (int) ($other->start_number ?? 1);
                $otherEnd = $other->end_number;

                return ($end === null || $otherStart <= $end) && ($otherEnd === null || $start <= $otherEnd);
            });
    }

    /**
     * Tiêu thụ 1 số hóa đơn trong dải của chi nhánh (fallback dải mặc định), tăng current_number
     * và trả về mã hóa đơn. Bỏ qua số đã tồn tại để không bao giờ cấp lại số đã dùng.
     *
     * @throws InvoiceRangeExhaustedException khi cả dải chi nhánh lẫn dải mặc định đều hết số
     */
    public static function consumeNextInvoiceNumber(?int $branchId = null): string
    {
        return DB::transaction(function () use ($branchId): string {
            $candidates = static::candidatesFor($branchId);

            if ($candidates->isEmpty() && ! static::query()->whereNull('branch_id')->exists()) {
                static::query()->create([
                    'template_code' => '1/001',
                    'series_code' => 'C26MEN',
                    'start_number' => 1,
                    'current_number' => 1001,
                    'provider' => 'vnpt',
                    'auto_issue' => true,
                    'is_active' => true,
                ]);
                $candidates = static::candidatesFor($branchId);
            }

            foreach ($candidates as $candidateId) {
                $config = static::query()->lockForUpdate()->find($candidateId);
                if (! $config || ! $config->is_active) {
                    continue;
                }

                while (! $config->isExhausted()) {
                    $number = max((int) $config->current_number, (int) ($config->start_number ?? 1));
                    $formatted = static::format((string) $config->series_code, $number);
                    $config->current_number = $number + 1;
                    $config->save();

                    if (! TuitionReceipt::query()->where('invoice_number', $formatted)->exists()) {
                        return $formatted;
                    }
                }
            }

            throw new InvoiceRangeExhaustedException(
                'Dải số hóa đơn của chi nhánh và dải mặc định đã hết số hoặc đang ngừng dùng. Vui lòng cấu hình dải số mới.'
            );
        });
    }

    /**
     * Thứ tự dải được thử: dải đang hiệu lực của chi nhánh (số bắt đầu nhỏ trước), rồi dải mặc định.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private static function candidatesFor(?int $branchId)
    {
        $branchRanges = $branchId
            ? static::query()->where('branch_id', $branchId)->where('is_active', true)->orderBy('start_number')->orderBy('id')->pluck('id')
            : collect();

        $globalRanges = static::query()->whereNull('branch_id')->where('is_active', true)->orderBy('start_number')->orderBy('id')->pluck('id');

        return $branchRanges->merge($globalRanges)->values();
    }
}
