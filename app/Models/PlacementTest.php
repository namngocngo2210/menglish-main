<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlacementTest extends Model
{
    use HasFactory;

    protected $table = 'placement_tests';

    protected $fillable = [
        'code',
        'title',
        'description',
        'target_level',
        'duration_minutes',
        'questions_count',
        'questions',
        'is_active',
        'is_preset',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'questions_count' => 'integer',
        'questions' => 'array',
        'is_active' => 'boolean',
        'is_preset' => 'boolean',
    ];

    public function submissions(): HasMany
    {
        return $this->hasMany(PlacementTestSubmission::class, 'placement_test_id');
    }
}
