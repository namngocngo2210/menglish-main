<?php

namespace App\Services\Syllabus;

use App\Models\ClassLessonProgress;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ghi nhận "Đã dạy" một buổi học vào tiến độ chặng (docs/kho-syllabus.md §8.3, quyết định 4: tiến độ chỉ chạy khi GV ghi nhận).
 * Từ 27/10/2026 GV nộp Báo cáo buổi học là ghi nhận Đã dạy (chủ dự án) — người gọi tự kiểm tra quyền.
 */
class LessonTaught
{
    public function __construct(private readonly ClassSyllabus $syllabus) {}

    /** Chặng đang mở của lớp, hoặc null nếu lớp không học theo syllabus. */
    public function openAssignment(ClassModel $class): ?SyllabusAssignment
    {
        return SyllabusAssignment::open()->where('class_id', $class->id)->first();
    }

    public function isMarked(SyllabusAssignment $assignment, ClassSession $session): bool
    {
        return ClassLessonProgress::where('syllabus_assignment_id', $assignment->id)->where('class_session_id', $session->id)->exists();
    }

    /**
     * Đánh dấu buổi đã dạy: bài syllabus kế tiếp, buổi giãn ($useExtra), hoặc buổi ôn / Big Test khi đã dạy hết bài.
     *
     * @return string nhãn buổi vừa ghi nhận
     *
     * @throws ValidationException buổi không hợp lệ / chặng chưa xác nhận / buổi trước chưa ghi nhận / đã ghi nhận
     */
    public function mark(ClassModel $class, SyllabusAssignment $assignment, ClassSession $session, User $user, ?string $paceNote = null, bool $useExtra = false): string
    {
        if ((int) $session->class_id !== (int) $class->id || ! in_array($session->type, [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP], true)
            || $session->status === 'cancelled' || $session->date->isAfter(today())
            || $session->date->lt(($assignment->start_date ?? $assignment->opened_at)->copy()->startOfDay())) {
            throw ValidationException::withMessages(['class_session_id' => 'Chỉ đánh dấu được buổi học đã / đang diễn ra của chặng lớp đang học.']);
        }
        if (! $assignment->isConfirmed()) {
            throw ValidationException::withMessages(['class_session_id' => 'Chặng chưa được XÁC NHẬN — GV bấm ĐỌC & XÁC NHẬN nhận syllabus trước khi ghi nhận Đã dạy.']);
        }

        try {
            $label = DB::transaction(function () use ($assignment, $session, $paceNote, $useExtra, $user) {
                $assignment = SyllabusAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                if ($this->isMarked($assignment, $session)) {
                    throw ValidationException::withMessages(['class_session_id' => 'Buổi này đã được đánh dấu Đã dạy.']);
                }
                $timeline = $this->syllabus->timeline($assignment);
                // Đánh dấu theo thứ tự buổi học: buổi cũ chưa đánh dấu phải làm trước (lịch sử buổi ↔ bài không bị đảo).
                $older = $timeline['unmarked_sessions']->first(fn (ClassSession $s) => $s->id !== $session->id
                    && ($s->date->lt($session->date) || ($s->date->eq($session->date) && $s->start_time < $session->start_time)));
                if ($older) {
                    throw ValidationException::withMessages(['class_session_id' => 'Buổi '.$older->date->format('d/m').' chưa ghi nhận Đã dạy — nộp báo cáo buổi đó trước (theo thứ tự buổi học).']);
                }
                $base = ['syllabus_assignment_id' => $assignment->id, 'class_id' => $assignment->class_id, 'class_session_id' => $session->id,
                    'pace_note' => $paceNote, 'taught_by' => $user->id,
                    // Ngày Đã dạy = ngày buổi học (ghi nhận bù cho buổi đã qua vẫn đúng ngày).
                    'taught_at' => $session->date->copy()->setTimeFromTimeString($session->start_time?->format('H:i:s') ?? '12:00:00')];
                if ($timeline['current'] === null) {
                    ClassLessonProgress::create($base + ['kind' => ClassLessonProgress::KIND_EXTRA]);

                    return 'buổi ôn / Big Test (đã dạy hết buổi của chặng)';
                }
                if ($useExtra) {
                    if ($timeline['extra_left'] < 1) {
                        throw ValidationException::withMessages(['use_extra' => 'Lớp chưa có buổi giãn được duyệt — gửi "Customize syllabus" để Học thuật duyệt.']);
                    }
                    ClassLessonProgress::create($base + ['kind' => ClassLessonProgress::KIND_EXTRA]);

                    return 'buổi giãn tiến độ';
                }
                $current = $timeline['current'];
                ClassLessonProgress::create($base + ['kind' => ClassLessonProgress::KIND_LESSON, 'syllabus_lesson_id' => $current['lesson']->id]);

                return 'Buổi '.$current['lesson']->session_no.': '.$current['data']['title'];
            });
        } catch (QueryException $e) {
            // Chỉ lỗi trùng khóa (bấm đúp / 2 tab) là "đã đánh dấu"; lỗi khác báo đúng.
            if (($e->errorInfo[0] ?? null) !== '23000') {
                throw $e;
            }
            throw ValidationException::withMessages(['class_session_id' => 'Buổi này đã được đánh dấu Đã dạy.']);
        }
        // Tiến độ chạy → mốc ORDER có thể vừa đến hạn: nút ORDER của GV lớp đếm lại ngay.
        SyllabusOrders::forget([$assignment->user_id, $class->teacher_id, $class->foreign_teacher_id]);

        return $label;
    }
}
