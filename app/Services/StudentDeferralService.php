<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kết thúc bảo lưu học viên (Phase 4 — việc còn tồn "hết bảo lưu chưa tự về Đang học").
 *
 * - Tự động: lệnh `students:end-deferrals` chạy hằng ngày, học viên đang "Bảo lưu" có khoản học phí
 *   với `deferred_until` < hôm nay (đã qua ngày cuối bảo lưu) được chuyển về trạng thái học.
 * - Thủ công: nút "Kết thúc bảo lưu" trên hồ sơ học viên (kết thúc sớm hoặc học viên bảo lưu tay).
 *
 * Trạng thái mới: lớp đang học đã khai giảng (start_date ≤ hôm nay) → "Đang học", còn lại → "Chờ khai giảng".
 * Khoản học phí: bỏ đóng băng (số buổi / công nợ), xóa khoảng bảo lưu (lịch sử ghi vào ghi chú),
 * nhắc nợ chạy lại từ hôm nay nếu đang tạm dừng vì bảo lưu. Idempotent: học viên không còn "Bảo lưu"
 * thì bỏ qua; mỗi Học vụ chỉ nhận 1 thông báo cho mỗi (học viên, ngày kết thúc bảo lưu).
 */
class StudentDeferralService
{
    public const NOTIFICATION_TYPE = 'deferral_ended';

    /**
     * Học viên đã hết thời gian bảo lưu tính tới hôm nay.
     *
     * @return Collection<int, Student>
     */
    public function dueStudents(): Collection
    {
        $today = now()->toDateString();

        return Student::query()
            ->where('status', 'deferred')
            ->whereHas('tuition', fn ($q) => $q->whereNotNull('deferred_until')->whereDate('deferred_until', '<', $today))
            ->get();
    }

    /**
     * Kết thúc bảo lưu của học viên. Trả về trạng thái mới, hoặc null nếu học viên không ở trạng thái Bảo lưu.
     */
    public function end(Student $student, ?User $actor = null, bool $automatic = false): ?string
    {
        return DB::transaction(function () use ($student, $actor, $automatic) {
            /** @var Student|null $locked */
            $locked = Student::query()->lockForUpdate()->find($student->id);
            if (! $locked || $locked->status !== 'deferred') {
                return null;
            }

            $locked->load(['currentClass', 'tuition']);
            $newStatus = $this->resumeStatus($locked);
            $stamp = '['.now()->format('d/m/Y H:i').'] ';
            $by = $automatic ? 'hệ thống (hết thời gian bảo lưu)' : ($actor?->name ?? 'hệ thống');

            $tuition = $locked->tuition;
            $deferredUntil = $tuition?->deferred_until?->toDateString();
            if ($tuition) {
                $this->releaseTuition($tuition, $stamp, $by);
            }

            $locked->update([
                'status' => $newStatus,
                'notes' => trim(($locked->notes ? $locked->notes."\n" : '').$stamp
                    .'Kết thúc bảo lưu → '.Student::STATUSES[$newStatus].". Thực hiện bởi {$by}."),
            ]);

            $this->notifyAcademicStaff($locked, $newStatus, $deferredUntil, $automatic, $actor);

            $student->setRawAttributes($locked->getAttributes(), true);

            return $newStatus;
        });
    }

    /**
     * Bỏ đóng băng khoản học phí khi hết bảo lưu. Dùng cả khi người dùng đổi trạng thái tay khỏi "Bảo lưu".
     */
    public function releaseTuition(StudentTuition $tuition, ?string $stamp = null, string $by = 'hệ thống'): void
    {
        if (! $tuition->deferred_from && ! $tuition->deferred_until && $tuition->frozen_remaining_sessions === null && $tuition->frozen_debt_amount === null) {
            return;
        }

        $stamp ??= '['.now()->format('d/m/Y H:i').'] ';
        $today = now()->startOfDay();
        $range = ($tuition->deferred_from?->format('d/m/Y') ?? '?').' – '.($tuition->deferred_until?->format('d/m/Y') ?? '?');

        // Nhắc nợ đang dừng đúng tới ngày học lại do bảo lưu đặt → chạy lại từ hôm nay (không đụng khất nợ dài hơn).
        $deferralResume = $tuition->deferred_until?->copy()->addDay()->startOfDay();
        if ($tuition->reminder_paused_until && $deferralResume
            && $tuition->reminder_paused_until->copy()->startOfDay()->equalTo($deferralResume)
            && $tuition->reminder_paused_until->copy()->startOfDay()->gt($today)) {
            $tuition->reminder_paused_until = $today;
        }

        $tuition->deferred_from = null;
        $tuition->deferred_until = null;
        $tuition->frozen_remaining_sessions = null;
        $tuition->frozen_debt_amount = null;
        $tuition->notes = trim(($tuition->notes ? $tuition->notes."\n" : '').$stamp
            ."Kết thúc bảo lưu ({$range}): bỏ đóng băng số buổi và công nợ. Thực hiện bởi {$by}.");
        $tuition->save();
    }

    private function resumeStatus(Student $student): string
    {
        $class = $student->currentClass;

        return $class && $class->start_date && $class->start_date->copy()->startOfDay()->lte(now()->startOfDay())
            ? 'studying'
            : 'waiting_start';
    }

    private function notifyAcademicStaff(Student $student, string $newStatus, ?string $deferredUntil, bool $automatic, ?User $actor): void
    {
        $branchId = $student->branch_id ?? $student->currentClass?->branch_id;
        $active = fn ($q) => $q->where('is_active', true)->whereNull('locked_at');

        $inBranch = fn (User $user) => $branchId && in_array((int) $branchId, Student::branchIdsFor($user), true);

        $recipients = User::role('academic_staff')->with('branches:id')->where($active)->get()->filter($inBranch);
        if ($recipients->isEmpty()) {
            $recipients = User::role('manager')->with('branches:id')->where($active)->get()->filter($inBranch);
        }
        if ($recipients->isEmpty()) {
            $recipients = User::role(\App\Support\Rbac::SUPER_ADMIN)->where($active)->get();
        }

        $recipients = $recipients->reject(fn (User $user) => $actor && $user->id === $actor->id);
        $key = $deferredUntil ?? now()->toDateString();

        foreach ($recipients as $recipient) {
            $exists = AdminNotification::query()
                ->where('type', self::NOTIFICATION_TYPE)
                ->where('user_id', $recipient->id)
                ->where('data->student_id', $student->id)
                ->where('data->deferred_until', $key)
                ->exists();
            if ($exists) {
                continue;
            }

            AdminNotification::create([
                'user_id' => $recipient->id,
                'type' => self::NOTIFICATION_TYPE,
                'title' => "Học viên {$student->name} kết thúc bảo lưu",
                'message' => ($automatic ? 'Hết thời gian bảo lưu' : 'Bảo lưu được kết thúc sớm')
                    ." — học viên {$student->name} ({$student->code}) đã chuyển sang \"".Student::STATUSES[$newStatus].'". Vui lòng sắp xếp lớp / lịch học lại.',
                'data' => [
                    'student_id' => $student->id,
                    'deferred_until' => $key,
                    'link' => route('students.show', $student->id),
                ],
                'is_read' => false,
            ]);
        }
    }
}
