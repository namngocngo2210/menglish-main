<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiEvaluationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'kpi_evaluation_id',
        'kpi_criterion_id',
        'score',
        'actual',
        'critical_error',
        'note',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'critical_error' => 'boolean',
    ];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(KpiEvaluation::class, 'kpi_evaluation_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(KpiCriterion::class, 'kpi_criterion_id');
    }
}
