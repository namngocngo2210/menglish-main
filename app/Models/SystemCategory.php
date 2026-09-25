<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SystemCategory extends Model
{
    use HasFactory, SoftDeletes;

    // Nhóm danh mục theo đúng các tab trong màn "Quản lý Danh mục hệ thống".
    public const TYPE_LEAD_SOURCE = 'lead_source';

    public const TYPE_LOST_REASON = 'lost_reason';

    public const TYPE_POSITION = 'position';

    public const TYPE_FINE_LEVEL = 'fine_level';

    public const TYPES = [
        self::TYPE_LEAD_SOURCE,
        self::TYPE_LOST_REASON,
        self::TYPE_POSITION,
        self::TYPE_FINE_LEVEL,
    ];

    protected $fillable = [
        'type',
        'code',
        'name',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
