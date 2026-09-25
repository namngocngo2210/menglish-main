<?php

namespace App\Models;

use App\Services\SupportListService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Một dòng trong "danh sách bổ trợ": học viên cần học bổ trợ ở một lớp, kèm nguồn phát sinh
 * (báo cáo trực lớp, vắng học, mini test < 7, Big Test < 7 — xem SupportListService).
 */
class ClassReportStudentSupport extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_report_id',
        'class_id',
        'student_id',
        'source',
        'source_id',
        'score',
        'absence_session',
        'reason',
        'action_plan',
    ];

    protected $attributes = [
        'source' => SupportListService::SOURCE_CLASS_REPORT,
    ];

    protected $casts = [
        'score' => 'decimal:2',
    ];

    public function classReport()
    {
        return $this->belongsTo(ClassReport::class, 'class_report_id');
    }

    public function classModel()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function supportSession()
    {
        return $this->hasOne(SupportSession::class, 'class_report_student_support_id');
    }

    public function getSourceLabelAttribute(): string
    {
        return SupportListService::SOURCE_LABELS[$this->source] ?? (string) $this->source;
    }

    /** Lớp của dòng bổ trợ (dữ liệu cũ chỉ có qua báo cáo trực lớp). */
    public function resolvedClassId(): ?int
    {
        return $this->class_id ? (int) $this->class_id : ($this->classReport?->class_id ? (int) $this->classReport->class_id : null);
    }
}
