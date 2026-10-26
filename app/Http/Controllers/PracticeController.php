<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ResolvesPortalStudent;
use App\Models\PracticeAttempt;
use App\Models\PracticeAttemptItem;
use App\Models\PracticeBlueprint;
use App\Models\PracticeExam;
use App\Models\Student;
use App\Models\User;
use App\Services\Practice\PracticeAttemptProps;
use App\Services\Practice\PracticeAttemptService;
use App\Services\Practice\PracticeException;
use App\Services\Practice\QuestionAvailability;
use App\Support\ExamViolations;
use App\Support\Practice\PracticeCatalog;
use App\Support\Practice\SchoolGrade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Luyện tập ở cổng học viên (docs/luyen-tap-ngan-hang-cau-hoi.md §6): chọn kỳ, làm bài, xem kết quả.
 * Ghi (bắt đầu / lưu đáp án / rời bài / nộp) chỉ chủ hồ sơ (tài khoản học viên / phụ huynh); nhân sự có student.view
 * xem được lịch sử và kết quả của học viên trong phạm vi. Lượt làm ngoài tầm với của người dùng luôn trả 404.
 */
class PracticeController extends Controller
{
    use ResolvesPortalStudent;

    private const HISTORY_PER_PAGE = 10;

    /** Cờ "hết giờ" do trình duyệt gửi được nhận khi tới sớm hơn deadline_at tối đa chừng này giây. */
    private const TIMEOUT_FLAG_TOLERANCE_SECONDS = 5;

    public function __construct(
        private PracticeAttemptService $attempts,
        private PracticeAttemptProps $attemptProps,
        private QuestionAvailability $availability,
    ) {}

    public function index(Request $request, $studentId = null): Response
    {
        [$students, $student] = $this->getActiveStudent($studentId);
        $user = $request->user();

        $grade = $student ? SchoolGrade::forStudent($student) : null;
        $gradeSupported = $grade !== null && in_array($grade, PracticeCatalog::GRADES, true);

        // lượt đang làm còn hạn theo kỳ → nút "Làm tiếp"
        $inProgress = $student
            ? PracticeAttempt::query()
                ->where('student_id', $student->id)
                ->whereNull('exam_id')
                ->where('status', PracticeAttempt::IN_PROGRESS)
                ->where('deadline_at', '>=', now())
                ->orderBy('id')
                ->pluck('id', 'term')
            : collect();

        // Số câu bốc được thật (khung đề ∩ câu đã duyệt), không phải tổng khung: một truy vấn GROUP BY cho cả 4 kỳ.
        $approved = $gradeSupported ? $this->availability->all() : [];
        $terms = collect(PracticeCatalog::TERMS)->map(function (string $label, string $term) use ($grade, $gradeSupported, $inProgress, $approved) {
            $blueprint = $gradeSupported ? PracticeBlueprint::forGradeTerm($grade, $term) : null;
            $drawable = $blueprint ? $this->availability->drawable($blueprint->items ?? [], $approved["{$grade}|{$term}"] ?? []) : null;

            return [
                'key' => $term,
                'label' => $label,
                'questionCount' => $drawable,
                'duration' => $blueprint?->duration_minutes,
                'available' => $drawable > 0,
                'inProgressAttemptId' => $inProgress->get($term),
            ];
        })->values()->all();

        $history = PracticeAttempt::query()
            ->with('exam:id,code')
            ->where('status', PracticeAttempt::SUBMITTED)
            ->when($student, fn ($q) => $q->where('student_id', $student->id), fn ($q) => $q->whereRaw('1 = 0'))
            ->orderByDesc('id')
            ->paginate(self::HISTORY_PER_PAGE)
            ->withQueryString()
            ->through(fn (PracticeAttempt $a) => [
                'id' => $a->id,
                'term_label' => PracticeCatalog::TERMS[$a->term] ?? $a->term,
                'exam_label' => $a->exam?->label(),
                'started_at' => $a->started_at->toIso8601String(),
                'duration_seconds' => $this->attemptProps->durationSeconds($a),
                'score' => (float) $a->score,
                'auto_submitted' => $a->auto_submitted,
                'submit_reason' => $a->submit_reason,
            ]);

        return Inertia::render('Portal/Practice', [
            ...$this->portalProps($students, $student),
            'grade' => $grade,
            'schoolYear' => SchoolGrade::schoolYearLabel(now()),
            'terms' => $terms,
            'exams' => $student && $gradeSupported ? $this->examCards($student, $grade) : [],
            'history' => $history,
            'canStart' => $student !== null && $gradeSupported && $student->isLinkedTo($user),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'term' => ['required_without:exam_id', 'nullable', 'string'],
            'exam_id' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        $student = Student::query()->find($data['student_id']);
        abort_unless($student && $this->canView($user, $student), 404);
        abort_unless($student->isLinkedTo($user), 403, 'Chỉ học viên / phụ huynh của hồ sơ này mới làm bài luyện tập được.');

        try {
            if (isset($data['exam_id'])) {
                $exam = PracticeExam::query()->find($data['exam_id']);
                abort_unless($exam, 404);
                $attempt = $this->attempts->startExam($student, $user, $exam);
            } else {
                $attempt = $this->attempts->start($student, $user, (string) $data['term']);
            }
        } catch (PracticeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.student.practice.attempt', $attempt->id);
    }

    /** Trang làm bài khi lượt đang làm; trang kết quả khi đã nộp (lượt quá hạn được chốt "hết giờ" trước). */
    public function show(Request $request, $attempt): Response|RedirectResponse
    {
        $user = $request->user();
        $attempt = $this->findAttempt($user, $attempt);
        $student = $attempt->student;

        if ($attempt->status === PracticeAttempt::IN_PROGRESS
            && now()->greaterThan($attempt->deadline_at->copy()->addSeconds(PracticeAttemptService::ANSWER_GRACE_SECONDS))) {
            $attempt = $this->attempts->finalize($attempt, 'timeout');
        }

        if ($attempt->status === PracticeAttempt::IN_PROGRESS) {
            // Nhân sự không xem đề đang làm (trang làm bài là của học viên; đáp án chỉ lộ sau khi nộp).
            if (! $student->isLinkedTo($user)) {
                return redirect()->route('portal.student.practice', ['studentId' => $student->id])
                    ->with('info', 'Học viên đang làm bài này — xem được kết quả sau khi nộp.');
            }

            return $this->renderTake($attempt);
        }

        return $this->renderResult($attempt, $student);
    }

    public function answer(Request $request, $attempt): JsonResponse
    {
        $attempt = $this->findAttemptForWrite($request->user(), $attempt);
        $data = $request->validate([
            'position' => ['required', 'integer', 'min:1'],
            'choice' => ['nullable', 'string', 'in:A,B,C,D'],
        ]);

        try {
            $this->attempts->saveAnswer($attempt, (int) $data['position'], $data['choice'] ?? null);
        } catch (PracticeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function violation(Request $request, $attempt): JsonResponse
    {
        $attempt = $this->findAttemptForWrite($request->user(), $attempt);
        $data = $request->validate(['type' => ['required', 'string', 'max:40']]);

        $attempt = $this->attempts->recordViolation($attempt, $data['type']);

        return response()->json([
            'count' => $attempt->violation_count,
            'submitted' => $attempt->status === PracticeAttempt::SUBMITTED,
        ]);
    }

    public function submit(Request $request, $attempt): RedirectResponse
    {
        $attempt = $this->findAttemptForWrite($request->user(), $attempt);
        $request->validate(['timeout' => ['sometimes', 'boolean']]);

        // Hết giờ: nộp sau deadline_at, hoặc đồng hồ trình duyệt về 0 (cờ timeout) sớm hơn server tối đa vài giây
        // (lệch đồng hồ / làm tròn); cờ gửi sớm hơn thế bị bỏ qua → tính là học viên tự nộp.
        $timedOut = now()->greaterThan($attempt->deadline_at)
            || ($request->boolean('timeout')
                && now()->greaterThanOrEqualTo($attempt->deadline_at->copy()->subSeconds(self::TIMEOUT_FLAG_TOLERANCE_SECONDS)));
        $this->attempts->finalize($attempt, $timedOut ? 'timeout' : null);

        return redirect()->route('portal.student.practice.attempt', $attempt->id);
    }

    private function renderTake(PracticeAttempt $attempt): Response
    {
        $items = $attempt->items()->get(['id', 'attempt_id', 'position', 'skill', 'type', 'snapshot', 'chosen']);

        return Inertia::render('Portal/PracticeTake', [
            'attempt' => [
                'id' => $attempt->id,
                'student_id' => $attempt->student_id,
                'term_label' => PracticeCatalog::TERMS[$attempt->term] ?? $attempt->term,
                'exam_label' => $attempt->exam?->label(),
                'grade' => $attempt->grade,
                'remainingSeconds' => (int) max(0, ceil(now()->diffInSeconds($attempt->deadline_at, false))),
                'violationCount' => (int) $attempt->violation_count,
                'maxViolations' => ExamViolations::MAX,
            ],
            // Liệt kê trường cụ thể: snapshot có đáp án, không được gửi xuống khi đang làm.
            'items' => $items->map(fn (PracticeAttemptItem $item) => $this->attemptProps->question($item))->values()->all(),
        ]);
    }

    private function renderResult(PracticeAttempt $attempt, Student $student): Response
    {
        return Inertia::render('Portal/PracticeResult', [
            ...$this->portalProps(collect([$student]), $student),
            'attempt' => $this->attemptProps->result($attempt),
            'items' => $this->attemptProps->reviewItems($attempt),
        ]);
    }

    /**
     * Đề cố định đã mở của khối học viên, theo kỳ rồi mã đề: số câu, thời gian, số lượt đã nộp, điểm cao nhất, lượt đang
     * làm còn hạn (nút "Làm tiếp"). 3 truy vấn.
     *
     * @return list<array<string, mixed>>
     */
    private function examCards(Student $student, int $grade): array
    {
        $exams = PracticeExam::query()->published()->where('grade', $grade)
            ->withCount('questions')
            ->get(['id', 'grade', 'term', 'code', 'title', 'duration_minutes'])
            ->sortBy(fn (PracticeExam $e) => [array_search($e->term, array_keys(PracticeCatalog::TERMS), true), $e->code])
            ->values();
        if ($exams->isEmpty()) {
            return [];
        }

        $stats = PracticeAttempt::query()->toBase()
            ->where('student_id', $student->id)
            ->whereIn('exam_id', $exams->pluck('id'))
            ->where('status', PracticeAttempt::SUBMITTED)
            ->groupBy('exam_id')
            ->selectRaw('exam_id, count(*) as attempts, max(score) as best_score')
            ->get()->keyBy('exam_id');
        $inProgress = PracticeAttempt::query()
            ->where('student_id', $student->id)
            ->whereIn('exam_id', $exams->pluck('id'))
            ->where('status', PracticeAttempt::IN_PROGRESS)
            ->where('deadline_at', '>=', now())
            ->orderBy('id')
            ->pluck('id', 'exam_id');

        return $exams->map(fn (PracticeExam $e) => [
            'id' => $e->id,
            'code' => $e->code,
            'label' => $e->label(),
            'term' => $e->term,
            'term_label' => $e->termLabel(),
            'question_count' => (int) $e->questions_count,
            'duration' => $e->duration_minutes,
            'attempts' => (int) ($stats[$e->id]->attempts ?? 0),
            'best_score' => isset($stats[$e->id]) && $stats[$e->id]->best_score !== null ? (float) $stats[$e->id]->best_score : null,
            'inProgressAttemptId' => $inProgress->get($e->id),
        ])->all();
    }

    /** Chủ hồ sơ, hoặc nhân sự có student.view với học viên trong phạm vi dữ liệu. */
    private function canView(User $user, Student $student): bool
    {
        return $student->isLinkedTo($user)
            || ($user->can('student.view') && Student::query()->visibleTo($user)->whereKey($student->id)->exists());
    }

    /** Lượt làm người dùng xem được; không tồn tại hoặc ngoài tầm với → 404 (không lộ lượt có tồn tại hay không). */
    private function findAttempt(User $user, $id): PracticeAttempt
    {
        $attempt = ctype_digit((string) $id) ? PracticeAttempt::query()->with('student')->find((int) $id) : null;
        abort_unless($attempt && $attempt->student && $this->canView($user, $attempt->student), 404);

        return $attempt;
    }

    private function findAttemptForWrite(User $user, $id): PracticeAttempt
    {
        $attempt = $this->findAttempt($user, $id);
        abort_unless($attempt->student->isLinkedTo($user), 403, 'Chỉ học viên / phụ huynh của hồ sơ này mới làm bài được.');

        return $attempt;
    }
}
