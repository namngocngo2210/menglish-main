<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPeriodTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['payroll_period_id', 'from_status', 'to_status', 'user_id', 'note', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
