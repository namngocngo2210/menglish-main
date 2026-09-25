<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlacementTestSubmission extends Model
{
    use HasFactory;

    protected $table = 'placement_test_submissions';

    protected $fillable = [
        'placement_test_id',
        'customer_id',
        'student_id',
        'candidate_name',
        'candidate_phone',
        'candidate_email',
        'listening_score',
        'reading_score',
        'writing_score',
        'speaking_score',
        'overall_score',
        'cefr_level',
        'writing_content',
        'answers',
        'speaking_audio_url',
        'recommended_course',
        'teacher_comments',
        'grader_id',
        'status',
    ];

    protected $casts = [
        'listening_score' => 'decimal:1',
        'reading_score' => 'decimal:1',
        'writing_score' => 'decimal:1',
        'speaking_score' => 'decimal:1',
        'overall_score' => 'decimal:1',
        'answers' => 'array',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(PlacementTest::class, 'placement_test_id');
    }

    public function placementTest(): BelongsTo
    {
        return $this->belongsTo(PlacementTest::class, 'placement_test_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'customer_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grader_id');
    }

    public function calculateOverall(): void
    {
        $this->overall_score = round(($this->listening_score + $this->reading_score + $this->writing_score + $this->speaking_score) / 4, 1);
    }
}
