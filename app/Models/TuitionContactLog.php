<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nhật ký đôn đốc công nợ: đã liên hệ phụ huynh / đã báo cáo Admin. */
class TuitionContactLog extends Model
{
    public const ACTION_CONTACTED = 'contacted';

    public const ACTION_REPORTED = 'reported_admin';

    protected $table = 'tuition_contact_logs';

    protected $fillable = [
        'student_tuition_id',
        'user_id',
        'action',
        'note',
        'contacted_at',
    ];

    protected $casts = [
        'contacted_at' => 'datetime',
    ];

    public function tuition(): BelongsTo
    {
        return $this->belongsTo(StudentTuition::class, 'student_tuition_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
