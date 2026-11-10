<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoverRegistration extends Model
{
    protected $fillable = ['cover_request_id', 'teacher_id'];

    public function coverRequest(): BelongsTo
    {
        return $this->belongsTo(CoverRequest::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
