<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SystemCategory extends Model
{
    use AuditsChanges, HasFactory, SoftDeletes;

    // Nhóm danh mục theo đúng các tab trong màn "Quản lý Danh mục hệ thống".
    public const TYPE_LEAD_SOURCE = 'lead_source';

    public const TYPE_LOST_REASON = 'lost_reason';

    public const TYPE_POSITION = 'position';

    public const TYPE_FINE_LEVEL = 'fine_level';

    public const TYPES = [
        self::TYPE_LEAD_SOURCE,
        self::TYPE_LOST_REASON,
        self::TYPE_POSITION,
        self::TYPE_FINE_LEVEL,
    ];

    /** Nhãn tab (mockup) và tiền tố mã gợi ý (VD: SRC_06). */
    public const TYPE_LABELS = [
        self::TYPE_LEAD_SOURCE => 'Nguồn khách hàng',
        self::TYPE_LOST_REASON => 'Lý do không chốt',
        self::TYPE_POSITION => 'Chức vụ',
        self::TYPE_FINE_LEVEL => 'Mức phạt',
    ];

    public const CODE_PREFIXES = [
        self::TYPE_LEAD_SOURCE => 'SRC',
        self::TYPE_LOST_REASON => 'LOST',
        self::TYPE_POSITION => 'POS',
        self::TYPE_FINE_LEVEL => 'FINE',
    ];

    /** Mã gợi ý kế tiếp của nhóm: TIỀNTỐ_NN (tính cả bản ghi đã ngừng / đã xóa mềm). */
    public static function suggestCode(string $type): string
    {
        $prefix = self::CODE_PREFIXES[$type] ?? 'CAT';
        $max = self::withTrashed()->where('type', $type)->pluck('code')
            ->map(fn ($code) => preg_match('/^'.preg_quote($prefix, '/').'_(\d+)$/', (string) $code, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return sprintf('%s_%02d', $prefix, $max + 1);
    }

    protected $fillable = [
        'type',
        'code',
        'name',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
