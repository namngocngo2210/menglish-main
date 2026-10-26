<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lần đầu GV mở trang "Đọc & xác nhận" của một chặng được giao (mốc tính thời gian đọc tổng quan). */
class SyllabusAssignmentRead extends Model
{
    protected $fillable = ['syllabus_assignment_id', 'user_id', 'opened_at'];

    protected $casts = [
        'opened_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SyllabusAssignment::class, 'syllabus_assignment_id');
    }
}
