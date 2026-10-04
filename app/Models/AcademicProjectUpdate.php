<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lần cập nhật tiến độ của thành viên dự án: việc đã làm, khối lượng xong đến nay, link sản phẩm, khó khăn; Học thuật phản hồi. */
class AcademicProjectUpdate extends Model
{
    public const MAX_LINKS = 5;

    protected $fillable = [
        'academic_project_id', 'milestone_id', 'user_id', 'quantity_done', 'content', 'difficulties', 'links',
        'marks_complete', 'response', 'responded_by', 'responded_at',
    ];

    protected $casts = [
        'quantity_done' => 'float',
        'links' => 'array',
        'marks_complete' => 'boolean',
        'responded_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(AcademicProject::class, 'academic_project_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(AcademicProjectMilestone::class, 'milestone_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
