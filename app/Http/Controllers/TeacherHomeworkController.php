<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Cổng GV — Bài nộp homework 3 giỏ (docs/kho-syllabus.md §10.2). Từ 27/10/2026 xem và chấm ở một màn duy nhất "Bài nộp của lớp"
 * (tab Bài tập theo syllabus, StudentPortalController::teacherSubmissions); URL cũ chuyển hướng về đó.
 * Lớp GV dạy / trợ giảng / GVNN (quyền homework.grade).
 */
class TeacherHomeworkController extends Controller
{
    public function index(Request $request, int $class)
    {
        abort_unless($request->user()->can('homework.grade'), 403);
        abort_unless($this->classes($request)->whereKey($class)->exists(), 404);
        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return redirect()->route('portal.teacher.submissions', array_filter(['classId' => $class, 'type' => 'basket'] + $range));
    }

    /** Chấm bài nộp của lớp mình: điểm, nhận xét (tùy chọn) và đánh dấu "Đã xem". */
    public function review(Request $request, int $submission)
    {
        abort_unless($request->user()->can('homework.grade'), 403);
        $row = HomeworkSubmission::with('homework')->findOrFail($submission);
        abort_unless($row->homework && $this->classes($request)->whereKey($row->homework->class_id)->exists(), 404);
        // Quiz không chấm điểm (TK-2026-0038): chỉ HS nộp + GV xác nhận tùy chọn.
        abort_if($row->basket === 'quizizz', 422, 'Bài Quiz không chấm điểm — dùng "Xác nhận".');
        $data = $request->validate(['score' => ['nullable', 'string', 'max:20'], 'feedback' => ['nullable', 'string', 'max:1000']]);
        $row->update([
            'status' => HomeworkSubmission::STATUS_REVIEWED,
            'score' => filled($data['score'] ?? null) ? trim($data['score']) : null,
            'feedback' => filled($data['feedback'] ?? null) ? $data['feedback'] : null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Đã chấm bài nộp.');
    }

    /**
     * GV xác nhận bài Quiz (tùy chọn, TK-2026-0038): ghi người + giờ xác nhận lên CHÍNH bài nộp của HS (giờ nộp giữ nguyên).
     * HS chưa nộp → tạo bài nộp thay HS (created_by = GV) để hủy xác nhận sau đó xóa lại.
     */
    public function confirmQuiz(Request $request)
    {
        [$homework, $studentId] = $this->quizTarget($request);
        $key = ['homework_id' => $homework->id, 'student_id' => $studentId, 'basket' => 'quizizz'];
        $row = HomeworkSubmission::firstOrNew($key);
        if (! $row->exists) {
            $row->fill(['status' => HomeworkSubmission::STATUS_SUBMITTED, 'submitted_at' => now(), 'created_by' => $request->user()->id]);
        }
        $row->fill(['confirmed_by' => $request->user()->id, 'confirmed_at' => now()])->save();

        return back()->with('success', 'Đã xác nhận bài Quiz.');
    }

    /** Hủy xác nhận: bài do GV tạo hộ → xóa (HS về Chưa nộp); bài HS tự nộp → chỉ xóa xác nhận (HS vẫn Đã nộp). */
    public function cancelQuiz(Request $request)
    {
        [$homework, $studentId] = $this->quizTarget($request);
        $row = HomeworkSubmission::where(['homework_id' => $homework->id, 'student_id' => $studentId, 'basket' => 'quizizz'])->first();
        if (! $row || $row->confirmed_at === null) {
            return back()->with('error', 'Không có xác nhận nào để hủy.');
        }
        if ($row->created_by !== null) {
            $row->delete();
        } else {
            $row->update(['confirmed_by' => null, 'confirmed_at' => null]);
        }

        return back()->with('success', 'Đã hủy xác nhận bài Quiz.');
    }

    /** @return array{0: Homework, 1: int} bài tập có giỏ Quiz của lớp GV phụ trách + học viên thuộc lớp đó. */
    private function quizTarget(Request $request): array
    {
        abort_unless($request->user()->can('homework.grade'), 403);
        $data = $request->validate(['homework_id' => ['required', 'integer'], 'student_id' => ['required', 'integer']]);
        $homework = Homework::findOrFail($data['homework_id']);
        $class = $this->classes($request)->whereKey($homework->class_id)->first();
        abort_unless($class && in_array('quizizz', $homework->baskets(), true), 404);
        abort_unless($class->roster()->whereKey($data['student_id'])->exists(), 404);

        return [$homework, (int) $data['student_id']];
    }

    /** Lớp GV dạy / trợ giảng / GVNN; phạm vi Lớp học "Toàn hệ thống" → mọi lớp (như màn chấm bài nộp cũ). */
    private function classes(Request $request): Builder
    {
        $user = $request->user();

        return ClassModel::query()->where('status', '!=', 'cancelled')
            ->when(! DataScope::isAll($user, 'class'), fn (Builder $q) => $q->where(fn (Builder $c) => $c->where('teacher_id', $user->id)
                ->orWhere('assistant_id', $user->id)->orWhere('foreign_teacher_id', $user->id)));
    }
}
