<?php

namespace App\Services\TeachingShifts;

use App\Models\PayrollPeriod;
use App\Models\Penalty;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TimesheetSoftDelete
{
    public function softDelete(TeacherTimesheet $row, User $actor, string $reason): Penalty
    {
        return DB::transaction(function () use ($row, $actor, $reason) {
            $locked = TeacherTimesheet::withSoftDeleted()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->payroll_locked || PayrollPeriod::isLockedFor($locked->teaching_date), 409,
                'Kỳ lương đã chốt, không xóa mềm được công');
            abort_if($locked->reconcile_status === TeacherTimesheet::RECONCILE_CLOSED
                || ($locked->reconcile_status === null && $locked->status === 'valid'), 409,
                'Dòng đã chốt — cần Admin mở khóa trước khi xóa');
            abort_if($locked->bi_xoa_boi_hoc_vu, 409, 'Học vụ đã xóa công ca này, liên hệ Học vụ');

            $locked->update([
                'bi_xoa_boi_hoc_vu' => true,
                'soft_deleted_by' => $actor->id,
                'soft_deleted_at' => now(),
                'soft_delete_reason' => $reason,
            ]);

            return Penalty::create([
                'code' => Penalty::generateCode(),
                'user_id' => $locked->user_id,
                'class_id' => $locked->class_id,
                'violation_type' => 'Chấm công sai: công ca bị Học vụ xóa',
                'error_category' => 'operations',
                'violation_date' => $locked->teaching_date->toDateString(),
                'violation_at' => now(),
                'amount' => 0,
                'reporter_id' => $actor->id,
                'status' => 'pending',
                'notes' => $reason.' — ca ngày '.$locked->teaching_date->format('d/m/Y').' '.($locked->scheduled_time ?? $locked->checkin_time ?? ''),
            ]);
        });
    }
}
