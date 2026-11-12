<?php

namespace App\Services\TeachingShifts;

use App\Models\ClassSession;
use App\Models\PayrollPeriod;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Support\DataScopeGuard;
use App\Support\Roles;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AdjacentShiftAttendance
{
    /** @return array{created:int, removed:int} */
    public function run(?CarbonInterface $now = null): array
    {
        $now ??= now();

        return DataScopeGuard::bypass(function () use ($now) {
            $created = 0;
            $removed = 0;
            $today = $now->copy()->startOfDay();
            $sessions = ClassSession::whereDate('date', '>=', $today->copy()->subDay()->toDateString())->whereDate('date', '<=', $today->toDateString())
                ->orderBy('id')->pluck('id');
            foreach ($sessions as $id) {
                $teacherId = null;
                try {
                    $created += DB::transaction(function () use ($id, $now, &$teacherId) {
                        $session = ClassSession::with('classModel')->whereKey($id)->lockForUpdate()->first();
                        if (! $session || ! $this->eligibleSession($session) || $session->type === ClassSession::TYPE_SUPPORT
                            || $now->gt($session->checkinDeadline()) || PayrollPeriod::isLockedFor($session->date)) {
                            return 0;
                        }
                        $teacherId = $this->mainTeacherId($session);
                        if (! $teacherId || ! User::whereKey($teacherId)->where('is_active', true)->role(Roles::TEACHER_PARTTIME)->exists()
                            || ! $this->hasTrigger($session, $teacherId)) {
                            return 0;
                        }
                        if (TeacherTimesheet::withSoftDeleted()->where('user_id', $teacherId)->where('class_session_id', $session->id)->exists()
                            || TeacherTimesheet::findDuplicate($teacherId, (int) $session->class_id, $session->date->toDateString(), $session->id)) {
                            return 0;
                        }
                        TeacherTimesheet::create([
                            'user_id' => $teacherId,
                            'class_id' => $session->class_id,
                            'class_session_id' => $session->id,
                            'teaching_date' => $session->date->toDateString(),
                            'scheduled_time' => $session->start_time->format('H:i').'-'.$session->end_time->format('H:i'),
                            'checkin_time' => $session->start_time->format('H:i'),
                            'hours' => max(0.5, abs(Carbon::parse($session->start_time)->diffInMinutes(Carbon::parse($session->end_time))) / 60),
                            'late_minutes' => 0,
                            'early_leave_minutes' => 0,
                            'late_notified' => false,
                            'type' => 'regular',
                            'source' => TeacherTimesheet::SOURCE_AUTO_ADJACENT,
                            'status' => 'pending_review',
                            'reconcile_status' => TeacherTimesheet::RECONCILE_NHAP,
                            'payroll_locked' => false,
                            'created_by' => null,
                            'bi_xoa_boi_hoc_vu' => false,
                            'notes' => 'Tự động ca liền kề',
                        ]);

                        return 1;
                    });
                } catch (UniqueConstraintViolationException $exception) {
                    // A concurrent check-in/job may have inserted the same pair; never overwrite its attendance.
                    if (! TeacherTimesheet::withSoftDeleted()->where('user_id', $teacherId)->where('class_session_id', $id)->exists()) {
                        throw $exception;
                    }
                }
            }

            $rows = TeacherTimesheet::where('source', TeacherTimesheet::SOURCE_AUTO_ADJACENT)
                ->where('reconcile_status', TeacherTimesheet::RECONCILE_NHAP)->pluck('id');
            foreach ($rows as $id) {
                $removed += DB::transaction(function () use ($id) {
                    $row = TeacherTimesheet::whereKey($id)->lockForUpdate()->first();
                    if (! $row || $row->source !== TeacherTimesheet::SOURCE_AUTO_ADJACENT
                        || $row->reconcile_status !== TeacherTimesheet::RECONCILE_NHAP
                        || $row->payroll_locked || PayrollPeriod::isLockedFor($row->teaching_date)) {
                        return 0;
                    }
                    $session = $row->classSession;
                    if ($session && $this->eligibleSession($session) && $this->mainTeacherId($session) === (int) $row->user_id
                        && $this->hasTrigger($session, (int) $row->user_id)) {
                        return 0;
                    }
                    $row->delete();

                    return 1;
                });
            }

            return compact('created', 'removed');
        });
    }

    private function mainTeacherId(ClassSession $session): ?int
    {
        $id = $session->teacher_id ?? $session->classModel?->teacher_id;

        return $id === null ? null : (int) $id;
    }

    private function eligibleSession(ClassSession $session): bool
    {
        return $session->status !== 'cancelled' && $session->start_time !== null && $session->end_time !== null
            && ! $session->makeupSession()->exists()
            && ! $session->coverRequests()->whereIn('status', ['open', 'approved'])->exists();
    }

    private function hasTrigger(ClassSession $session, int $teacherId): bool
    {
        return ClassSession::with('classModel')->whereDate('date', $session->date)->whereKeyNot($session->id)
            ->get()
            ->contains(fn (ClassSession $previous) => $previous->end_time?->format('H:i:s') === $session->start_time->format('H:i:s')
                && $this->mainTeacherId($previous) === $teacherId
                && $this->eligibleSession($previous)
                && TeacherTimesheet::where('user_id', $teacherId)->where('class_session_id', $previous->id)
                    ->where('source', TeacherTimesheet::SOURCE_CHECKIN)->where('status', '<>', 'invalid')->where('type', '<>', 'sub')->exists());
    }
}
