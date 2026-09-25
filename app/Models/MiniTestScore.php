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
        'name',
        'score',
        'max_score',
        'test_date',
        'note',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'test_date' => 'date',
    ];

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
