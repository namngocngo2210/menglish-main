<?php

namespace App\Services\Crm;

use App\Models\ClassModel;
use App\Models\CourseLevel;
use App\Models\CrmCustomer;
use App\Models\PlacementTest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Ghép cấp độ khi xếp lớp: lớp có "Cấp độ" (mã trình độ, classes.level; lớp chưa có thì lấy trình độ của khóa),
 * học viên có khóa đã chốt (trình độ của khóa) và cấp độ test đầu vào (Mẫu giáo, Lớp 1..9 của đề đã làm / được giao).
 * Cấp độ test ứng với trình độ nào cấu hình ở Cấu hình Trình độ ("Cấp độ test đầu vào"); trình độ trùng tên cấp độ
 * (vd trình độ "Lớp 3") tự ghép không cần cấu hình.
 */
class PlacementLevelMatcher
{
    /** @var Collection<int, CourseLevel>|null */
    private ?Collection $levels = null;

    /** Cấp độ test của khách: đề của bài test gần nhất, chưa làm thì đề đang được giao. */
    public function gradeLevelOf(CrmCustomer $customer): ?string
    {
        foreach ([$customer->latestSubmission?->test, $customer->assignedTest] as $test) {
            $grade = $test ? ($test->grade_level ?: PlacementTest::detectGradeLevel($test->code)) : null;
            if ($grade) {
                return $grade;
            }
        }

        return null;
    }

    /** @return list<int> trình độ ứng với một cấp độ test */
    public function levelIdsForGrade(?string $grade): array
    {
        if (! $grade || ! isset(PlacementTest::GRADE_LEVELS[$grade])) {
            return [];
        }
        $names = [self::normalize($grade), self::normalize(PlacementTest::GRADE_LEVELS[$grade])];

        return $this->levels()
            ->filter(fn (CourseLevel $level) => in_array($grade, (array) $level->grade_levels, true)
                || in_array(self::normalize($level->name), $names, true)
                || in_array(self::normalize($level->code), $names, true))
            ->keys()->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Trình độ của lớp: theo "Cấp độ" của lớp, lớp chưa có cấp độ hợp lệ thì theo trình độ của khóa. */
    public function classLevelId(ClassModel $class): ?int
    {
        $code = Str::upper(trim((string) $class->level));
        $level = $code !== '' ? $this->levels()->first(fn (CourseLevel $level) => Str::upper(trim($level->code)) === $code) : null;

        return $level ? (int) $level->id : ($class->course?->course_level_id ? (int) $class->course->course_level_id : null);
    }

    public function classLevelName(ClassModel $class): ?string
    {
        $id = $this->classLevelId($class);

        return $id ? $this->levels()->get($id)?->name : null;
    }

    /**
     * Trình độ lớp nhận học viên đã chốt: trình độ của khóa đã chốt + trình độ ứng với cấp độ test của khách.
     *
     * @return list<int>
     */
    public function targetLevelIds(CrmCustomer $customer): array
    {
        $courseLevelId = $customer->waiting_course_id ? $customer->waitingCourse?->course_level_id : null;

        return array_values(array_unique(array_filter([
            $courseLevelId ? (int) $courseLevelId : null,
            ...$this->levelIdsForGrade($this->gradeLevelOf($customer)),
        ])));
    }

    /** Tên các cấp độ nhận học viên (cho thông báo lỗi / gợi ý). */
    public function targetLevelNames(CrmCustomer $customer): array
    {
        return collect($this->targetLevelIds($customer))->map(fn (int $id) => $this->levels()->get($id)?->name)->filter()->values()->all();
    }

    /**
     * Lớp xếp được cho khách Chờ xếp lớp: đúng khóa đã chốt, hoặc cùng cấp độ (trình độ của khóa đã chốt /
     * cấp độ test). Khách chưa có khóa lẫn cấp độ → không giới hạn.
     */
    public function matchesClosed(CrmCustomer $customer, ClassModel $class): bool
    {
        if ($customer->waiting_course_id && (int) $class->course_id === (int) $customer->waiting_course_id) {
            return true;
        }
        $targets = $this->targetLevelIds($customer);
        if ($targets === []) {
            return ! $customer->waiting_course_id;
        }

        return in_array($this->classLevelId($class), $targets, true);
    }

    /** Lớp đúng cấp độ test của khách (gợi ý ở màn Chốt & Xếp lớp / Xếp học thử). */
    public function matchesGrade(CrmCustomer $customer, ClassModel $class): bool
    {
        $ids = $this->levelIdsForGrade($this->gradeLevelOf($customer));

        return $ids !== [] && in_array($this->classLevelId($class), $ids, true);
    }

    /** @return Collection<int, CourseLevel> */
    private function levels(): Collection
    {
        return $this->levels ??= CourseLevel::query()->get(['id', 'code', 'name', 'grade_levels'])->keyBy('id');
    }

    private static function normalize(?string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii((string) $value)));
    }
}
