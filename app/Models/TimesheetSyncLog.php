<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimesheetSyncLog extends Model
{
    use HasFactory;

    protected $table = 'timesheet_sync_logs';

    protected $fillable = [
        'branch_id',
        'device_name',
        'device_ip',
        'records_count',
        'matched_count',
        'status',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
