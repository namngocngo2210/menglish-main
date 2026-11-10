<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoverRequest extends Model
{
    protected $fillable = [
        'class_session_id', 'requested_by', 'original_teacher_id', 'reason', 'status',
        'approved_user_id', 'approved_by', 'approved_at', 'cancelled_at', 'attendance_reminded_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'attendance_reminded_at' => 'datetime',
    ];

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function originalTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_teacher_id');
    }

    public function approvedTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(CoverRegistration::class);
    }
}
