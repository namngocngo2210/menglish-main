<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyllabusUnit extends Model
{
    use HasFactory;

    protected $table = 'syllabus_units';

    protected $fillable = [
        'curriculum_id',
        'unit_number',
        'title',
        'objectives',
        'vocabulary_focus',
        'grammar_focus',
        'homework_guide',
    ];

    protected $casts = [
        'unit_number' => 'integer',
    ];

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(SyllabusCurriculum::class, 'curriculum_id');
    }
}
