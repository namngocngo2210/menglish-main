<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Mốc nhắc nợ: gửi khi hôm nay = hạn đóng + offset_days (âm = trước hạn, 0 = đúng hạn, dương = quá hạn).
 */
class DebtReminderRule extends Model
{
    use HasFactory;

    /** Kênh gửi hỗ trợ: thông báo trong Cổng PH/HS (in-app) và email vận hành nội bộ. */
    public const CHANNELS = [
        'portal' => 'Chuông thông báo in-app (Cổng PH/HS)',
        'email' => 'Email cảnh báo nội bộ (Kế toán / Admin)',
    ];

    public const DEFAULT_CHANNELS = ['portal', 'email'];

    /**
     * Biến dùng được trong mẫu tin (dạng thường hoặc IN HOA). Key = biến chuẩn, value = mô tả.
     */
    public const VARIABLES = [
        '{ten_hoc_vien}' => 'Họ tên học viên',
        '{ma_hoc_vien}' => 'Mã học viên',
        '{so_dien_thoai}' => 'SĐT học viên',
        '{lop_hoc}' => 'Tên lớp',
        '{so_tien}' => 'Số tiền còn nợ',
        '{han_dong}' => 'Hạn đóng (dd/mm/yyyy)',
        '{moc_nhac}' => 'Tên mốc nhắc',
    ];

    /** Biến đồng nghĩa (đã từng hướng dẫn trên màn cấu hình cũ) -> biến chuẩn. */
    public const VARIABLE_ALIASES = [
        '{ten_lop}' => '{lop_hoc}',
        '{han_nop}' => '{han_dong}',
        '{han_chot}' => '{han_dong}',
    ];

    protected $table = 'debt_reminder_rules';

    protected $fillable = [
        'milestone_key',
        'offset_days',
        'title',
        'template_content',
        'channels',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'offset_days' => 'integer',
        'channels' => 'array',
    ];

    /** Kênh đang bật (chưa cấu hình = mặc định cả hai kênh). */
    public function activeChannels(): array
    {
        return $this->channels === null ? self::DEFAULT_CHANNELS : array_values(array_intersect(array_keys(self::CHANNELS), $this->channels));
    }

    /** Suy ra số ngày từ mã mốc dạng T-3 / T0 / T+3 (dữ liệu cũ). */
    public static function offsetFromKey(?string $key): ?int
    {
        $key = strtoupper((string) $key);
        if (preg_match('/^T([+-]?\d+)$/', $key, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^D_(PLUS|MINUS)_(\d+)$/', $key, $m)) {
            return ($m[1] === 'PLUS' ? 1 : -1) * (int) $m[2];
        }

        return null;
    }

    public function effectiveOffset(): ?int
    {
        return $this->offset_days ?? static::offsetFromKey($this->milestone_key);
    }

    public function getOffsetLabelAttribute(): string
    {
        $offset = $this->effectiveOffset();

        return match (true) {
            $offset === null => 'Chưa đặt lịch',
            $offset < 0 => abs($offset).' ngày trước hạn',
            $offset === 0 => 'Đúng ngày đến hạn',
            default => 'Quá hạn '.$offset.' ngày',
        };
    }

    /**
     * Các biến {..} trong mẫu mà hệ thống không thay được.
     *
     * @return list<string>
     */
    public static function unknownVariables(string $template): array
    {
        preg_match_all('/\{[^{}\s]+\}/u', $template, $matches);
        $known = array_merge(array_keys(self::VARIABLES), array_keys(self::VARIABLE_ALIASES));

        return array_values(array_unique(array_filter(
            $matches[0],
            fn (string $var) => ! in_array(mb_strtolower($var), $known, true)
        )));
    }

    /**
     * Thay biến trong mẫu (không phân biệt hoa thường, hỗ trợ biến đồng nghĩa).
     *
     * @param  array<string, string>  $values  key = biến chuẩn dạng thường
     */
    public static function render(string $template, array $values): string
    {
        return preg_replace_callback('/\{[^{}\s]+\}/u', function (array $m) use ($values) {
            $key = mb_strtolower($m[0]);
            $key = self::VARIABLE_ALIASES[$key] ?? $key;

            return array_key_exists($key, $values) ? (string) $values[$key] : $m[0];
        }, $template);
    }
}
