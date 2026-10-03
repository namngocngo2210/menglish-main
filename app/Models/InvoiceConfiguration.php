<?php

namespace App\Models;

use App\Exceptions\InvoiceRangeExhaustedException;
use App\Exceptions\PaperInvoiceNumberChangedException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dải số hóa đơn. Hai loại (kind):
 * - electronic (HĐĐT): cấp số khi DUYỆT phiếu thu. Mỗi chi nhánh có dải riêng (branch_id); dải branch_id = NULL là
 *   dải mặc định dùng chung khi chi nhánh chưa có dải riêng hoặc dải riêng đã hết số.
 * - paper (hóa đơn giấy thu tiền mặt): chỉ theo chi nhánh, không có dải dùng chung. Hệ thống cấp số theo thứ tự
 *   ngay khi LẬP phiếu tiền mặt; Học vụ ghi đúng số đó lên hóa đơn giấy và tải ảnh lên phiếu. Phiếu tiền mặt có
 *   số hóa đơn giấy thì không lấy thêm số HĐĐT khi duyệt.
 * current_number là SỐ KẾ TIẾP sẽ cấp. Số đã cấp không bao giờ được cấp lại
 * (UNIQUE tuition_receipts.invoice_number + không cho lùi current_number dưới số lớn nhất đã cấp); ghi sai số
 * trên hóa đơn giấy thì hủy hóa đơn số đó, phiếu mới nhận số kế tiếp.
 */
class InvoiceConfiguration extends Model
{
    use HasFactory;

    /** Số chữ số phần số thứ tự (C26MEN-0001001). */
    public const NUMBER_PAD = 7;

    /** Còn ít hơn ngưỡng này thì cảnh báo "Sắp hết số". */
    public const LOW_REMAINING_THRESHOLD = 50;

    public const KIND_ELECTRONIC = 'electronic';

    public const KIND_PAPER = 'paper';

    public const KIND_LABELS = [
        self::KIND_ELECTRONIC => 'Hóa đơn điện tử',
        self::KIND_PAPER => 'Hóa đơn giấy (tiền mặt)',
    ];

    protected $table = 'invoice_configurations';

    protected $fillable = [
        'branch_id',
        'kind',
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
        'kind' => self::KIND_ELECTRONIC,
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

            if ($candidates->isEmpty() && ! static::query()->where('kind', self::KIND_ELECTRONIC)->whereNull('branch_id')->exists()) {
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
     * Thứ tự dải HĐĐT được thử: dải đang hiệu lực của chi nhánh (số bắt đầu nhỏ trước), rồi dải mặc định.
     *
     * @return Collection<int, int>
     */
    private static function candidatesFor(?int $branchId)
    {
        $electronic = fn () => static::query()->where('kind', self::KIND_ELECTRONIC)->where('is_active', true)->orderBy('start_number')->orderBy('id');

        $branchRanges = $branchId ? $electronic()->where('branch_id', $branchId)->pluck('id') : collect();
        $globalRanges = $electronic()->whereNull('branch_id')->pluck('id');

        return $branchRanges->merge($globalRanges)->values();
    }

    /** Chi nhánh có dải hóa đơn giấy đang hiệu lực → phiếu tiền mặt được hệ thống cấp số hóa đơn giấy. */
    public static function branchUsesPaperRange(?int $branchId): bool
    {
        return $branchId !== null && static::paperRangesQuery($branchId)->exists();
    }

    /**
     * Số hóa đơn giấy kế tiếp của chi nhánh (chỉ xem trước, không tiêu thụ). null = chi nhánh không có dải giấy
     * đang hiệu lực hoặc các dải đã hết số.
     */
    public static function peekNextPaperNumber(?int $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        foreach (static::paperRangesQuery($branchId)->get() as $range) {
            $number = max((int) $range->current_number, (int) ($range->start_number ?? 1));
            while ($range->end_number === null || $number <= (int) $range->end_number) {
                $formatted = static::format((string) $range->series_code, $number);
                if (! TuitionReceipt::query()->where('invoice_number', $formatted)->exists()) {
                    return $formatted;
                }
                $number++;
            }
        }

        return null;
    }

    /**
     * Cấp số hóa đơn giấy kế tiếp của chi nhánh cho phiếu tiền mặt. $expected: số người lập đã thấy (và ghi lên
     * hóa đơn giấy) trên form — nếu số kế tiếp đã đổi (người khác vừa lập phiếu) thì không cấp, báo số mới.
     *
     * @throws InvoiceRangeExhaustedException khi các dải giấy của chi nhánh đều hết số / ngừng dùng
     * @throws PaperInvoiceNumberChangedException khi số kế tiếp khác $expected
     */
    public static function consumeNextPaperNumber(int $branchId, ?string $expected = null): string
    {
        return DB::transaction(function () use ($branchId, $expected): string {
            foreach (static::paperRangesQuery($branchId)->pluck('id') as $rangeId) {
                $range = static::query()->lockForUpdate()->find($rangeId);
                if (! $range || ! $range->is_active) {
                    continue;
                }

                while (! $range->isExhausted()) {
                    $number = max((int) $range->current_number, (int) ($range->start_number ?? 1));
                    $formatted = static::format((string) $range->series_code, $number);
                    if (TuitionReceipt::query()->where('invoice_number', $formatted)->exists()) {
                        $range->current_number = $number + 1;
                        $range->save();

                        continue;
                    }
                    if ($expected !== null && $expected !== '' && $expected !== $formatted) {
                        throw new PaperInvoiceNumberChangedException($expected, $formatted);
                    }
                    $range->current_number = $number + 1;
                    $range->save();

                    return $formatted;
                }
            }

            throw new InvoiceRangeExhaustedException(
                'Dải số hóa đơn giấy của chi nhánh đã hết số hoặc đang ngừng dùng. Nhờ Kế toán thêm dải mới ở Cấu hình dải số hóa đơn.'
            );
        });
    }

    private static function paperRangesQuery(int $branchId)
    {
        return static::query()
            ->where('kind', self::KIND_PAPER)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('end_number')->orWhereColumn('current_number', '<=', 'end_number'))
            ->orderBy('start_number')
            ->orderBy('id');
    }

    public function getKindLabelAttribute(): string
    {
        return self::KIND_LABELS[$this->kind] ?? self::KIND_LABELS[self::KIND_ELECTRONIC];
    }
}
