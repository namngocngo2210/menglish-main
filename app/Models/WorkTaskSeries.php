<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class WorkTaskSeries extends Model
{
    use AuditsChanges;

    protected $table = 'work_task_series';

    protected $fillable = ['penalty_amount', 'configured_by', 'configured_at'];

    protected $casts = [
        'penalty_amount' => 'decimal:2',
        'configured_at' => 'datetime',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkTask::class, 'series_id');
    }

    public function latestOccurrence(): HasOne
    {
        return $this->hasOne(WorkTask::class, 'series_id')->latestOfMany();
    }

    public function isConfigured(): bool
    {
        return $this->configured_at !== null;
    }

    public function resume(): ?WorkTask
    {
        return DB::transaction(function () {
            $series = self::query()->lockForUpdate()->find($this->id);
            if (! $series?->isConfigured()) {
                return null;
            }

            // Overdue, blocked and awaiting confirmation occurrences are also still open.
            if ($series->tasks()->whereNotIn('status', ['completed', 'canceled'])->exists()) {
                return null;
            }

            return $series->latestOccurrence()->first()?->spawnNextOccurrence(today());
        });
    }
}
