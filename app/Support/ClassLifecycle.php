<?php

namespace App\Support;

/**
 * Trạng thái lớp (badge + tab lọc trên Danh sách lớp) và các tab của Trang lớp.
 */
final class ClassLifecycle
{
    /** Nhãn + màu badge theo trạng thái lớp (thứ tự = thứ tự chip lọc trên Danh sách lớp). */
    public const STATUSES = [
        'pending_schedule' => ['label' => 'Chờ lịch', 'color' => 'warning'],
        'upcoming' => ['label' => 'Sắp khai giảng', 'color' => 'secondary'],
        'active' => ['label' => 'Đang học', 'color' => 'success'],
        'completed' => ['label' => 'Đã kết thúc', 'color' => 'neutral'],
        'cancelled' => ['label' => 'Đã hủy', 'color' => 'error'],
    ];

    /** Tab con của Trang lớp. */
    public const TABS = [
        'overview' => ['label' => 'Tổng quan', 'icon' => 'dashboard'],
        'students' => ['label' => 'Học viên', 'icon' => 'group'],
        'sessions' => ['label' => 'Buổi học & điểm danh', 'icon' => 'fact_check'],
        'academic' => ['label' => 'Học thuật', 'icon' => 'school'],
    ];

    /** Link cũ tới tab đã gộp → tab mới (Lịch + Điểm danh → Buổi học; Syllabus + Homework → Học thuật; Sự vụ nằm trong báo cáo buổi). */
    public const LEGACY_TABS = [
        'schedule' => 'sessions',
        'attendance' => 'sessions',
        'syllabus' => 'academic',
        'homework' => 'academic',
        'incidents' => 'overview',
    ];

    /** @return array{label: string, color: string} */
    public static function status(?string $status): array
    {
        return self::STATUSES[$status] ?? ['label' => StatusLabel::for((string) $status), 'color' => 'neutral'];
    }
}
