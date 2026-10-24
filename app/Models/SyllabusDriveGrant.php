<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Quyền xem 1 file Google Drive cho 1 email do hệ thống quản lý (docs/kho-syllabus.md §7.4). */
class SyllabusDriveGrant extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_GRANTED = 'granted';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Đang chờ mở',
        self::STATUS_GRANTED => 'Đã mở quyền xem',
        self::STATUS_FAILED => 'Lỗi',
        self::STATUS_REVOKED => 'Đã đóng',
    ];

    /** Thử lại tối đa trước khi chờ "Đồng bộ lại" thủ công. */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'email', 'file_id', 'file_kind', 'permission_id', 'status', 'pre_existing', 'error', 'attempts',
        'granted_at', 'revoked_at', 'failure_notified_at', 'grant_requested_at',
    ];

    protected $casts = [
        'pre_existing' => 'boolean',
        'attempts' => 'integer',
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'failure_notified_at' => 'datetime',
        'grant_requested_at' => 'datetime',
    ];

    public static function key(string $email, string $fileId): string
    {
        return mb_strtolower($email).'|'.$fileId;
    }
}
