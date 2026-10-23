<?php

namespace App\Support\Practice;

use App\Models\Student;
use Carbon\CarbonInterface;

/**
 * Khối học của học viên, tự tính từ năm sinh (năm học mới bắt đầu 1/8):
 * khối = năm bắt đầu năm học − năm sinh − 5 + students.grade_adjust.
 */
class SchoolGrade
{
    public const SCHOOL_YEAR_START_MONTH = 8;

    /** Năm bắt đầu năm học chứa ngày $on (từ tháng 8 → năm hiện tại, ngược lại năm trước). */
    public static function schoolYearStart(CarbonInterface $on): int
    {
        return $on->month >= self::SCHOOL_YEAR_START_MONTH ? $on->year : $on->year - 1;
    }

    /** Khối tính thuần từ ngày sinh (chưa cộng grade_adjust); null khi thiếu dob hoặc ngoài 1–12. */
    public static function baseGrade(?CarbonInterface $dob, CarbonInterface $on): ?int
    {
        if ($dob === null) {
            return null;
        }

        return self::inRange(self::rawBase($dob, $on));
    }

    public static function forStudent(Student $student, ?CarbonInterface $on = null): ?int
    {
        if ($student->dob === null) {
            return null;
        }

        $on ??= now();

        return self::inRange(self::rawBase($student->dob, $on) + (int) $student->grade_adjust);
    }

    /** Giá trị cần lưu vào grade_adjust để học viên đang ở khối $grade (cần có dob). */
    public static function adjustFor(Student $student, int $grade, ?CarbonInterface $on = null): int
    {
        if ($student->dob === null) {
            throw new \InvalidArgumentException('Cần ngày sinh để đặt khối.');
        }

        return $grade - self::rawBase($student->dob, $on ?? now());
    }

    /** Nhãn năm học, vd "2026–2027". */
    public static function schoolYearLabel(CarbonInterface $on): string
    {
        $start = self::schoolYearStart($on);

        return $start.'–'.($start + 1);
    }

    private static function rawBase(CarbonInterface $dob, CarbonInterface $on): int
    {
        return self::schoolYearStart($on) - $dob->year - 5;
    }

    private static function inRange(int $grade): ?int
    {
        return $grade >= 1 && $grade <= 12 ? $grade : null;
    }
}
