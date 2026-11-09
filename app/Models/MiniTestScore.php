<?php

namespace App\Models;

use App\Services\SupportListService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiniTestScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_id',
        'student_id',
        'user_id',
        'syllabus_unit_id',
        'name',
        'score',
        'max_score',
        'skill_scores',
        'test_date',
        'note',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'test_date' => 'date',
        'skill_scores' => 'array',
    ];

    /** 4 kỹ năng của dữ liệu cũ (trước TK-2026-0049); chỉ dùng để đọc lại bản ghi cũ. */
    public const SKILLS = ['listening' => 'Nghe', 'speaking' => 'Nói', 'reading' => 'Đọc', 'writing' => 'Viết'];

    /** Bản ghi mới (TK-2026-0049): thành phần [{name, score}] tự do, không có thang; bản ghi cũ là map kỹ năng => điểm /max_score. */
    public function isFreeScale(): bool
    {
        return collect((array) $this->skill_scores)->contains(fn ($i) => is_array($i));
    }

    /**
     * Thành phần điểm dạng danh sách [{name, score}]. Bản ghi cũ lưu dạng {listening: 8, ...} vẫn đọc được, không cần migrate.
     *
     * @return list<array{name: string, score: float}>
     */
    public function components(): array
    {
        $out = [];
        foreach ((array) $this->skill_scores as $key => $item) {
            if (is_array($item)) {
                $name = trim((string) ($item['name'] ?? ''));
                $score = $item['score'] ?? null;
            } else {
                $name = self::SKILLS[$key] ?? (string) $key;
                $score = $item;
            }
            if ($name !== '' && $score !== null && $score !== '') {
                $out[] = ['name' => $name, 'score' => (float) $score];
            }
        }

        return $out;
    }

    protected static function booted(): void
    {
        // Điểm mini test dưới 7/10 → tự vào danh sách bổ trợ.
        static::saved(fn (MiniTestScore $score) => app(SupportListService::class)->syncMiniTest($score));
        static::deleted(fn (MiniTestScore $score) => app(SupportListService::class)->forget(SupportListService::SOURCE_MINI_TEST, $score->id));
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
