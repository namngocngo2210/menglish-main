<?php

namespace App\Models;

use App\Support\Practice\PracticeCatalog;
use Illuminate\Database\Eloquent\Model;

/** Khung đề luyện tập theo khối × kỳ. */
class PracticeBlueprint extends Model
{
    protected $fillable = ['grade', 'term', 'duration_minutes', 'items'];

    protected $casts = [
        'grade' => 'integer',
        'duration_minutes' => 'integer',
        'items' => 'array',
    ];

    /** Khung đã lưu, hoặc bản chưa lưu với giá trị mặc định khi hàng bị xóa. */
    public static function forGradeTerm(int $grade, string $term): self
    {
        return self::query()->where('grade', $grade)->where('term', $term)->first()
            ?? new self([
                'grade' => $grade,
                'term' => $term,
                'duration_minutes' => PracticeCatalog::DEFAULT_DURATION,
                'items' => PracticeCatalog::DEFAULT_ITEMS,
            ]);
    }
}
