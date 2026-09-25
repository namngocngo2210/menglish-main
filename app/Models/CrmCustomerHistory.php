<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmCustomerHistory extends Model
{
    use HasFactory;

    protected $table = 'crm_customer_histories';

    protected $fillable = [
        'customer_id',
        'user_id',
        'type',
        'content',
        'from_stage',
        'to_stage',
        'reason',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'customer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getTypeIconAttribute(): string
    {
        return match ($this->type) {
            'call' => 'call',
            'message' => 'chat',
            'meet' => 'groups',
            'test' => 'quiz',
            'stage_change' => 'sync_alt',
            'trial' => 'school',
            default => 'notes',
        };
    }
}
