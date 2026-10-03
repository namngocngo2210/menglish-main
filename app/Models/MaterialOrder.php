<?php

namespace App\Models;

use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Order học liệu (giáo viên đặt, Học vụ / Trưởng Học thuật xử lý).
 *
 * Hạn xử lý (config/material_orders.php):
 *  - Đạo cụ, In ấn: 15:00 ngày hôm trước ngày sử dụng — CM (Học vụ) xử lý.
 *  - Order GVNN: 23:59 ngày N của tháng chứa ngày sử dụng — CM xử lý.
 *  - Học liệu học thuật: như GVNN nhưng Trưởng Học thuật xử lý.
 * Tạo trễ (đã quá hạn lúc tạo) vẫn được, chỉ cảnh báo. Quá hạn mà chưa xử lý → job chuyển "Quá hạn"; vẫn xử lý được (ghi trễ).
 */
class MaterialOrder extends Model
{
    use HasFactory, SoftDeletes;

    public const CATEGORY_PROPS = 'props';

    public const CATEGORY_PRINTING = 'printing';

    public const CATEGORY_FOREIGN_TEACHER = 'foreign_teacher';

    public const CATEGORY_ACADEMIC = 'academic';

    public const CATEGORIES = [
        self::CATEGORY_PROPS => 'Đạo cụ',
        self::CATEGORY_PRINTING => 'In ấn',
        self::CATEGORY_FOREIGN_TEACHER => 'Order GVNN',
        self::CATEGORY_ACADEMIC => 'Học liệu học thuật',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUSES = [
        self::STATUS_PENDING => 'Chờ xử lý',
        self::STATUS_PROCESSING => 'Đang xử lý',
        self::STATUS_DONE => 'Hoàn thành',
        self::STATUS_REJECTED => 'Từ chối',
        self::STATUS_OVERDUE => 'Quá hạn',
    ];

    /** Trạng thái còn chờ xử lý (chưa xong / chưa từ chối). */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_OVERDUE];

    protected $fillable = [
        'code', 'branch_id', 'class_id', 'requester_id', 'category', 'title', 'description', 'quantity',
        'use_date', 'due_at', 'status', 'created_late', 'processed_by', 'processed_at', 'processed_late',
        'processor_note', 'reject_reason',
    ];

    protected function casts(): array
    {
        return [
            'use_date' => 'date',
            'due_at' => 'datetime',
            'processed_at' => 'datetime',
            'created_late' => 'boolean',
            'processed_late' => 'boolean',
            'quantity' => 'integer',
        ];
    }

    /** Hạn xử lý theo loại + ngày sử dụng. */
    public static function computeDueAt(string $category, Carbon|string $useDate): Carbon
    {
        $use = Carbon::parse($useDate)->startOfDay();

        if (in_array($category, [self::CATEGORY_PROPS, self::CATEGORY_PRINTING], true)) {
            [$hour, $minute] = array_map('intval', explode(':', (string) config('material_orders.day_before_cutoff_time', '15:00')));

            return $use->copy()->subDay()->setTime($hour, $minute);
        }

        // GVNN / học thuật: 23:59 ngày N của tháng chứa ngày sử dụng (N không vượt số ngày của tháng).
        $day = min(max(1, (int) config('material_orders.start_of_month_day', 5)), $use->daysInMonth);

        return $use->copy()->day($day)->setTime(23, 59);
    }

    /** Loại do Học vụ (CM) xử lý theo chi nhánh; còn lại do Trưởng Học thuật. */
    public static function isOpsCategory(string $category): bool
    {
        return $category !== self::CATEGORY_ACADEMIC;
    }

    /** Quyền xử lý của loại này. */
    public static function processPermission(string $category): string
    {
        return self::isOpsCategory($category) ? 'material_order.process_ops' : 'material_order.process_academic';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Trạng thái hạn cho badge: ok (Còn hạn) | soon (< 24h) | overdue | null (đã xử lý xong / từ chối). */
    public function deadlineState(?Carbon $now = null): ?string
    {
        if (! $this->isOpen()) {
            return null;
        }
        $now ??= now();
        if ($this->due_at->lt($now)) {
            return 'overdue';
        }

        return $this->due_at->lt($now->copy()->addHours((int) config('material_orders.due_soon_hours', 24))) ? 'soon' : 'ok';
    }

    /** Người này được xử lý order (theo loại; Học vụ chỉ trong chi nhánh được phân). Admin qua Gate::before. */
    public function canBeProcessedBy(?User $user): bool
    {
        if (! $user || ! $user->can(self::processPermission($this->category))) {
            return false;
        }
        if (! self::isOpsCategory($this->category)) {
            return true;
        }

        return DataScope::coversBranch($user, 'material_order', $this->branch_id);
    }

    /** Order mà người dùng được xem: người lập thấy của mình; view_all thấy theo phạm vi (chi nhánh / toàn hệ thống). */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $own = fn (Builder $q) => $q->where('requester_id', $user->id);
        if (! $user->can('material_order.view_all')) {
            return $query->where(fn (Builder $q) => $own($q));
        }

        return DataScope::apply($query, $user, 'material_order', $own, fn (Builder $q, array $ids) => $q->whereIn('branch_id', $ids), true);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function classRoom(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
