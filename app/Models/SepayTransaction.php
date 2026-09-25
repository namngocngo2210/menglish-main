<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SepayTransaction extends Model
{
    use HasFactory;

    protected $table = 'sepay_transactions';

    protected $fillable = [
        'sepay_id',
        'gateway',
        'transaction_date',
        'account_number',
        'sub_account',
        'transfer_type',
        'transfer_amount',
        'accumulated',
        'content',
        'reference_code',
        'raw_payload',
        'status',
        'matched_student_id',
        'matched_tuition_id',
        'matched_receipt_id',
        'response_message',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'transfer_amount' => 'decimal:2',
        'accumulated' => 'decimal:2',
        'raw_payload' => 'array',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'matched_student_id');
    }

    public function tuition(): BelongsTo
    {
        return $this->belongsTo(StudentTuition::class, 'matched_tuition_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(TuitionReceipt::class, 'matched_receipt_id');
    }
}
