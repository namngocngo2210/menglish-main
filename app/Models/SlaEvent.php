<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sổ SLA: một dòng cho mỗi (SLA, đối tượng) — khóa idempotent cho việc giao việc, phạt, cảnh báo. */
class SlaEvent extends Model
{
    protected $fillable = ['rule_key', 'subject_type', 'subject_id', 'user_id', 'triggered_at', 'due_at', 'breached_at', 'resolved_at', 'penalty_id', 'work_task_id'];

    protected $casts = ['triggered_at' => 'datetime', 'due_at' => 'datetime', 'breached_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function penalty(): BelongsTo
    {
        return $this->belongsTo(Penalty::class);
    }

    public function workTask(): BelongsTo
    {
        return $this->belongsTo(WorkTask::class);
    }
}
