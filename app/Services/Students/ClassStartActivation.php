<?php

namespace App\Services\Students;

use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;

/**
 * Học viên "Chờ khai giảng" → "Đang học" khi đã hoàn tất nhập học (lượt ghi danh "completed": xác nhận chính thức
 * từ CRM hoặc bàn giao đủ ở màn Học viên) vào một lớp đã khai giảng (lớp đang hoạt động, tới ngày bắt đầu).
 * Gọi ngay khi xác nhận / bàn giao xong, và chạy hằng ngày (students:start-studying) cho lớp khai giảng sau đó.
 */
class ClassStartActivation
{
    public static function classHasStarted(?ClassModel $class): bool
    {
        return $class !== null && $class->status === 'active'
            && (! $class->start_date || $class->start_date->copy()->startOfDay()->lte(today()));
    }

    /** Sau khi một lượt ghi danh hoàn tất: chuyển học viên sang Đang học nếu lớp đã khai giảng. */
    public function forEnrollment(ClassEnrollment $enrollment): bool
    {
        $student = $enrollment->student;
        if (! $student || $student->status !== Student::INITIAL_STATUS || $enrollment->status !== 'completed'
            || ! self::classHasStarted($enrollment->classModel)) {
            return false;
        }

        $student->update(['status' => 'studying']);

        return true;
    }

    /** Học viên Chờ khai giảng có lượt ghi danh hoàn tất ở lớp đã khai giảng. */
    public function dueStudents(): Builder
    {
        return Student::query()
            ->where('status', Student::INITIAL_STATUS)
            ->whereHas('enrollments', fn (Builder $enrollment) => $enrollment
                ->where('status', 'completed')
                ->whereHas('classModel', fn (Builder $class) => $class
                    ->where('status', 'active')
                    ->where(fn (Builder $date) => $date->whereNull('start_date')->orWhereDate('start_date', '<=', today()))));
    }

    /** @return int số học viên đã chuyển sang Đang học */
    public function run(): int
    {
        $count = 0;
        $this->dueStudents()->each(function (Student $student) use (&$count) {
            $student->update(['status' => 'studying']);
            $count++;
        });

        return $count;
    }
}
