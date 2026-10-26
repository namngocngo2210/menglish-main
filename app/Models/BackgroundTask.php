<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một việc nặng chạy nền (job con của App\Jobs\BackgroundTaskJob). File tải lên (nếu có) nằm ở
 * storage/app/private/background-tasks/{id} tới khi job xong. Màn khởi chạy hiện vài lần gần nhất (recentFor) và tự tải lại
 * khi còn việc đang chờ / đang chạy; xong thì người chạy nhận thông báo.
 */
class BackgroundTask extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const STATUSES = [
        self::QUEUED => 'Đang chờ',
        self::RUNNING => 'Đang xử lý',
        self::DONE => 'Xong',
        self::FAILED => 'Lỗi',
    ];

    public const QUESTION_BANK_IMPORT = 'question_bank_import';

    public const SYLLABUS_IMPORT = 'syllabus_import';

    public const PAYROLL_CALCULATE = 'payroll_calculate';

    public const CRM_IMPORT = 'crm_import';

    public const TUITION_IMPORT = 'tuition_import';

    public const STAFF_IMPORT = 'staff_import';

    public const CLASS_ROSTER_IMPORT = 'class_roster_import';

    protected $fillable = ['type', 'user_id', 'status', 'title', 'payload', 'message', 'report', 'started_at', 'finished_at'];

    protected $casts = [
        'payload' => 'array',
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
        return "background-tasks/{$this->id}";
    }

    /** Việc cùng loại còn đang chờ / đang chạy (vd. không cho tính lương cùng kỳ hai lần một lúc). */
    public static function pendingExists(string $type, ?callable $where = null): bool
    {
        return static::query()->where('type', $type)->whereIn('status', [self::QUEUED, self::RUNNING])
            ->when($where, $where)->exists();
    }

    /**
     * Vài lần chạy gần nhất của một loại, cho component BackgroundTasks: lần đầu kèm báo cáo chi tiết.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentFor(string $type, ?int $userId = null, int $limit = 3, ?callable $where = null): array
    {
        return static::query()->with('user:id,name')->where('type', $type)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($where, $where)
            ->latest('id')->limit($limit)->get()
            ->map(fn (self $task, int $i) => [
                'id' => $task->id,
                'status' => $task->status,
                'status_label' => self::STATUSES[$task->status] ?? $task->status,
                'pending' => $task->isPending(),
                'title' => $task->title,
                'user' => $task->user?->name,
                'created_at' => $task->created_at?->format('d/m/Y H:i'),
                // chờ quá 2 phút chưa chạy: thường do tiến trình xử lý nền (queue) chưa bật
                'stalled' => $task->status === self::QUEUED && $task->created_at?->lt(now()->subMinutes(2)),
                'message' => $task->message,
                'report' => $i === 0 ? $task->report : null,
            ])->all();
    }

    /**
     * Màn nhập 3 bước sau khi xác nhận (?task=): xong → báo cáo làm bước Kết quả; đang chờ / lỗi → trạng thái việc nền
     * (BackgroundTasks.vue tự tải lại tới khi xong). Chỉ người chạy xem được việc của mình.
     *
     * @return array{result: ?array, backgroundTasks: array}
     */
    public static function importResult(string $type, mixed $taskId, int $userId): array
    {
        $task = $taskId ? static::query()->where('type', $type)->where('user_id', $userId)->find((int) $taskId) : null;
        $result = $task?->status === self::DONE ? $task->report : null;

        return [
            'result' => $result,
            'backgroundTasks' => $task && ! $result ? static::recentFor($type, limit: 1, where: fn ($q) => $q->whereKey($task->id)) : [],
        ];
    }
}
