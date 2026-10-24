<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ghi chú của Quản lý / người làm trên báo cáo tuần Học thuật (Sheet 4 câu 9). */
class StaffReportNote extends Model
{
    protected $fillable = ['staff_report_id', 'user_id', 'note'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(StaffReport::class, 'staff_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
