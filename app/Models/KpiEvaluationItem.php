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
        'critical_note',
        'critical_marked_by',
        'critical_marked_at',
        'critical_prev_score',
        'not_applicable',
        'note',
        'evidence',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'critical_error' => 'boolean',
        'critical_marked_by' => 'integer',
        'critical_marked_at' => 'datetime',
        'critical_prev_score' => 'decimal:2',
        'not_applicable' => 'boolean',
        'evidence' => 'array',
    ];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(KpiEvaluation::class, 'kpi_evaluation_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(KpiCriterion::class, 'kpi_criterion_id')->withTrashed();
    }

    public function criticalMarker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'critical_marked_by');
    }
}
