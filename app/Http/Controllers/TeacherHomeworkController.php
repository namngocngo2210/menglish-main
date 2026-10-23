<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Services\Homework\HomeworkStats;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Cổng GV — Bài nộp homework 3 giỏ (docs/kho-syllabus.md §10.2): bài nộp theo bài tập × học viên × giỏ, đánh dấu "Đã xem";
 * tỉ lệ nộp theo giỏ × học sinh và theo lớp trong khoảng ngày. Lớp GV dạy / trợ giảng / GVNN (quyền homework.grade).
 */
class TeacherHomeworkController extends Controller
{
    public function __construct(private readonly HomeworkStats $stats) {}

    public function index(Request $request, int $class)
    {
        abort_unless($request->user()->can('homework.grade'), 403);
        $classModel = $this->classes($request)->find($class, ['id', 'name']);
        abort_unless($classModel, 404);
        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = isset($range['to']) ? Carbon::parse($range['to']) : today();
        $from = isset($range['from']) ? Carbon::parse($range['from']) : $to->copy()->subDays(30);

        $homeworks = $this->stats->homeworks($classModel, $from, $to)->load('submissions');
        $students = $classModel->rosterStudents();

        return Inertia::render('Teacher/HomeworkSubmissions', [
            'classroom' => ['id' => $classModel->id, 'name' => $classModel->name],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'baskets' => collect(HomeworkSubmission::BASKETS)->map(fn (array $b, string $key) => ['key' => $key, 'label' => $b[0], 'icon' => $b[1]])->values(),
            'stats' => $this->stats->forClass($classModel, $from, $to),
            'homeworks' => $homeworks->map(fn (Homework $hw) => [
                'id' => $hw->id,
                'title' => $hw->title,
                'created_at' => $hw->created_at->toDateString(),
                'due_date' => $hw->due_date?->toDateString(),
                'baskets' => $hw->baskets(),
                'rows' => $students->map(fn ($student) => [
                    'id' => $student->id,
                    'student' => $student->name,
                    'baskets' => collect($hw->baskets())->mapWithKeys(function (string $basket) use ($hw, $student) {
                        $sub = $hw->submissions->firstWhere(fn (HomeworkSubmission $s) => $s->student_id === $student->id && $s->basket === $basket);

                        return [$basket => $sub ? [
                            'id' => $sub->id,
                            'status' => $sub->status,
                            'submitted_at' => $sub->submitted_at->toIso8601String(),
                            'original_name' => $sub->original_name,
                            'note' => $sub->note,
                            'file_url' => $sub->file_path ? route('portal.student.homework-file', $sub->id) : null,
                        ] : null];
                    })->all(),
                ])->values(),
            ])->values(),
        ]);
    }

    /** Đánh dấu "Đã xem" bài nộp của lớp mình. */
    public function review(Request $request, int $submission)
    {
        abort_unless($request->user()->can('homework.grade'), 403);
        $row = HomeworkSubmission::with('homework')->findOrFail($submission);
        abort_unless($row->homework && $this->classes($request)->whereKey($row->homework->class_id)->exists(), 404);
        $row->update(['status' => HomeworkSubmission::STATUS_REVIEWED, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        return redirect()->back()->with('success', 'Đã đánh dấu đã xem bài nộp.');
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
