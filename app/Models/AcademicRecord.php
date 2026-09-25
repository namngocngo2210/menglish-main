<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'academic_records';

    protected $fillable = [
        'screen_key',
        'module',
        'record_code',
        'title',
        'status',
        'is_seed',
        'data',
        'user_id',
    ];

    protected $casts = [
        'is_seed' => 'boolean',
        'data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeForScreen($query, string $screenKey)
    {
        return $query->where('screen_key', $screenKey);
    }

    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
