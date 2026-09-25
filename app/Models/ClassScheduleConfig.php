<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassScheduleConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_id',
        'academic_year',
        'slot1_day',
        'slot1_start',
        'slot1_end',
        'slot2_day',
        'slot2_start',
        'slot2_end',
    ];

    public function classModel()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }
}
