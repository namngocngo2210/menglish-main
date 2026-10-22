<?php

namespace App\Services\TeachingShifts;

use App\Models\ClassSession;
use App\Models\PayrollPeriod;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Carbon\Carbon;

/**
 * "Xác nhận theo lịch" (Bảng giờ dạy): Học vụ ghi công cho nhân sự giảng dạy theo giờ buổi học trên lịch — dùng cho
 * xác nhận từng buổi và xác nhận hàng loạt các buổi thiếu công (GV quên check-in vẫn được tính lương).
 * Ca đã check-in / chấm tay → duyệt ca đó; chưa có (hoặc ca cũ bị từ chối) → ca "Xác nhận theo lịch" hợp lệ.
 */
class ScheduleConfirmation
{
    /** Lý do không xác nhận được buổi $session cho $staff (null = xác nhận được). */
    public function skipReason(ClassSession $session, User $staff): ?string
    {
        return match (true) {
            $session->status === 'cancelled' => 'buổi đã hủy',
            ! in_array((int) $staff->id, array_map('intval', [$session->teacher_id, $session->foreign_teacher_id, $session->assistant_id]), true) => 'không phải buổi của nhân sự này',
            $session->date->isAfter(today()) => 'chưa tới ngày học',
            PayrollPeriod::isLockedFor($session->date) => 'kỳ lương đã khóa',
            default => null,
        };
    }

    /** Ghi / duyệt ca của $staff cho buổi $session theo giờ lịch (gọi sau khi skipReason() trả null). */
    public function confirm(ClassSession $session, User $staff, User $reviewer): TeacherTimesheet
    {
        $review = ['status' => 'valid', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'rejection_reason' => null];
        $existing = TeacherTimesheet::where('user_id', $staff->id)->where('class_session_id', $session->id)->first()
            ?? TeacherTimesheet::findDuplicate((int) $staff->id, (int) $session->class_id, $session->date->toDateString(), $session->id);

        if ($existing && $existing->status !== 'invalid') {
            $existing->update($review);

            return $existing;
        }

        $start = $session->start_time?->format('H:i');
        $end = $session->end_time?->format('H:i');
        $attributes = $review + [
            'user_id' => $staff->id,
            'class_id' => $session->class_id,
            'class_session_id' => $session->id,
            'teaching_date' => $session->date->toDateString(),
            'scheduled_time' => $start && $end ? $start.'-'.$end : null,
            'checkin_time' => $start,
            'checkout_time' => $end,
            'hours' => $start && $end ? max(0.5, round(abs(Carbon::parse($start)->diffInMinutes(Carbon::parse($end))) / 60, 2)) : 0,
            'type' => $session->type === ClassSession::TYPE_SUPPORT ? '1on1' : 'regular',
            'source' => TeacherTimesheet::SOURCE_SCHEDULE,
            'notes' => 'Học vụ xác nhận theo lịch buổi học',
        ];

        $timesheet = $existing ?? new TeacherTimesheet;
        $timesheet->fill($attributes)->save();

        return $timesheet;
    }
}
