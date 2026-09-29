<?php

namespace App\Services\Crm;

use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\PlacementTestSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Tìm buổi học thử cho khách trước Chốt: mọi lớp đang mở khớp trình độ khách đăng ký
 * (khóa quan tâm / lớp xếp sau test), mỗi lớp kèm các buổi trong 7 ngày tới (gần nhất trước).
 * Chỉ để đặt học thử — không bao giờ tạo ghi danh chính thức (xếp lớp chính thức đi qua WaitingLeadPlacement sau Chốt).
 */
class TrialSlotFinder
{
    public const WINDOW_DAYS = 7;

    public const LEVEL_KEYWORDS = ['PRE STARTERS', 'STARTERS', 'MOVERS', 'FLYERS', 'FAM 0', 'FAM 1', 'FAM 2', 'KET', 'PET', 'IELTS'];

    /**
     * @return array{level: ?string, filtered: bool, classes: Collection<int, array{class: ClassModel, sessions: Collection<int, ClassSession>}>}
     */
    public function find(CrmCustomer $customer, ?PlacementTestSubmission $submission = null): array
    {
        $keywords = self::levelKeywords($customer, $submission);
        $courseId = $this->registeredCourseId($customer);
        $filtered = $keywords !== [] || $courseId !== null;

        $classes = ClassModel::query()
            ->with(['course', 'teacher', 'branch'])
            ->whereIn('status', ['active', 'upcoming'])
            ->when($customer->branch_id, fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->orderBy('name')
            ->get()
            ->when($filtered, fn (Collection $all) => $all->filter(fn (ClassModel $class) => ($courseId && (int) $class->course_id === $courseId)
                || self::matchesKeywords($class, $keywords)))
            ->values();

        $now = now();
        $sessions = ClassSession::query()
            ->with('teacher')
            ->whereIn('class_id', $classes->pluck('id'))
            ->where('status', 'scheduled')
            ->whereDate('date', '>=', $now->toDateString())
            ->whereDate('date', '<=', $now->copy()->addDays(self::WINDOW_DAYS)->toDateString())
            ->orderBy('date')->orderBy('start_time')
            ->get()
            // Buổi hôm nay đã kết thúc thì không còn đặt được.
            ->reject(fn (ClassSession $session) => $session->date->isToday() && $session->end_time
                && $session->date->copy()->setTimeFrom($session->end_time)->lte($now))
            ->groupBy('class_id');

        $rows = $classes->map(fn (ClassModel $class) => [
            'class' => $class,
            'sessions' => $sessions->get($class->id, collect())->values(),
        ])
            // Lớp có buổi gần nhất lên đầu; lớp không có buổi trong 7 ngày xuống cuối.
            ->sortBy(fn (array $row) => $row['sessions']->isEmpty()
                ? '9'
                : '0'.$row['sessions']->first()->date->format('Y-m-d').($row['sessions']->first()->start_time?->format('H:i') ?? ''))
            ->values();

        return [
            'level' => self::levelLabel($customer, $submission),
            'filtered' => $filtered,
            'classes' => $rows,
        ];
    }

    /** Trình độ khách hiển thị trên modal: khóa đăng ký, kèm lớp xếp sau test nếu có. */
    public static function levelLabel(CrmCustomer $customer, ?PlacementTestSubmission $submission): ?string
    {
        $parts = array_values(array_unique(array_filter([
            filled($customer->course_interest) ? trim($customer->course_interest) : null,
            $submission?->finalClass(),
        ])));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Từ khóa trình độ của khách: khóa quan tâm (đăng ký) + lớp xếp sau test (vd "STARTERS (FAM 1 …)").
     *
     * @return array<int, string>
     */
    public static function levelKeywords(CrmCustomer $customer, ?PlacementTestSubmission $submission): array
    {
        $source = Str::upper(trim(($submission?->finalClass() ?? '').' '.($customer->course_interest ?? '')));
        if ($source === '') {
            return [];
        }

        $found = collect(self::LEVEL_KEYWORDS)->filter(fn (string $keyword) => str_contains($source, $keyword));

        // "PRE STARTERS" chứa "STARTERS": chỉ giữ từ khóa dài nhất để lớp Starters không bị coi là khớp Pre Starters.
        return $found->reject(fn (string $keyword) => $found->contains(fn (string $other) => $other !== $keyword && str_contains($other, $keyword)))
            ->values()->all();
    }

    /** @param  array<int, string>  $keywords */
    public static function matchesKeywords(ClassModel $class, array $keywords): bool
    {
        if ($keywords === []) {
            return false;
        }
        // Chỉ so tên lớp / trình độ lớp / tên khóa: nhóm trình độ của khóa (vd "Starters (Pre-A1)" gắn cho Pre Starters) quá rộng.
        $haystack = Str::upper(implode(' ', array_filter([$class->name, $class->level, $class->course?->name])));
        // Tránh lớp "PRE STARTERS" bị coi là khớp "STARTERS".
        $withoutPre = str_replace('PRE STARTERS', '', $haystack);

        return collect($keywords)->contains(fn (string $k) => str_contains($k === 'STARTERS' ? $withoutPre : $haystack, $k));
    }

    /** Khóa khách đăng ký, khi "Khóa học quan tâm" trùng đúng tên một khóa. */
    private function registeredCourseId(CrmCustomer $customer): ?int
    {
        if (blank($customer->course_interest)) {
            return null;
        }

        $id = Course::query()->whereRaw('LOWER(name) = ?', [Str::lower(trim($customer->course_interest))])->value('id');

        return $id ? (int) $id : null;
    }
}
