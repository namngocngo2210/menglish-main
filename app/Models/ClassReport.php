<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Báo cáo trực lớp (A6 Q8, 25/09/2026):
 *  - Ảnh bảng/lớp KHÔNG bắt buộc.
 *  - Có ≥ 1 ảnh → báo cáo "Đã xác nhận", đầu việc "Trực lớp" tự Hoàn thành.
 *  - Không ảnh → "Chờ xác nhận": GV chính của lớp xác nhận; lớp chưa có GV chính
 *    thì NGƯỜI GIAO VIỆC (người tạo đầu việc "Trực lớp") xác nhận.
 */
class ClassReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'class_id',
        'class_session_id',
        'reporter_id',
        'confirmer_id',
        'session_name',
        'session_date',
        'topics_learned',
        'teaching_log',
        'board_image',
        'board_images',
        'has_image',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    protected $casts = [
        'session_date' => 'date',
        'has_image' => 'boolean',
        'board_images' => 'array',
        'approved_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Chờ xác nhận',
        self::STATUS_APPROVED => 'Đã xác nhận',
        self::STATUS_REJECTED => 'Trả về',
    ];

    public function task()
    {
        return $this->belongsTo(WorkTask::class, 'task_id');
    }

    public function classModel()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function classSession()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmer_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function studentSupports()
    {
        return $this->hasMany(ClassReportStudentSupport::class, 'class_report_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ($this->status ?: 'Chưa cập nhật');
    }

    /**
     * Danh sách đường dẫn ảnh bảng/lớp (gộp cột cũ board_image).
     *
     * @return string[]
     */
    public function images(): array
    {
        $images = array_values(array_filter((array) ($this->board_images ?? [])));

        return $images ?: array_values(array_filter([$this->board_image]));
    }

    /**
     * URL hiển thị của một ảnh (file trên disk public hoặc URL ngoài).
     */
    public static function imageUrl(string $path): string
    {
        return preg_match('~^https?://~i', $path) ? $path : \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    }

    /**
     * Người phải xác nhận báo cáo không có ảnh theo A6 Q8:
     * GV chính của lớp; lớp chưa có GV chính (hoặc GV chính chính là người nộp)
     * → người giao đầu việc "Trực lớp". Không ai tự xác nhận báo cáo của mình.
     */
    public static function resolveConfirmerId(?ClassModel $class, ?WorkTask $task, int $reporterId): ?int
    {
        $mainTeacherId = $class?->teacher_id ? (int) $class->teacher_id : null;
        if ($mainTeacherId && $mainTeacherId !== $reporterId) {
            return $mainTeacherId;
        }

        $assignerId = $task?->creator_id ? (int) $task->creator_id : null;
        if ($assignerId && $assignerId !== $reporterId) {
            return $assignerId;
        }

        return null;
    }

    /**
     * Người xác nhận hiện tại (tính lại theo GV chính hiện tại của lớp — GV chính
     * đổi sau khi nộp thì GV chính mới xác nhận).
     */
    public function currentConfirmerId(): ?int
    {
        return self::resolveConfirmerId($this->classModel, $this->task, (int) $this->reporter_id)
            ?? ($this->confirmer_id ? (int) $this->confirmer_id : null);
    }

    public function confirmerRoleLabel(): string
    {
        $confirmerId = $this->currentConfirmerId();
        if ($confirmerId && $this->classModel && (int) $this->classModel->teacher_id === $confirmerId) {
            return 'GV chính của lớp';
        }

        return 'Người giao việc';
    }
}
