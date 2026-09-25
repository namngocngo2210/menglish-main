<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassReportStudentSupport extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_report_id',
        'student_id',
        'absence_session',
        'reason',
        'action_plan',
    ];

    public function classReport()
    {
        return $this->belongsTo(ClassReport::class, 'class_report_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function supportSession()
    {
        return $this->hasOne(SupportSession::class, 'class_report_student_support_id');
    }
}
