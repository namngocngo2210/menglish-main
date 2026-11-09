<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiRetentionTier extends Model
{
    protected $fillable = ['min_seniority_months', 'from_students', 'to_students', 'rate'];

    protected $casts = [
        'min_seniority_months' => 'integer',
        'from_students' => 'integer',
        'to_students' => 'integer',
        'rate' => 'decimal:2',
    ];
}
