<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HrDailyDemand extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'report_date',
        'day_of_week',
        'shift_count',
        'staff_needed',
    ];

    protected $casts = [
        'report_date' => 'date',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
