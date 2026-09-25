<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Chặng của giáo trình (Q4 — BA chốt 25/09/2026).
 *
 * Giáo trình → Chặng (thứ tự `position`, có Big Test cuối chặng) → Unit → Buổi.
 * Mỗi lớp chỉ mở 1 chặng tại một thời điểm (xem SyllabusAssignment / SyllabusProgressionService);
 * chặng đóng khi Big Test của chặng được duyệt và gửi phụ huynh, chặng có `position` kế tiếp tự mở.
 */
class SyllabusStage extends Model
{
    protected $table = 'syllabus_stages';

    protected $fillable = [
        'curriculum_id',
        'position',
        'name',
        'description',
        'overview_link',
        'big_test_title',
        'big_test_note',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(SyllabusCurriculum::class, 'curriculum_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(SyllabusUnit::class, 'stage_id')->orderBy('unit_number')->orderBy('id');
    }

    public function lessons(): HasManyThrough
    {
        return $this->hasManyThrough(SyllabusLesson::class, SyllabusUnit::class, 'stage_id', 'unit_id')
            ->orderBy('syllabus_lessons.session_no');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SyllabusAssignment::class, 'stage_id');
    }

    public function bigTests(): HasMany
    {
        return $this->hasMany(BigTest::class, 'syllabus_stage_id');
    }

    /** Chặng kế tiếp trong cùng giáo trình (theo position, rồi id). */
    public function next(): ?self
    {
        return self::where('curriculum_id', $this->curriculum_id)
            ->where(fn ($q) => $q->where('position', '>', $this->position)
                ->orWhere(fn ($q) => $q->where('position', $this->position)->where('id', '>', $this->id)))
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    public function getLabelAttribute(): string
    {
        $prefix = 'Chặng '.$this->position;

        return str_starts_with(mb_strtolower($this->name), mb_strtolower($prefix)) ? $this->name : $prefix.': '.$this->name;
    }

    /** Phạm vi Unit / Buổi của chặng dạng "Unit 1–3 · Buổi 1–12" (dùng cho nội dung được giao). */
    public function rangeSummary(): string
    {
        $units = $this->units()->pluck('unit_number');
        $sessions = SyllabusLesson::whereIn('unit_id', $this->units()->select('id'))->pluck('session_no');
        $parts = [];
        if ($units->isNotEmpty()) {
            $parts[] = 'Unit '.$units->min().($units->max() !== $units->min() ? '–'.$units->max() : '');
        }
        if ($sessions->isNotEmpty()) {
            $parts[] = 'Buổi '.$sessions->min().($sessions->max() !== $sessions->min() ? '–'.$sessions->max() : '');
        }

        return $parts ? implode(' · ', $parts) : $this->label;
    }
}
