<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffReportFollowup extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_report_id',
        'user_id',
        'content',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(StaffReport::class, 'staff_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
