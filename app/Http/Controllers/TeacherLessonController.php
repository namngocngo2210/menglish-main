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
use App\Services\Syllabus\SyllabusOrders;
use App\Support\Ui;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $classes = $this->classesOf($user);
        // Mặc định: lớp có buổi học hôm nay.
        $class = $request->filled('class') ? $classes->firstWhere('id', $request->integer('class'))
            : ($classes->first(fn (ClassModel $c) => $this->todaySession($c) !== null) ?? $classes->first());
        abort_if($request->filled('class') && ! $class, 404);

        $assignment = $class ? SyllabusAssignment::with(['stage', 'curriculum:id,title'])->open()->where('class_id', $class->id)->first() : null;
        $timeline = $assignment ? $this->syllabus->timeline($assignment) : null;
        $today = $class ? $this->todaySession($class) : null;
        $taughtRow = $today ? $timeline['rows']->firstWhere('taught_session_id', $today->id) : null;
        $todayMarked = $today && ClassLessonProgress::where('class_session_id', $today->id)->where('syllabus_assignment_id', $assignment?->id)->exists();
        $row = $today ? ($taughtRow ?? ($todayMarked ? null : $timeline['current'])) : null;
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
            'unmarked' => $timeline ? $timeline['unmarked_sessions']->map(fn (ClassSession $s) => ['id' => $s->id, 'date' => $s->date->toDateString()])->values() : [],
            // Dạy hết buổi của chặng: buổi còn lại ghi là buổi ôn / Big Test (không cần buổi giãn).
            'can_mark' => $canMark && $today !== null && ! $todayMarked,
            'can_mark_past' => $assignment && $confirmed && $this->canMark($user, $class, $assignment, null),
            'mark_url' => $class ? route('teacher.lesson-today.taught', $class->id) : null,
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
        if (! $assignment->isConfirmed()) {
            throw ValidationException::withMessages(['class_session_id' => 'Chặng chưa được XÁC NHẬN — GV bấm ĐỌC & XÁC NHẬN nhận syllabus trước khi đánh dấu Đã dạy.']);
        }

        try {
            $label = DB::transaction(function () use ($assignment, $session, $validated, $user) {
                $assignment = SyllabusAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                if (ClassLessonProgress::where('syllabus_assignment_id', $assignment->id)->where('class_session_id', $session->id)->exists()) {
                    throw ValidationException::withMessages(['class_session_id' => 'Buổi này đã được đánh dấu Đã dạy.']);
                }
                $timeline = $this->syllabus->timeline($assignment);
                // Đánh dấu theo thứ tự buổi học: buổi cũ chưa đánh dấu phải làm trước (lịch sử buổi ↔ bài không bị đảo).
                $older = $timeline['unmarked_sessions']->first(fn (ClassSession $s) => $s->id !== $session->id
                    && ($s->date->lt($session->date) || ($s->date->eq($session->date) && $s->start_time < $session->start_time)));
                if ($older) {
                    throw ValidationException::withMessages(['class_session_id' => 'Đánh dấu buổi '.$older->date->format('d/m').' trước (theo thứ tự buổi học).']);
                }
                $base = ['syllabus_assignment_id' => $assignment->id, 'class_id' => $assignment->class_id, 'class_session_id' => $session->id,
                    'pace_note' => $validated['pace_note'] ?? null, 'taught_by' => $user->id,
                    // Ngày Đã dạy = ngày buổi học (bấm bù cho buổi đã qua vẫn đúng ngày).
                    'taught_at' => $session->date->copy()->setTimeFromTimeString($session->start_time?->format('H:i:s') ?? '12:00:00')];
                if ($timeline['current'] === null) {
                    ClassLessonProgress::create($base + ['kind' => ClassLessonProgress::KIND_EXTRA]);

                    return 'buổi ôn / Big Test (đã dạy hết buổi của chặng)';
                }
                if (! empty($validated['use_extra'])) {
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
        SyllabusOrders::forget([$assignment->user_id, $classModel->teacher_id, $classModel->foreign_teacher_id]);

        return redirect()->back()->with('status', "Đã đánh dấu Đã dạy {$label} ({$session->date->format('d/m')}).");
    }

    /** Lớp GV dạy (GV chính / GVNN / TG của lớp, hoặc dạy thay buổi hôm nay) đang có chặng mở. */
    private function classesOf(User $user)
    {
        return ClassModel::visibleTo($user)
            ->whereIn('id', SyllabusAssignment::open()->whereNotNull('class_id')->select('class_id'))
            ->where(fn ($q) => $q->where('teacher_id', $user->id)->orWhere('foreign_teacher_id', $user->id)->orWhere('assistant_id', $user->id)
                ->orWhereHas('sessions', fn ($s) => $s->whereDate('date', today())
                    ->where(fn ($w) => $w->where('teacher_id', $user->id)->orWhere('foreign_teacher_id', $user->id)->orWhere('assistant_id', $user->id))))
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
