<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\ClassSession;
use App\Models\PayrollPeriod;
use App\Models\StudentAttendance;
use App\Models\SupportSession;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hoàn thành / hủy buổi bổ trợ — một luồng chung cho nút "Đã bổ trợ xong" và điểm danh buổi phụ đạo (chủ dự án 28/10/2026):
 *  - Hoàn thành (nút hoặc điểm danh Có mặt / Đi muộn): buổi "Hoàn thành" + nhận xét, công 1-1 chờ duyệt, có bản điểm danh
 *    Có mặt của học sinh, báo người xếp lịch.
 *  - Học sinh vắng buổi bổ trợ (điểm danh Vắng / Vắng có phép) hoặc Học vụ hủy: buổi "Đã hủy" + lý do, dòng gốc quay lại
 *    danh sách chờ xếp buổi (ClassReportStudentSupport::supportSession bỏ qua buổi đã hủy), báo Học vụ xếp lại.
 */
class SupportSessionService
{
    /** Lý do hủy tự ghi khi học sinh vắng buổi bổ trợ (nhận ra để khôi phục nếu điểm danh được sửa lại thành có mặt). */
    public const ABSENT_REASON_PREFIX = 'Học sinh vắng buổi bổ trợ';

    public function complete(SupportSession $support, string $note, ?User $actor, bool $recordAttendance = true): void
    {
        DB::transaction(function () use ($support, $note, $actor, $recordAttendance) {
            $support->update(['status' => 'completed', 'completed_at' => now(), 'completion_note' => $note,
                'cancel_reason' => null, 'cancelled_at' => null]);
            $support->classSession?->update(['status' => 'completed']);
            // Kỳ lương đã khóa (điểm danh sửa muộn): không ghi thêm công vào kỳ đã chốt.
            if (! PayrollPeriod::isLockedFor($support->session_date)) {
                $minutes = Carbon::parse($support->start_time)->diffInMinutes(Carbon::parse($support->end_time));
                TeacherTimesheet::updateOrCreate(
                    ['user_id' => $support->teacher_id, 'class_session_id' => $support->class_session_id],
                    [
                        'class_id' => $support->class_id,
                        'teaching_date' => $support->session_date,
                        'scheduled_time' => $support->start_time.'-'.$support->end_time,
                        'hours' => max(.5, $minutes / 60),
                        'type' => '1on1',
                        'status' => 'pending_review',
                        'notes' => 'Phụ đạo học viên #'.$support->student_id,
                    ]
                );
            }
            // Bấm "Đã bổ trợ xong" mà chưa điểm danh: ghi luôn Có mặt để chuyên cần / hồ sơ học sinh có buổi này.
            if ($recordAttendance && $support->class_session_id
                && ! StudentAttendance::where('class_session_id', $support->class_session_id)->where('student_id', $support->student_id)->exists()) {
                StudentAttendance::create([
                    'class_id' => $support->class_id,
                    'class_session_id' => $support->class_session_id,
                    'student_id' => $support->student_id,
                    'user_id' => $support->teacher_id,
                    'recorded_by' => $actor?->id ?? $support->teacher_id,
                    'session_date' => $support->session_date->toDateString(),
                    'status' => 'present',
                    'review_status' => 'pending_review',
                    'note' => Str::limit($note, 500, ''),
                ]);
            }
        });

        if ($support->scheduled_by && (int) $support->scheduled_by !== (int) $actor?->id) {
            $support->loadMissing(['student:id,name', 'classModel:id,name']);
            AdminNotification::notifyUser($support->scheduled_by, 'support_session_done', 'Đã bổ trợ xong',
                ($support->student?->name ?? 'Học sinh').' · '.($support->classModel?->name ?? '').': '.Str::limit($note, 120),
                route('tasks.support-sessions', ['tab' => 'sessions']), ['support_session_id' => $support->id]);
        }
    }

    /**
     * Hủy buổi bổ trợ: Học vụ hủy (buổi chưa diễn ra → bỏ buổi trên lịch, trả chỗ người dạy / phòng) hoặc học sinh vắng
     * (buổi đã diễn ra, giữ buổi trên lịch). Công 1-1 chưa duyệt của buổi bị gỡ.
     */
    public function cancel(SupportSession $support, string $reason, ?User $actor, bool $studentAbsent = false): void
    {
        DB::transaction(function () use ($support, $reason) {
            $support->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_at' => now()]);
            if ($support->classSession && ! $support->classSession->attendances()->exists()) {
                $support->classSession->update(['status' => 'cancelled']);
            }
            if ($support->class_session_id) {
                TeacherTimesheet::where('class_session_id', $support->class_session_id)->where('user_id', $support->teacher_id)
                    ->where('status', 'pending_review')->delete();
            }
        });

        $support->loadMissing(['student:id,name', 'classModel:id,name']);
        $who = ($support->student?->name ?? 'Học sinh').' · '.($support->classModel?->name ?? '').' · '.$support->session_date->format('d/m/Y');
        if ($studentAbsent) {
            // Học sinh vắng: Học vụ (người xếp lịch) cần xếp lại — dòng đã quay lại danh sách chờ.
            AdminNotification::notifyUser($support->scheduled_by, 'support_session_absent', 'Học sinh vắng buổi bổ trợ — cần xếp lại',
                $who.': '.Str::limit($reason, 120), route('tasks.support-sessions'), ['support_session_id' => $support->id]);
        } elseif ((int) $support->teacher_id !== (int) $actor?->id) {
            AdminNotification::notifyUser($support->teacher_id, 'support_session_cancelled', 'Buổi bổ trợ đã hủy',
                $who.': '.Str::limit($reason, 120), route('tasks.support-sessions', ['tab' => 'sessions', 'mine' => 1]), ['support_session_id' => $support->id]);
        }
    }

    /**
     * Sau khi lưu điểm danh buổi phụ đạo: Có mặt / Đi muộn → hoàn thành buổi bổ trợ (ghi chú điểm danh = nhận xét);
     * Vắng / Vắng có phép → hủy buổi, dòng gốc quay lại danh sách chờ để Học vụ xếp lại.
     */
    public function syncFromAttendance(ClassSession $session, ?User $actor): void
    {
        if ($session->type !== ClassSession::TYPE_SUPPORT) {
            return;
        }
        $support = SupportSession::with('classSession')->where('class_session_id', $session->id)->latest('id')->first();
        $attendance = $support
            ? StudentAttendance::where('class_session_id', $session->id)->where('student_id', $support->student_id)->first()
            : null;
        if (! $attendance) {
            return;
        }
        $note = trim((string) $attendance->note);

        if (in_array($attendance->status, ['present', 'late'], true)) {
            $absentCancelled = $support->status === 'cancelled' && str_starts_with((string) $support->cancel_reason, self::ABSENT_REASON_PREFIX);
            // Sửa điểm danh vắng → có mặt: khôi phục buổi nếu dòng gốc chưa được xếp buổi mới.
            $replaced = $absentCancelled && $support->supportItem()->whereHas('supportSession')->exists();
            if ($support->status === 'scheduled' || ($absentCancelled && ! $replaced)) {
                $this->complete($support, $note !== '' ? $note : 'Hoàn thành qua điểm danh buổi phụ đạo.', $actor, recordAttendance: false);
            } elseif ($support->status === 'completed' && $note !== '' && $note !== $support->completion_note) {
                $support->update(['completion_note' => $note]);
            }

            return;
        }

        if (in_array($attendance->status, SupportListService::ABSENT_STATUSES, true) && $support->status !== 'cancelled') {
            $this->cancel($support, self::ABSENT_REASON_PREFIX.($note !== '' ? ': '.$note : '.'), $actor, studentAbsent: true);
        }
    }
}
