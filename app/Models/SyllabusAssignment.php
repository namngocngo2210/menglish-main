<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyllabusAssignment extends Model
{
    use HasFactory;

    protected $table = 'syllabus_assignments';

    protected $fillable = [
        'user_id',
        'curriculum_id',
        'class_id',
        'assigned_chapters',
        'stage_name',
        'deadline',
        'progress_percent',
        'status',
    ];

    protected $casts = [
        'deadline' => 'date',
        'progress_percent' => 'integer',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(SyllabusCurriculum::class, 'curriculum_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }
}
