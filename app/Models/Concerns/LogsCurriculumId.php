<?php

namespace App\Models\Concerns;

use App\Models\SyllabusCurriculum;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Ghi kèm curriculum_id vào nhật ký thay đổi của giáo trình / chặng / unit / buổi / tài liệu để tab Lịch sử của
 * trang chi tiết giáo trình (Kho syllabus) lọc theo giáo trình.
 */
trait LogsCurriculumId
{
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->properties = $activity->properties->put('curriculum_id', $this instanceof SyllabusCurriculum ? $this->getKey() : $this->curriculum_id);
    }
}
