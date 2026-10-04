<?php

namespace App\Support\Portal;

use App\Models\AcademicRecord;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Thông báo của Cổng Phụ huynh / Học sinh (bản ghi AcademicRecord màn 05_danh_sach_thong_bao).
 * Một nguồn đếm "chưa đọc" dùng chung cho chuông topbar, thanh điều hướng đáy và ô Hộp thư ở App Shell.
 */
final class PortalNotifications
{
    public const SCREEN_KEY = '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao';

    /** Số thông báo chưa đọc của một học viên. */
    public static function unreadCount(?Student $student): int
    {
        return $student ? self::unreadCountFor([$student->id]) : 0;
    }

    /** Tổng chưa đọc của mọi học viên gắn với tài khoản (phụ huynh nhiều con xem chung một chuông). */
    public static function unreadCountForUser(User $user): int
    {
        $studentIds = Student::query()
            ->linkedTo($user)
            ->pluck('id');

        return self::unreadCountFor($studentIds);
    }

    /** @param  iterable<int>|Collection<int, int>  $studentIds */
    private static function unreadCountFor(iterable $studentIds): int
    {
        $ids = collect($studentIds)->map(fn ($id) => (string) $id)->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        return AcademicRecord::where('screen_key', self::SCREEN_KEY)
            ->whereIn('data->student_id', $ids->all())
            ->where('data->unread', true)
            ->count();
    }
}
