<?php

namespace App\Services\TeachingShifts;

use App\Models\ClassSession;
use App\Models\PayrollPeriod;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Record missing attendance from the schedule or review an existing row without changing its source or times.
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
            TeacherTimesheet::withSoftDeleted()->where('user_id', $staff->id)->where('class_session_id', $session->id)
                ->where('bi_xoa_boi_hoc_vu', true)->exists() => 'Học vụ đã xóa công ca này (dùng Chấm công thủ công)',
            TeacherTimesheet::where('user_id', $staff->id)->where('class_session_id', $session->id)
                ->where('payroll_locked', true)->exists() => 'kỳ lương đã khóa',
            PayrollPeriod::isLockedFor($session->date) => 'kỳ lương đã khóa',
            default => null,
        };
    }

    /** Ghi / duyệt ca của $staff cho buổi $session theo giờ lịch (gọi sau khi skipReason() trả null). */
    public function confirm(ClassSession $session, User $staff, User $reviewer): TeacherTimesheet
    {
        return DB::transaction(function () use ($session, $staff, $reviewer) {
            $session = ClassSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $rows = TeacherTimesheet::withSoftDeleted()->where('user_id', $staff->id)->where('class_session_id', $session->id)
                ->orderBy('id')->lockForUpdate()->get();
            abort_if($rows->contains(fn (TeacherTimesheet $row) => $row->bi_xoa_boi_hoc_vu), 409,
                'Học vụ đã xóa công ca này (dùng Chấm công thủ công)');
            abort_if(PayrollPeriod::isLockedFor($session->date), 409, 'Kỳ lương đã chốt, không sửa được công');

            $review = ['status' => 'valid', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'rejection_reason' => null];
            $existing = $rows->first()
                ?? TeacherTimesheet::findDuplicate((int) $staff->id, (int) $session->class_id, $session->date->toDateString(), $session->id);

            if ($existing) {
                $existing = TeacherTimesheet::whereKey($existing->id)->lockForUpdate()->firstOrFail();
            }
            abort_if($existing?->payroll_locked, 409, 'Kỳ lương đã chốt, không sửa được công');
            if ($existing && in_array($existing->reconcile_status, [TeacherTimesheet::RECONCILE_NHAP, TeacherTimesheet::RECONCILE_CHO], true)) {
                $review['reconcile_status'] = TeacherTimesheet::RECONCILE_CLOSED;
            }
            if ($existing) {
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

            $timesheet = new TeacherTimesheet;
            $timesheet->fill($attributes)->save();

            return $timesheet;
        });
    }
}
