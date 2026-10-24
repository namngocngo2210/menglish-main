<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionBankQuestion extends Model
{
    use AuditsChanges;

    public const STATUSES = [
        'draft' => 'Chưa duyệt',
        'approved' => 'Đã duyệt',
        'hidden' => 'Đã ẩn',
    ];

    protected $fillable = [
        'passage_id', 'position', 'grade', 'term', 'skill', 'type', 'knowledge', 'instruction', 'stem',
        'options', 'answer', 'image_path', 'status', 'approved_by', 'approved_at', 'edited_at',
        'source_school', 'source_year', 'source_file', 'source_key',
    ];

    protected $casts = [
        'grade' => 'integer',
        'position' => 'integer',
        'options' => 'array',
        'approved_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function passage(): BelongsTo
    {
        return $this->belongsTo(QuestionBankPassage::class, 'passage_id');
    }
}
