<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\PracticeAttempt;
use App\Models\Student;
use App\Models\User;
use App\Services\Practice\PracticeAttemptProps;
use App\Services\Practice\PracticeReport;
use App\Support\Practice\PracticeCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Báo cáo Kết quả luyện tập (docs/luyen-tap-ngan-hang-cau-hoi.md §7) cho Học thuật / Admin / giáo viên — quyền
 * practice.view_results ở middleware route. Tổng quan + bảng theo học viên → lượt của một học viên → xem lại bài (chỉ đọc).
 * Học viên / lượt ngoài phạm vi `Student::visibleTo` của người xem → 404; trang chỉ đọc, không chốt hay sửa lượt nào.
 */
class PracticeResultsController extends Controller
{
    /** Bộ lọc của trang một học viên (lớp / học viên đã cố định). */
    private const STUDENT_FILTERS = ['grade', 'term', 'from', 'to'];

    /** Bộ lọc màn tổng hợp chỉ mang theo để nút quay lại giữ nguyên (trang học viên không áp dụng). */
    private const INDEX_ONLY_FILTERS = ['class_id', 'q', 'mine'];

    public function __construct(
        private PracticeReport $report,
        private PracticeAttemptProps $attemptProps,
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $filters = $this->report->filters($viewer, $request->query());

        return Inertia::render('PracticeResults/Index', [
            'filters' => $filters,
            'filterOptions' => [
                ...$this->gradeTermOptions(),
                'classes' => ClassModel::query()->visibleTo($viewer)->where('status', '!=', 'cancelled')
                    ->orderBy('name')->get(['id', 'name'])
                    ->map(fn (ClassModel $class) => ['value' => $class->id, 'label' => $class->name])->all(),
            ],
            'summary' => $this->report->summary($viewer, $filters),
            'rows' => $this->report->studentRows($viewer, $filters)->withQueryString(),
        ]);
    }

    public function student(Request $request, $student): Response
    {
        $viewer = $request->user();
        $student = $this->findStudent($viewer, $student);
        $filters = [
            ...$this->report->filters($viewer, $request->only(self::STUDENT_FILTERS)),
            'student_id' => $student->id,
        ];

        return Inertia::render('PracticeResults/Student', [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'code' => $student->code,
                'class_name' => $student->currentClass?->name,
            ],
            'filters' => array_intersect_key($filters, array_flip(self::STUDENT_FILTERS)),
            'backUrl' => route('practice-results.index', $this->indexQuery($viewer, $request)),
            'keepFilters' => self::INDEX_ONLY_FILTERS,
            // link xem lại bài mang theo để nút quay lại về đúng trang học viên đang xem (bộ lọc + trang)
            'returnQuery' => $this->studentPageQuery($viewer, $request),
            'filterOptions' => $this->gradeTermOptions(),
            'summary' => $this->report->summary($viewer, $filters),
            'attempts' => $this->report->attemptList($viewer, $filters)->withQueryString()
                ->through(fn (PracticeAttempt $a) => [
                    'id' => $a->id,
                    'grade' => $a->grade,
                    'term_label' => PracticeCatalog::TERMS[$a->term] ?? $a->term,
                    'submitted_at' => $a->submitted_at?->toIso8601String(),
                    'duration_seconds' => $this->attemptProps->durationSeconds($a),
                    'score' => (float) $a->score,
                    'correct' => (int) $a->correct,
                    'total' => (int) $a->total,
                    'violation_count' => (int) $a->violation_count,
                    'submit_reason' => $a->submit_reason,
                ]),
        ]);
    }

    /** Xem lại một lượt đã nộp (chỉ đọc). Lượt đang làm không có ở báo cáo → 404. */
    public function attempt(Request $request, $attempt): Response
    {
        $viewer = $request->user();
        $attempt = ctype_digit((string) $attempt)
            ? PracticeAttempt::query()->where('status', PracticeAttempt::SUBMITTED)->find((int) $attempt)
            : null;
        abort_unless($attempt, 404);
        $student = $this->findStudent($viewer, $attempt->student_id);

        return Inertia::render('PracticeResults/Attempt', [
            'student' => ['id' => $student->id, 'name' => $student->name, 'code' => $student->code],
            'backUrl' => route('practice-results.student', ['student' => $student->id, ...$this->studentPageQuery($viewer, $request)]),
            'attempt' => $this->attemptProps->result($attempt),
            'items' => $this->attemptProps->reviewItems($attempt),
        ]);
    }

    /** Học viên trong phạm vi người xem; không tồn tại hoặc ngoài phạm vi → 404 (không lộ hồ sơ có tồn tại hay không). */
    private function findStudent(User $viewer, $id): Student
    {
        $student = ctype_digit((string) $id)
            ? Student::query()->visibleTo($viewer)->with('currentClass:id,name')->find((int) $id)
            : null;
        abort_unless($student, 404);

        return $student;
    }

    /** Bộ lọc màn tổng hợp mà trang học viên được mở kèm (đã kiểm tra lại), để quay về đúng danh sách đang xem. */
    private function indexQuery(User $viewer, Request $request): array
    {
        $filters = $this->report->filters($viewer, $request->only([...self::STUDENT_FILTERS, ...self::INDEX_ONLY_FILTERS]));
        $filters['mine'] = $filters['mine'] ? 1 : null;
        unset($filters['student_id']);

        return array_filter($filters, fn ($value) => $value !== null);
    }

    /** Bộ lọc + trang của trang một học viên (đã kiểm tra lại), để từ trang xem lại bài quay về đúng chỗ. */
    private function studentPageQuery(User $viewer, Request $request): array
    {
        $page = $request->query('page');

        return [
            ...$this->indexQuery($viewer, $request),
            ...(is_string($page) && ctype_digit($page) && (int) $page > 1 ? ['page' => (int) $page] : []),
        ];
    }

    private function gradeTermOptions(): array
    {
        return [
            'grades' => array_map(fn (int $grade) => ['value' => $grade, 'label' => "Khối {$grade}"], PracticeCatalog::GRADES),
            'terms' => array_map(fn (string $term, string $label) => ['value' => $term, 'label' => $label],
                array_keys(PracticeCatalog::TERMS), PracticeCatalog::TERMS),
        ];
    }
}
