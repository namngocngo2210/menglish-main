<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BigTest extends Model
{
    use HasFactory;

    protected $table = 'big_tests';

    protected $fillable = [
        'code',
        'title',
        'class_id',
        'test_type',
        'scheduled_at',
        'room',
        'proctor_id',
        'passcode',
        'content_url',
        'is_distributed',
        'status',
        'approved_by',
        'approved_at',
        'distributed_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'is_distributed' => 'boolean',
        'approved_at' => 'datetime',
        'distributed_at' => 'datetime',
    ];

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function proctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proctor_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(BigTestResult::class, 'big_test_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
