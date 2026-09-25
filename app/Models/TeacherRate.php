<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherRate extends Model
{
    use HasFactory;

    protected $table = 'teacher_rates';

    protected $fillable = [
        'rank_title',
        'criteria',
        'communication_rate',
        'ielts_rate',
    ];

    protected $casts = [
        'communication_rate' => 'decimal:2',
        'ielts_rate' => 'decimal:2',
    ];
}
