<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceCancellation extends Model
{
    use HasFactory;

    protected $table = 'invoice_cancellations';

    protected $fillable = [
        'invoice_number',
        'tuition_receipt_id',
        'student_id',
        'amount',
        'reason',
        'rejection_reason',
        'proof_image',
        'requester_id',
        'approver_id',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(TuitionReceipt::class, 'tuition_receipt_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
