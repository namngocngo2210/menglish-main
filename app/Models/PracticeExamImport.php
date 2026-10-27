<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lần import đề cố định chạy nền (job ImportPracticeExams): file tải lên nằm ở storage/app/private/exam-imports/{id}
 * tới khi job xong; kết quả từng đề lưu ở report để màn Đề cố định hiện lại.
 */
class PracticeExamImport extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const STATUSES = [
        self::QUEUED => 'Chờ xử lý',
        self::RUNNING => 'Đang xử lý',
        self::DONE => 'Hoàn thành',
        self::FAILED => 'Thất bại',
    ];

    protected $fillable = ['user_id', 'status', 'file_names', 'message', 'report', 'started_at', 'finished_at'];

    protected $casts = [
        'file_names' => 'array',
        'report' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /** Thư mục chứa file tải lên trên disk local. */
    public function directory(): string
    {
        return "exam-imports/{$this->id}";
    }
}
