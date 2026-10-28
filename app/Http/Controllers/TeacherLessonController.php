<?php

namespace App\Http\Controllers;

use App\Models\ClassLessonProgress;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusDocument;
use App\Models\SyllabusLesson;
use App\Models\SyllabusLessonSlide;
use App\Models\User;
use App\Services\Syllabus\ClassSyllabus;
use App\Services\Syllabus\ConfirmDeadline;
use App\Services\Syllabus\LessonTaught;
use App\Support\Ui;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Cổng GV — "Bài giảng hôm nay" (docs/kho-syllabus.md §8.3): GV chỉ xem buổi syllabus hiện tại của lớp có buổi học hôm nay,
 * bấm "Đã dạy" (ghi chú chậm / nhanh, hoặc dùng 1 buổi giãn đã duyệt) — tiến độ chặng chỉ chạy khi bấm (quyết định 4).
 */
class TeacherLessonController extends Controller
{
    public function __construct(private readonly ClassSyllabus $syllabus) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $classes = $this->classesOf($user, $request->filled('class') ? $request->integer('class') : null);
        // Mặc định: lớp có buổi học hôm nay.
        $class = $request->filled('class') ? $classes->firstWhere('id', $request->integer('class'))
            : ($classes->first(fn (ClassModel $c) => $this->todaySession($c) !== null) ?? $classes->first());
        abort_if($request->filled('class') && ! $class, 404);

        $assignment = $class ? SyllabusAssignment::with(['stage', 'curriculum:id,title'])->open()->where('class_id', $class->id)->first() : null;
        $timeline = $assignment ? $this->syllabus->timeline($assignment) : null;
        $today = $class ? $this->todaySession($class) : null;
        $taughtRow = $today && $timeline ? $timeline['rows']->firstWhere('taught_session_id', $today->id) : null;
        $todayMarked = $today && ClassLessonProgress::where('class_session_id', $today->id)->where('syllabus_assignment_id', $assignment?->id)->exists();
        $row = $today && $timeline ? ($taughtRow ?? ($todayMarked ? null : $timeline['current'])) : null;
        // Chặng chưa XÁC NHẬN thì chưa được bấm "Đã dạy" (docs/luong-giao-vien.md); người xác nhận được thấy nút ngay tại đây.
        $confirmed = $assignment?->isConfirmed() ?? false;
        $canMark = $assignment && $confirmed && $this->canMark($user, $class, $assignment, $today);

        return Inertia::render('Teacher/LessonToday', [
            'classes' => Ui::options($classes, fn (ClassModel $c) => $c->name.' ('.$c->code.')'),
            'classId' => $class?->id,
            'assignment' => $assignment ? [
                'stage_label' => $assignment->stage?->label ?? $assignment->stage_name,
                'curriculum' => $assignment->curriculum?->title,
                'taught' => $timeline['taught'],
                'total' => $timeline['rows']->count(),
                'extra_left' => $timeline['extra_left'],
            ] : null,
            'session' => $today ? ['id' => $today->id, 'time' => $today->start_time?->format('H:i').' – '.$today->end_time?->format('H:i')] : null,
            'lesson' => $row ? $this->lessonPayload($row, $class, $user) : null,
            'taught_today' => $todayMarked,
            'finished' => $timeline && $timeline['current'] === null,
            // Nộp báo cáo buổi = ghi nhận "Đã dạy" (chủ dự án 27/10/2026): mỗi buổi chưa ghi nhận dẫn tới báo cáo buổi đó.
            'unmarked' => $timeline ? $timeline['unmarked_sessions']->map(fn (ClassSession $s) => ['id' => $s->id, 'date' => $s->date->toDateString(),
                'report_url' => route('classes.session-report', ['id' => $class->id, 'session' => $s->id])])->values() : [],
            // Dạy hết buổi của chặng: buổi còn lại ghi là buổi ôn / Big Test (không cần buổi giãn).
            'can_mark' => $canMark && $today !== null && ! $todayMarked,
            'can_mark_past' => $assignment && $confirmed && $this->canMark($user, $class, $assignment, null),
            'report_url' => $today ? route('classes.session-report', ['id' => $class->id, 'session' => $today->id]) : null,
            'confirmed' => $confirmed,
            'confirm_url' => $assignment && ! $confirmed && $assignment->canConfirm($user) ? route('syllabus.assignments.read', $assignment->id) : null,
            'confirm_due' => $assignment && ! $confirmed ? app(ConfirmDeadline::class)->dueAt($assignment)?->format('H:i d/m/Y') : null,
        ]);
    }

    public function markTaught(Request $request, int $class)
    {
        $user = $request->user();
        $classModel = ClassModel::visibleTo($user)->findOrFail($class);
        $assignment = SyllabusAssignment::open()->where('class_id', $classModel->id)->firstOrFail();
        $validated = $request->validate([
            'class_session_id' => ['required', 'integer'],
            'pace_note' => ['nullable', 'string', 'max:1000'],
            'use_extra' => ['nullable', 'boolean'],
        ]);
        $session = ClassSession::where('class_id', $classModel->id)->whereKey($validated['class_session_id'])
            ->whereIn('type', [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP])->where('status', '!=', 'cancelled')
            ->whereDate('date', '<=', today())->first();
        if (! $session || $session->date->lt(($assignment->start_date ?? $assignment->opened_at)->copy()->startOfDay())) {
            throw ValidationException::withMessages(['class_session_id' => 'Chỉ đánh dấu được buổi học đã / đang diễn ra của chặng lớp đang học.']);
        }
        abort_unless($this->canMark($user, $classModel, $assignment, $session), 403, 'Chỉ GV / GVNN của lớp đánh dấu Đã dạy.');
        $label = app(LessonTaught::class)->mark($classModel, $assignment, $session, $user, $validated['pace_note'] ?? null, ! empty($validated['use_extra']));

        return redirect()->back()->with('status', "Đã đánh dấu Đã dạy {$label} ({$session->date->format('d/m')}).");
    }

    /**
     * Lớp GV dạy (GV chính / GVNN / TG của lớp, hoặc dạy thay buổi hôm nay) đang có chặng mở. Lớp mở thẳng từ thẻ "Chặng đang
     * dạy" ($requested) chỉ cần nằm trong phạm vi xem lớp — màn đó liệt kê mọi lớp xem được (dạy thay buổi trước, Học thuật,
     * GV cũ sau khi lớp đổi GV), không thì nút "Bài giảng" của thẻ báo lỗi. Bấm "Đã dạy" vẫn kiểm tra riêng (canMark).
     */
    private function classesOf(User $user, ?int $requested = null)
    {
        return ClassModel::visibleTo($user)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereIn('id', SyllabusAssignment::open()->whereNotNull('class_id')->select('class_id'))
                    ->where(fn ($q) => $q->where('teacher_id', $user->id)->orWhere('foreign_teacher_id', $user->id)->orWhere('assistant_id', $user->id)
                        ->orWhereHas('sessions', fn ($s) => $s->whereDate('date', today())
                            ->where(fn ($w) => $w->where('teacher_id', $user->id)->orWhere('foreign_teacher_id', $user->id)->orWhere('assistant_id', $user->id)))))
                // Lớp mở thẳng từ thẻ ca dạy / Trang lớp: hiện cả khi lớp chưa được giao chặng (màn báo "chưa giao chặng" thay vì lỗi 404).
                ->when($requested, fn ($q) => $q->orWhere('id', $requested)))
            ->orderBy('name')
            ->get();
    }

    private function todaySession(ClassModel $class): ?ClassSession
    {
        return ClassSession::where('class_id', $class->id)->whereDate('date', today())
            ->whereIn('type', [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP])->where('status', '!=', 'cancelled')
            ->orderBy('start_time')->first();
    }

    /** GV được giao chặng, GV chính, GVNN của lớp, hoặc GV / GVNN dạy thay buổi đó — không phải trợ giảng. */
    private function canMark(User $user, ClassModel $class, SyllabusAssignment $assignment, ?ClassSession $session): bool
    {
        return in_array($user->id, array_filter([$assignment->user_id, $class->teacher_id, $class->foreign_teacher_id,
            $session?->teacher_id, $session?->foreign_teacher_id]), false);
    }

    private function lessonPayload(array $row, ClassModel $class, User $user): array
    {
        /** @var SyllabusLesson $lesson */
        $lesson = $row['lesson'];
        $type = $row['data']['lesson_type'] ?? 'lesson';

        return [
            'id' => $lesson->id,
            'session_no' => $lesson->session_no,
            'unit' => $row['unit_number'] ? "Unit {$row['unit_number']}: {$row['unit_title']}" : null,
            'type_label' => SyllabusLesson::TYPES[$type] ?? SyllabusLesson::TYPES['lesson'],
            'milestone_label' => SyllabusLesson::MILESTONES[$row['data']['notice_milestone']] ?? null,
            'data' => $row['data'],
            'overridden' => $row['overridden'],
            // PPT tải lên của buổi (thay link PPT): mở trang trình chiếu trong app.
            'slides' => SyllabusLessonSlide::payloadsFor($lesson),
            'documents' => SyllabusDocument::visibleTo($user)->where('syllabus_lesson_id', $lesson->id)->orderBy('title')->get()
                ->map(fn (SyllabusDocument $d) => ['id' => $d->id, 'title' => $d->title, 'icon' => $d->icon,
                    'url' => route('syllabus.documents.file', $d->id), 'can_download' => $d->canDownload($user)])->values(),
            // Sản phẩm đầu ra của buổi test = điểm (Sheet 4 câu 11).
            'score_url' => match ($type) {
                'mini_test' => route('teacher.scores', ['classId' => $class->id, 'unit_id' => $lesson->unit_id]),
                'big_test' => route('syllabus.big-tests.results'),
                default => null,
            },
            // Giao bài tập theo syllabus: form giao bài điền sẵn 3 giỏ của buổi (giai đoạn 5).
            'homework_url' => route('teacher.homework', ['classId' => $class->id, 'lesson' => $lesson->id]),
        ];
    }
}
