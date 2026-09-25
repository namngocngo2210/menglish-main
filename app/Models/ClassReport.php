<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'class_id',
        'reporter_id',
        'session_name',
        'session_date',
        'topics_learned',
        'teaching_log',
        'board_image',
        'has_image',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'session_date' => 'date',
        'has_image' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(WorkTask::class, 'task_id');
    }

    public function classModel()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function studentSupports()
    {
        return $this->hasMany(ClassReportStudentSupport::class, 'class_report_id');
    }

    public const STATUS_LABELS = [
        'pending_approval' => 'Chờ duyệt',
        'approved' => 'Đã duyệt',
        'rejected' => 'Trả về',
    ];

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ($this->status ?: 'Chưa cập nhật');
    }
}
