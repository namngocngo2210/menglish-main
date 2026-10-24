<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PracticeAttemptItem extends Model
{
    protected $fillable = ['attempt_id', 'question_id', 'position', 'skill', 'type', 'snapshot', 'chosen', 'is_correct'];

    protected $casts = [
        'position' => 'integer',
        'snapshot' => 'array',
        'is_correct' => 'boolean',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PracticeAttempt::class, 'attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuestionBankQuestion::class, 'question_id');
    }
}
