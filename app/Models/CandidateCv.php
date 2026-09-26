<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateCv extends Model
{
    use HasFactory;

    protected $table = 'candidate_cvs';

    protected $fillable = [
        'job_posting_id',
        'full_name',
        'email',
        'phone',
        'applying_position',
        'branch_id',
        'cv_file_path',
        'portfolio_url',
        'cover_letter',
        'notes',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusBadge(): array
    {
        return match ($this->status) {
            'pending' => ['label' => 'Chờ xử lý', 'class' => 'bg-warning/10 text-warning border-warning/30'],
            'reviewing' => ['label' => 'Đang đánh giá', 'class' => 'bg-secondary/10 text-secondary border-secondary/30'],
            'interviewed' => ['label' => 'Đã phỏng vấn', 'class' => 'bg-purple-50 text-purple-700 border-purple-200'],
            'accepted' => ['label' => 'Đã tuyển dụng', 'class' => 'bg-tertiary/10 text-tertiary border-tertiary/30'],
            'rejected' => ['label' => 'Từ chối', 'class' => 'bg-error/10 text-error border-error/30'],
            default => ['label' => $this->status, 'class' => 'bg-surface-container-low text-on-surface-variant border-surface-container-highest'],
        };
    }
}
