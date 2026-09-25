<?php

namespace App\Models\Concerns;

use App\Support\Audit;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Ghi nhật ký trước/sau (old/attributes) khi model được tạo, sửa, xóa.
 * Cấu hình phân hệ và trường được hoàn tác nằm ở App\Support\Audit::MODELS.
 * Giá trị nhạy cảm được che trong AppServiceProvider (SensitiveData) trước khi lưu.
 */
trait AuditsChanges
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(Audit::logNameFor($this))
            ->logFillable()
            ->logExcept(Audit::NEVER_LOG)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(Audit::IGNORE_ONLY)
            ->setDescriptionForEvent(fn (string $event) => Audit::descriptionFor($this, $event));
    }
}
