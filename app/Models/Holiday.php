<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Holiday extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'start_date',
        'end_date',
        'is_system_wide',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_system_wide' => 'boolean',
        ];
    }

    /**
     * Chi nhánh áp dụng. Rỗng + is_system_wide=true nghĩa là áp dụng toàn hệ thống.
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'holiday_branches');
    }

    public function appliesToBranch(?int $branchId): bool
    {
        if ($this->is_system_wide) {
            return true;
        }

        if ($branchId === null) {
            return false;
        }

        return $this->branches()->where('branches.id', $branchId)->exists();
    }
}
