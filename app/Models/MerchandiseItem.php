<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MerchandiseItem extends Model
{
    use HasFactory, SoftDeletes;

    public const CATEGORY_BOOK = 'book';
    public const CATEGORY_WORKBOOK = 'workbook';
    public const CATEGORY_UNIFORM = 'uniform';
    public const CATEGORY_BACKPACK = 'backpack';
    public const CATEGORY_EQUIPMENT = 'equipment';
    public const CATEGORY_GIFT = 'gift';
    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_BOOK => [
            'label' => 'Sách & Giáo trình',
            'color' => 'indigo',
            'icon' => 'menu_book',
        ],
        self::CATEGORY_WORKBOOK => [
            'label' => 'Sách bài tập',
            'color' => 'blue',
            'icon' => 'edit_note',
        ],
        self::CATEGORY_UNIFORM => [
            'label' => 'Đồng phục học viên',
            'color' => 'emerald',
            'icon' => 'apparel',
        ],
        self::CATEGORY_BACKPACK => [
            'label' => 'Balo & Túi xách',
            'color' => 'amber',
            'icon' => 'backpack',
        ],
        self::CATEGORY_EQUIPMENT => [
            'label' => 'Thiết bị & Giáo cụ',
            'color' => 'purple',
            'icon' => 'devices',
        ],
        self::CATEGORY_GIFT => [
            'label' => 'Học phẩm & Quà tặng',
            'color' => 'rose',
            'icon' => 'card_giftcard',
        ],
        self::CATEGORY_OTHER => [
            'label' => 'Hàng hóa khác',
            'color' => 'gray',
            'icon' => 'category',
        ],
    ];

    protected $fillable = [
        'code',
        'name',
        'category',
        'unit',
        'price',
        'cost_price',
        'stock_quantity',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCategory($query, ?string $category)
    {
        if ($category && array_key_exists($category, self::CATEGORIES)) {
            return $query->where('category', $category);
        }
        return $query;
    }

    public function scopeSearch($query, ?string $keyword)
    {
        if (!empty($keyword)) {
            $term = '%' . trim($keyword) . '%';
            return $query->where(function ($q) use ($term) {
                $q->where('name', 'LIKE', $term)
                  ->orWhere('code', 'LIKE', $term)
                  ->orWhere('description', 'LIKE', $term);
            });
        }
        return $query;
    }

    public function getCategoryMetaAttribute(): array
    {
        return self::CATEGORIES[$this->category] ?? self::CATEGORIES[self::CATEGORY_OTHER];
    }

    public function getCategoryLabelAttribute(): string
    {
        return $this->category_meta['label'] ?? 'Khác';
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 0, ',', '.') . ' đ';
    }
}
