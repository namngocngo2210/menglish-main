<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
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

    /** Lớp GV dạy / trợ giảng / GVNN; phạm vi Lớp học "Toàn hệ thống" → mọi lớp (như màn chấm bài nộp cũ). */
    private function classes(Request $request): Builder
    {
        $user = $request->user();

        return ClassModel::query()->where('status', '!=', 'cancelled')
            ->when(! DataScope::isAll($user, 'class'), fn (Builder $q) => $q->where(fn (Builder $c) => $c->where('teacher_id', $user->id)
                ->orWhere('assistant_id', $user->id)->orWhere('foreign_teacher_id', $user->id)));
    }
}
