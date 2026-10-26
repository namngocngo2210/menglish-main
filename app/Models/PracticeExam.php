<?php

namespace App\Models;

use App\Support\Practice\PracticeCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Đề cố định: nguyên bộ đề theo mã, học viên khối đó chọn làm (docs/luyen-tap-ngan-hang-cau-hoi.md §11). */
class PracticeExam extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    public const STATUSES = [
        self::DRAFT => 'Nháp',
        self::PUBLISHED => 'Đã mở',
        self::HIDDEN => 'Đã ẩn',
    ];

    protected $fillable = [
        'grade', 'term', 'code', 'title', 'duration_minutes', 'status', 'source_file', 'import_warnings', 'published_at',
    ];

    protected $casts = [
        'grade' => 'integer',
        'duration_minutes' => 'integer',
        'import_warnings' => 'array',
        'published_at' => 'datetime',
    ];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuestionBankQuestion::class, 'exam_id')->orderBy('position');
    }

    public function passages(): HasMany
    {
        return $this->hasMany(QuestionBankPassage::class, 'exam_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PracticeAttempt::class, 'exam_id');
    }

    /** "Đề 601" */
    public function label(): string
    {
        return 'Đề '.$this->code;
    }

    public function termLabel(): string
    {
        return PracticeCatalog::TERMS[$this->term] ?? $this->term;
    }
}
