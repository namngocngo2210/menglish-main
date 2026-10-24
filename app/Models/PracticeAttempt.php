<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PracticeAttempt extends Model
{
    public const IN_PROGRESS = 'in_progress';

    public const SUBMITTED = 'submitted';

    protected $fillable = [
        'student_id', 'user_id', 'grade', 'term', 'status', 'started_at', 'deadline_at', 'submitted_at',
        'auto_submitted', 'submit_reason', 'total', 'correct', 'score', 'skill_scores',
        'violation_count', 'violation_log',
    ];

    protected $casts = [
        'grade' => 'integer',
        'started_at' => 'datetime',
        'deadline_at' => 'datetime',
        'submitted_at' => 'datetime',
        'auto_submitted' => 'boolean',
        'total' => 'integer',
        'correct' => 'integer',
        'score' => 'decimal:2',
        'skill_scores' => 'array',
        'violation_count' => 'integer',
        'violation_log' => 'array',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PracticeAttemptItem::class, 'attempt_id')->orderBy('position');
    }
}
