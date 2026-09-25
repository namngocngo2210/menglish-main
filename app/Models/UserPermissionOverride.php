<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermissionOverride extends Model
{
    use HasFactory;

    public const SCOPE_ALL = 'all';

    public const SCOPE_BRANCH = 'branch';

    public const SCOPE_CLASS = 'class';

    /**
     * Module (và action) đã được kiểm tra theo phạm vi chi nhánh/lớp trong code.
     * Chỉ những cặp này mới cho phép lưu override có phạm vi cụ thể; module
     * khác chỉ nhận override "Toàn hệ thống".
     *  - class.view / class.update / class.delete: ClassModel::scopeVisibleTo()
     *    và ClassModel::userCan() (màn Lớp học, sửa/xóa lớp).
     */
    public const SCOPE_ENFORCED = [
        'class' => ['view', 'update', 'delete'],
    ];

    /** 4 cột chuẩn của ma trận phân quyền (Xem / Thêm / Sửa / Xóa). */
    public const MATRIX_ACTIONS = [
        'view' => 'Xem',
        'create' => 'Thêm',
        'update' => 'Sửa',
        'delete' => 'Xóa',
    ];

    public static function supportsScope(string $module, ?string $action = null): bool
    {
        if (! isset(self::SCOPE_ENFORCED[$module])) {
            return false;
        }

        return $action === null || in_array($action, self::SCOPE_ENFORCED[$module], true);
    }

    protected $fillable = [
        'user_id',
        'module',
        'action',
        'allow',
        'scope_type',
        'scope_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'allow' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
