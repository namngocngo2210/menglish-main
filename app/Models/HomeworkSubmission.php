<?php

namespace App\Models;

use App\Services\SafeUploadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bài nộp theo giỏ của một bài tập GV giao (docs/kho-syllabus.md §10). */
class HomeworkSubmission extends Model
{
    /** Giỏ => [nhãn, icon, đuôi file cho phép (null = Quizizz tự khai, không tải file)]. */
    public const BASKETS = [
        'paper' => ['Bài tập giấy', 'description', SafeUploadService::IMAGES],
        'quizizz' => ['Bài tập Quizizz', 'quiz', null],
        'video' => ['Bài tập Video', 'videocam', SafeUploadService::VIDEO],
    ];

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_REVIEWED = 'reviewed';

    protected $fillable = [
        'homework_id', 'student_id', 'basket', 'file_path', 'original_name', 'note', 'status', 'score', 'feedback', 'submitted_at', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function homework(): BelongsTo
    {
        return $this->belongsTo(Homework::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
