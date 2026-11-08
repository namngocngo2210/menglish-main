<?php

namespace App\Models;

use App\Services\SupportListService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BigTestResult extends Model
{
    use HasFactory;

    public const STATUS_LABELS = [
        'draft' => 'Nháp',
        'pending_review' => 'Chờ duyệt',
        'approved' => 'Đã duyệt',
        'sent' => 'Đã báo phụ huynh',
    ];

    /** Kết quả đã duyệt/đã gửi phụ huynh thì không được chấm lại. */
    public const LOCKED_STATUSES = ['approved', 'sent'];

    protected $table = 'big_test_results';

    protected $fillable = [
        'big_test_id',
        'student_id',
        'listening_score',
        'reading_score',
        'writing_score',
        'speaking_score',
        'components',
        'overall_score',
        'is_absent',
        'progress_note',
        'video_url',
        'status',
        'graded_by',
        'approved_by',
        'approved_at',
        'parent_notified',
        'notified_at',
    ];

    protected $casts = [
        'listening_score' => 'decimal:1',
        'reading_score' => 'decimal:1',
        'writing_score' => 'decimal:1',
        'speaking_score' => 'decimal:1',
        'overall_score' => 'decimal:1',
        'components' => 'array',
        'is_absent' => 'boolean',
        'parent_notified' => 'boolean',
        'notified_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Điểm Big Test dưới 7/10 (không tính vắng thi) → tự vào danh sách bổ trợ.
        static::saved(fn (BigTestResult $result) => app(SupportListService::class)->syncBigTest($result));
        static::deleted(fn (BigTestResult $result) => app(SupportListService::class)->forget(SupportListService::SOURCE_BIG_TEST, $result->id));
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(BigTest::class, 'big_test_id');
    }

    public function bigTest(): BelongsTo
    {
        return $this->belongsTo(BigTest::class, 'big_test_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** 4 kỹ năng của dữ liệu cũ (trước TK-2026-0056); chỉ dùng để đọc lại bản ghi cũ. */
    public const LEGACY_SKILLS = ['listening_score' => 'Nghe', 'reading_score' => 'Đọc', 'writing_score' => 'Viết', 'speaking_score' => 'Nói'];

    /**
     * Thành phần điểm [{name, score}] do GV tự đặt. Bản ghi cũ chỉ có 4 cột kỹ năng vẫn hiện được, không cần migrate dữ liệu.
     *
     * @return list<array{name: string, score: float}>
     */
    public function scoreComponents(): array
    {
        $stored = collect((array) $this->components)
            ->filter(fn ($c) => is_array($c) && filled($c['name'] ?? null) && ($c['score'] ?? null) !== null && $c['score'] !== '')
            ->map(fn ($c) => ['name' => (string) $c['name'], 'score' => (float) $c['score']])
            ->values()->all();
        if ($stored !== []) {
            return $stored;
        }

        $legacy = [];
        foreach (self::LEGACY_SKILLS as $column => $label) {
            if ($this->{$column} !== null) {
                $legacy[] = ['name' => $label, 'score' => (float) $this->{$column}];
            }
        }

        return $legacy;
    }

    /** "Nghe 8 · Đọc 7.5" — điểm các thành phần trên một dòng, không có điểm tổng. */
    public function scoreSummary(): string
    {
        return collect($this->scoreComponents())->map(fn ($c) => $c['name'].' '.(float) $c['score'])->implode(' · ');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED_STATUSES, true);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }
}
