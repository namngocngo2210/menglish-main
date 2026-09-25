<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiCriterion extends Model
{
    use HasFactory;

    protected $table = 'kpi_criteria';

    protected $fillable = [
        'name',
        'weight',
        'target',
        'unit',
        'description',
        'is_active',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
