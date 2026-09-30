<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Services\CrmStageService;
use App\Services\NotificationService;
use App\Services\PlacementPortalLinkService;
use App\Services\PlacementRubricService;
use App\Services\SafeUploadService;
use App\Support\Ui;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PlacementTestController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $allTests = PlacementTest::withCount(['submissions' => fn ($query) => $query->where(fn ($inner) => $inner
            ->whereNull('customer_id')
            ->orWhereIn('customer_id', CrmCustomer::query()->visibleTo(Auth::user())->select('id')))])->latest()->get()
            ->each(fn (PlacementTest $test) => $test->setAttribute('grade_group', PlacementRubricService::detectGradeGroup($test->code)));

        // Mockup quan-ly-de-dau-vao: lọc Cấp độ (khối lớp theo thang điểm A6 Q2), Trạng thái (Hoạt động / Ẩn), Tìm kiếm tên đề — phía server.
        $search = Str::lower(trim((string) $request->input('search')));
        $gradeGroup = (string) $request->input('grade_group');
        $status = (string) $request->input('status');
        $filtered = $allTests
            ->when($search !== '', fn ($tests) => $tests->filter(fn (PlacementTest $t) => str_contains(Str::lower($t->title), $search) || str_contains(Str::lower($t->code), $search)))
            ->when(PlacementRubricService::isValidGroup($gradeGroup), fn ($tests) => $tests->where('grade_group', $gradeGroup))
            ->when($status === 'active', fn ($tests) => $tests->where('is_active', true))
            ->when($status === 'hidden', fn ($tests) => $tests->where('is_active', false))
            ->values();
        $perPage = $request->perPage(20);
        $page = max(1, $request->integer('page', 1));
        $tests = new LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(), $filtered->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $submissionsQuery = $this->visibleSubmissionsQuery()->with(['test', 'grader', 'customer'])->latest();

        $selectedTest = null;
        if ($request->filled('test_id')) {
            $submissionsQuery->where('placement_test_id', $request->input('test_id'));
            $selectedTest = PlacementTest::find($request->input('test_id'));
        }

        $recentSubmissions = $submissionsQuery->take(50)->get();

        $stats = [
            'total_tests' => $allTests->count(),
            'active_tests' => $allTests->where('is_active', true)->count(),
            'hidden_tests' => $allTests->where('is_active', false)->count(),
            // Chỉ đếm bài làm trong phạm vi được xem (Quản lý / Học vụ: chi nhánh mình).
            'total_submissions' => $this->visibleSubmissionsQuery()->count(),
            'pending_submissions' => $this->visibleSubmissionsQuery()->where('status', 'pending')->count(),
        ];

        $gradeGroups = PlacementRubricService::gradeGroups();

        return Inertia::render('PlacementTests/Index', [
            'tests' => $tests->through(fn (PlacementTest $t) => [
                'id' => $t->id,
                'code' => $t->code,
                'title' => $t->title,
                'is_preset' => (bool) $t->is_preset,
                'is_active' => (bool) $t->is_active,
                'type' => str_contains(strtoupper($t->code), 'SPEAKING') ? 'speaking_test' : 'placement_test',
                'grade_group_label' => $gradeGroups[$t->grade_group] ?? 'Chưa rõ khối',
                'target_level' => $t->target_level,
                'duration_minutes' => $t->duration_minutes,
                'submissions_count' => (int) $t->submissions_count,
            ]),
            'submissions' => $recentSubmissions->map(fn (PlacementTestSubmission $sub) => $this->submissionRow($sub))->all(),
            'selectedTest' => $selectedTest ? ['title' => $selectedTest->title, 'code' => $selectedTest->code] : null,
            'stats' => $stats,
            'gradeGroups' => Ui::options($gradeGroups),
        ]);
    }

    /** Dòng bài làm trên danh sách "Bài làm & kết quả chấm" (điểm đã định dạng như màn cũ). */
    private function submissionRow(PlacementTestSubmission $sub): array
    {
        $total = $sub->total_score !== null
            ? self::formatScore($sub->total_score).(PlacementRubricService::hasRubric($sub->grade_group) ? ' / '.PlacementRubricService::maxTotal($sub->grade_group) : ' điểm')
            : ($sub->scoreSummary() ?? '—');

        return [
            'id' => $sub->id,
            'candidate_name' => $sub->candidate_name,
            'candidate_phone' => $sub->candidate_phone,
            'customer_id' => $sub->customer?->id,
            'violation_count' => (int) $sub->violation_count,
            'auto_submitted' => (bool) $sub->auto_submitted,
            'created_at' => $sub->created_at?->toIso8601String(),
            'test_title' => $sub->test?->title,
            'test_code' => $sub->test?->code,
            'listening' => self::formatScore($sub->listening_score),
            'reading_writing' => self::formatScore($sub->reading_writing_score ?? $sub->reading_score),
            'speaking' => self::formatScore($sub->speaking_score),
            'is_pending' => $sub->isPending(),
            'total' => $total,
            'final_class' => $sub->finalClass() ?? $sub->recommended_course ?? '—',
            'scorecard_url' => URL::signedRoute('portal.test.scorecard', ['id' => $sub->id]),
        ];
    }

    /** Điểm dạng "7.5" / "8" (bỏ số 0 thừa); chưa có điểm → $empty. */
    private static function formatScore(mixed $value, string $empty = '—'): string
    {
        return $value === null || $value === '' ? $empty : rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
    }

    /** Mockup: nút Ẩn / Kích hoạt đề ngay trên danh sách (đề đã có bài làm không xóa được — ẩn để ngừng phát hành). */
    public function toggleActive($id)
    {
        $test = PlacementTest::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $test->update(['is_active' => ! $test->is_active]);

        return back()->with('status', $test->is_active ? "Đã kích hoạt đề {$test->code}." : "Đã ẩn đề {$test->code} — link làm bài của đề này ngừng hoạt động.");
    }

    public function create(): InertiaResponse
    {
        $initialLevel = 'lop_3';
        $levelRubricGroups = collect(PlacementTest::GRADE_LEVELS)->map(fn ($label, $level) => PlacementTest::rubricGroupForLevel($level));
        $initialGroup = $levelRubricGroups[$initialLevel] ?? 'khac';

        return Inertia::render('PlacementTests/Create', [
            'gradeCodeTokens' => self::GRADE_CODE_TOKENS,
            'gradeLevels' => Ui::options(PlacementTest::GRADE_LEVELS),
            'levelRubricGroups' => $levelRubricGroups,
            'initialLevel' => $initialLevel,
            'initialCode' => 'TEST-'.(self::GRADE_CODE_TOKENS[$initialGroup] ?? 'G3').'-'.date('ymd-His'),
        ]);
    }

    /** Mã đề phải chứa khối lớp để hệ thống chấm theo thang điểm (PlacementRubricService::detectGradeGroup). */
    public const GRADE_CODE_TOKENS = [
        'khoi_1_2' => 'G1-G2',
        'khoi_2_3' => 'G2-G3',
        'khoi_3_4' => 'G3-G4',
        'khoi_4_5' => 'G4-G5',
        PlacementRubricService::MANUAL_GROUP => 'KHAC',
    ];

    public function storeTest(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:placement_tests,code|max:50',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'grade_level' => 'nullable|string|in:'.implode(',', array_keys(PlacementTest::GRADE_LEVELS)),
            'grade_group' => 'nullable|string|in:'.implode(',', array_keys(PlacementRubricService::gradeGroups())),
            'target_level' => 'required_without_all:grade_group,grade_level|nullable|string|max:255',
            'duration_minutes' => 'required|integer|min:10',
            'questions_count' => 'nullable|integer|min:1',
            'questions' => 'nullable',
            'save_mode' => 'nullable|in:draft,publish',
        ]);
        // Mockup Tạo đề — "Cấp độ" = khối lớp (A6 Q2); mã đề phải khớp khối để chấm đúng thang điểm.
        $gradeLevel = ($validated['grade_level'] ?? null) ?: PlacementTest::detectGradeLevel($validated['code']);
        $gradeGroup = ($validated['grade_level'] ?? null)
            ? PlacementTest::rubricGroupForLevel($gradeLevel)
            : ($validated['grade_group'] ?? null);
        if ($gradeGroup && PlacementRubricService::hasRubric($gradeGroup)
            && PlacementRubricService::detectGradeGroup($validated['code']) !== $gradeGroup) {
            throw ValidationException::withMessages([
                'code' => 'Mã đề phải chứa "'.self::GRADE_CODE_TOKENS[$gradeGroup].'" để hệ thống chấm theo thang điểm '.PlacementRubricService::groupLabel($gradeGroup).'.',
            ]);
        }
        $validated['target_level'] = ($validated['target_level'] ?? null)
            ?: (PlacementTest::gradeLevelLabel($gradeLevel) ?? PlacementRubricService::groupLabel($gradeGroup));

        $questions = $request->input('questions');
        if (is_string($questions)) {
            $questions = json_decode($questions, true);
        }

        $questionsCount = is_array($questions) && count($questions) > 0 ? count($questions) : ($validated['questions_count'] ?? 10);

        $test = PlacementTest::create([
            'code' => $validated['code'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'target_level' => $validated['target_level'],
            'grade_level' => $gradeLevel,
            'duration_minutes' => $validated['duration_minutes'],
            'questions_count' => max(1, $questionsCount),
            'questions' => $questions,
            // "Lưu nháp" = đề ẩn (chưa phát hành link làm bài).
            'is_active' => ($validated['save_mode'] ?? 'publish') !== 'draft',
        ]);

        return redirect()->route('placement-tests.index')
            ->with('status', $test->is_active
                ? "Đã tạo đề kiểm tra trình độ {$test->title} ({$test->code}) thành công!"
                : "Đã lưu nháp đề {$test->title} ({$test->code}) — đề đang ẩn, bấm Kích hoạt khi sẵn sàng.");
    }

    /**
     * Mockup Tạo đề: "Tải file nghe (.mp3)" và "Tải ảnh lên" cho phương án.
     * File lưu disk public (thí sinh không đăng nhập vẫn nghe/xem được), đuôi kiểm theo nội dung (SafeUploadService).
     */
    public function uploadMedia(Request $request)
    {
        abort_unless($request->user()->can('placement_test.create') || $request->user()->can('placement_test.update'), 403);
        $validated = $request->validate([
            'kind' => 'required|in:audio,image',
            'file' => 'required|file|max:'.($request->input('kind') === 'audio' ? 20480 : 5120),
        ], ['file.max' => 'File quá lớn (âm thanh tối đa 20 MB, ảnh tối đa 5 MB).']);
        $allowed = $validated['kind'] === 'audio' ? SafeUploadService::AUDIO : SafeUploadService::IMAGES;
        $path = SafeUploadService::store($request->file('file'), 'placement_tests/'.now()->format('Y/m'), $allowed, 'file');

        return response()->json(['url' => Storage::disk('public')->url($path), 'path' => $path]);
    }

    public function showTest($id): InertiaResponse
    {
        $test = PlacementTest::where('id', $id)->orWhere('code', $id)->firstOrFail();
        // Chỉ bài làm của khách trong phạm vi chi nhánh người xem (giống màn kết quả).
        $test->setRelation('submissions', $this->visibleSubmissionsQuery()
            ->where('placement_test_id', $test->id)
            ->with(['customer', 'grader'])
            ->latest()
            ->get());

        return Inertia::render('PlacementTests/Show', [
            'test' => [
                'id' => $test->id,
                'code' => $test->code,
                'title' => $test->title,
                'is_preset' => (bool) $test->is_preset,
                'target_level' => $test->target_level,
                'duration_minutes' => $test->duration_minutes,
                'questions_count' => $test->questions_count,
                'questions' => array_values(is_array($test->questions) ? $test->questions : []),
            ],
            'takeUrl' => route('portal.test.take', $test->code),
            'submissions' => $test->submissions->map(fn (PlacementTestSubmission $sub) => [
                'id' => $sub->id,
                'candidate_name' => $sub->candidate_name,
                'candidate_phone' => $sub->candidate_phone,
                'customer_id' => $sub->customer?->id,
                'created_at' => $sub->created_at?->toIso8601String(),
                'listening_score' => $sub->listening_score,
                'reading_score' => $sub->reading_score,
                'writing_score' => $sub->writing_score,
                'speaking_score' => $sub->speaking_score,
                'is_pending' => $sub->isPending(),
                'score_summary' => $sub->scoreSummary(),
                'recommended_course' => $sub->recommended_course,
                'scorecard_url' => URL::signedRoute('portal.test.scorecard', ['id' => $sub->id]),
            ])->all(),
        ]);
    }

    public function editTest($id)
    {
        $test = PlacementTest::where('id', $id)->orWhere('code', $id)->firstOrFail();

        if ($test->is_preset) {
            return redirect()->route('placement-tests.index')
                ->with('error', "Đề thi mẫu hệ thống [{$test->code}] đã khóa chỉnh sửa để bảo đảm tính toàn vẹn. Vui lòng bấm 'Nhân bản đề' để tạo bản sao và tùy biến!");
        }

        return Inertia::render('PlacementTests/Edit', [
            'test' => [
                'id' => $test->id,
                'code' => $test->code,
                'title' => $test->title,
                'description' => $test->description,
                'target_level' => $test->target_level,
                'grade_level' => $test->grade_level,
                'duration_minutes' => $test->duration_minutes,
                'is_active' => (bool) $test->is_active,
                'questions' => array_values(is_array($test->questions) ? $test->questions : []),
            ],
            'gradeLevels' => Ui::options(PlacementTest::GRADE_LEVELS),
        ]);
    }

    public function updateTest(Request $request, $id)
    {
        $test = PlacementTest::where('id', $id)->orWhere('code', $id)->firstOrFail();

        if ($test->is_preset) {
            return redirect()->route('placement-tests.index')
                ->with('error', "Không thể cập nhật đề thi mẫu hệ thống đã khóa [{$test->code}]!");
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'target_level' => 'required|string|max:255',
            'grade_level' => 'nullable|string|in:'.implode(',', array_keys(PlacementTest::GRADE_LEVELS)),
            'duration_minutes' => 'required|integer|min:10',
            'questions_count' => 'nullable|integer|min:1',
            'questions' => 'nullable',
            'is_active' => 'nullable|boolean',
        ]);

        $questions = $request->input('questions');
        if (is_string($questions)) {
            $questions = json_decode($questions, true);
        }

        $questionsCount = is_array($questions) && count($questions) > 0 ? count($questions) : ($validated['questions_count'] ?? $test->questions_count);

        $test->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? $test->description,
            'target_level' => $validated['target_level'],
            'grade_level' => $request->has('grade_level') ? ($validated['grade_level'] ?? null) : $test->grade_level,
            'duration_minutes' => $validated['duration_minutes'],
            'questions_count' => max(1, $questionsCount),
            'questions' => $questions ?? $test->questions,
            'is_active' => $request->has('is_active') ? true : false,
        ]);

        return redirect()->route('placement-tests.index')
            ->with('status', "Đã cập nhật đề thi {$test->title} ({$test->code}) thành công!");
    }

    public function duplicateTest($id)
    {
        $test = PlacementTest::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $newCode = 'TEST-CUSTOM-'.strtoupper(Str::random(5));

        $copy = PlacementTest::create([
            'code' => $newCode,
            'title' => $test->title.' (Bản sao tùy biến)',
            'description' => $test->description,
            'target_level' => $test->target_level,
            'grade_level' => $test->grade_level,
            'duration_minutes' => $test->duration_minutes,
            'questions_count' => $test->questions_count,
            'questions' => $test->questions,
            'is_active' => true,
            'is_preset' => false,
        ]);

        return redirect()->route('placement-tests.edit', $copy->id)
            ->with('status', "Đã nhân bản thành công đề thi sang mã {$copy->code}. Bạn có thể tùy ý sửa đổi câu hỏi và cấu hình!");
    }

    public function destroyTest($id)
    {
        $test = PlacementTest::where('id', $id)->orWhere('code', $id)->firstOrFail();

        if ($test->is_preset) {
            return redirect()->route('placement-tests.index')
                ->with('error', "Đề thi mẫu hệ thống [{$test->code}] đã khóa và không thể xóa!");
        }

        // Bài làm của thí sinh là dữ liệu tuyển sinh (điểm, câu trả lời, lịch sử khách) — không xóa theo đề.
        $submissionCount = $test->submissions()->count();
        if ($submissionCount > 0) {
            return redirect()->route('placement-tests.index')
                ->with('error', "Đề [{$test->code}] đã có {$submissionCount} bài làm nên không thể xóa. Hãy bấm \"Ẩn\" trên danh sách đề để ngừng phát hành.");
        }

        $title = $test->title;
        $test->delete();

        return redirect()->route('placement-tests.index')
            ->with('status', "Đã xóa đề thi {$title}!");
    }

    /**
     * Phát hành đề: kích hoạt đề và sinh link làm bài công khai theo mã đề
     * để học vụ gửi cho lead/học viên (dán vào CRM hoặc Zalo).
     */
    public function distributeTest($id)
    {
        $test = PlacementTest::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $test->update(['is_active' => true]);

        $takeUrl = route('portal.test.take', ['code' => $test->code]);

        AdminNotification::create([
            'title' => 'Phát hành đề test đầu vào: '.$test->title,
            'message' => "Đề [{$test->code}] {$test->title} đã được phát hành. Link làm bài: {$takeUrl}",
            'type' => 'info',
            'is_read' => false,
        ]);

        return redirect()->back()
            ->with('status', "Đã phát hành đề [{$test->code}] {$test->title}. Link làm bài: {$takeUrl}");
    }

    /**
     * Bài nộp gắn với khách CRM chỉ xem / chấm được khi khách thuộc phạm vi CRM của người dùng
     * (Quản lý cơ sở / Học vụ: chi nhánh mình; Admin: tất cả). Bài chưa gắn khách giữ nguyên quyền chấm.
     */
    private function visibleSubmissionsQuery()
    {
        return PlacementTestSubmission::query()->where(fn ($query) => $query
            ->whereNull('customer_id')
            ->orWhereIn('customer_id', CrmCustomer::query()->visibleTo(Auth::user())->select('id')));
    }

    public function showResult($id): InertiaResponse
    {
        $submission = $this->visibleSubmissionsQuery()->with(['test', 'grader', 'customer', 'student'])->findOrFail($id);

        $test = $submission->test;
        $questions = is_array($test?->questions) ? $test->questions : [];

        return Inertia::render('PlacementTests/Result', [
            'submission' => [
                'id' => $submission->id,
                'candidate_name' => $submission->candidate_name,
                'candidate_phone' => $submission->candidate_phone,
                'customer_id' => $submission->customer_id,
                'test_title' => $submission->test?->title ?? 'Đề Test Đầu Vào MEnglish',
                'score_summary' => $submission->scoreSummary(),
                'is_pending' => $submission->isPending(),
                'submitted_at' => ($submission->created_at ?? now())->toIso8601String(),
                'listening_score' => $submission->listening_score,
                'reading_score' => $submission->reading_score,
                'reading_writing_score' => $submission->reading_writing_score,
                'violation_count' => (int) $submission->violation_count,
                'auto_submitted' => (bool) $submission->auto_submitted,
                'violations' => collect($submission->violation_log ?? [])->map(fn (array $entry) => [
                    'at' => rescue(fn () => Carbon::parse($entry['at'] ?? '')->timezone(config('app.timezone'))->format('H:i:s d/m/Y'), $entry['at'] ?? '', false),
                    'label' => PlacementTestSubmission::VIOLATION_TYPES[$entry['type']] ?? $entry['type'],
                ])->all(),
                'writing_content' => $submission->writing_content,
                'writing_word_count' => str_word_count($submission->writing_content ?? ''),
                'speaking_audio_url' => $submission->speaking_audio_url,
                'scorecard_url' => URL::signedRoute('portal.test.scorecard', ['id' => $submission->id]),
            ],
            'questions' => $this->reviewQuestions($questions, $submission),
            'rubric' => self::rubricFormState($submission, $submission->resolvedGradeGroup()),
        ]);
    }

    /**
     * Câu hỏi của đề kèm câu trả lời của thí sinh và kết quả đối chiếu (đúng / sai) — tính ở server như màn cũ.
     *
     * @param  array<int|string, array<string, mixed>>  $questions
     * @return list<array<string, mixed>>
     */
    private function reviewQuestions(array $questions, PlacementTestSubmission $submission): array
    {
        $answers = $submission->answers ?? [];
        $rows = [];
        foreach ($questions as $idx => $q) {
            $qId = $q['id'] ?? ($idx + 1);
            $skill = $q['skill'] ?? 'general';
            $type = $q['type'] ?? 'multiple_choice';
            $correctAnswer = trim((string) ($q['correct_answer'] ?? ''));
            $candidateAnswer = trim((string) ($answers[$qId] ?? $answers['q'.$qId] ?? ($answers[$idx] ?? '')));
            if ($skill === 'writing' && empty($candidateAnswer)) {
                $candidateAnswer = (string) $submission->writing_content;
            }
            $isObjective = in_array($type, ['multiple_choice', 'fill_blank', 'single_choice']);
            $isCorrect = $isObjective && ! empty($candidateAnswer) && ! empty($correctAnswer) && strcasecmp($candidateAnswer, $correctAnswer) === 0;

            $rows[] = [
                'number' => $idx + 1,
                'skill' => $skill,
                'type' => $type,
                'title' => $q['title'] ?? 'Câu hỏi trắc nghiệm',
                'points' => $q['points'] ?? 1,
                'audio_url' => $q['audio_url'] ?? null,
                'passage' => $q['passage'] ?? null,
                'rubric_note' => $q['rubric_note'] ?? null,
                'cue_points' => $q['cue_points'] ?? null,
                'explanation' => $q['explanation'] ?? null,
                'correct_answer' => $correctAnswer,
                'candidate_answer' => $candidateAnswer,
                'is_objective' => $isObjective,
                'is_correct' => $isCorrect,
                'is_incorrect' => $isObjective && ! empty($candidateAnswer) && ! empty($correctAnswer) && ! $isCorrect,
                'options' => collect($type === 'multiple_choice' ? ($q['options'] ?? []) : [])->map(fn ($opt) => [
                    'key' => $opt['key'] ?? '',
                    'text' => $opt['text'] ?? '',
                    'is_correct' => strcasecmp((string) ($opt['key'] ?? ''), $correctAnswer) === 0,
                    'is_chosen' => strcasecmp((string) ($opt['key'] ?? ''), $candidateAnswer) === 0,
                ])->values()->all(),
            ];
        }

        return $rows;
    }

    /**
     * Dữ liệu ô chấm điểm theo thang điểm khối lớp (resources/js/Components/PlacementTests/RubricScoreFields.vue):
     * cấu hình thang điểm + giá trị ban đầu. Nhận xét đang lưu trùng gợi ý theo băng điểm (VD: hệ thống tự sinh khi
     * thí sinh nộp bài) thì coi như chưa sửa tay: Học vụ đổi điểm là nhận xét đổi theo. Nhận xét đã sửa tay thì giữ nguyên.
     *
     * @return array<string, mixed>
     */
    public static function rubricFormState(?PlacementTestSubmission $sub, ?string $defaultGroup = null): array
    {
        $config = PlacementRubricService::clientConfig();
        $initial = [
            'group' => $sub?->grade_group ?? $defaultGroup ?? array_key_first($config['groups']),
            'listening' => self::formatScore($sub?->listening_score, ''),
            // Bài nộp online: phần Đọc trắc nghiệm đã tự chấm (reading_score) làm gợi ý cho ô Đọc & Viết.
            'reading_writing' => self::formatScore($sub?->reading_writing_score ?? $sub?->reading_score, ''),
            'speaking' => self::formatScore($sub?->speaking_score, ''),
            'chosen' => $sub?->chosen_class,
            'comments' => [
                'listening' => $sub?->listening_comment,
                'reading_writing' => $sub?->reading_writing_comment,
                'speaking' => $sub?->speaking_comment,
            ],
            'teacher_comments' => $sub?->teacher_comments,
            'edited' => [],
        ];
        $savedGroup = $sub?->grade_group;
        foreach (PlacementRubricService::SKILLS as $skill => $label) {
            $comment = (string) ($initial['comments'][$skill] ?? '');
            $savedScore = $sub?->{$skill.'_score'};
            $autoComment = $savedGroup && $savedScore !== null ? PlacementRubricService::skillComment($savedGroup, $skill, (float) $savedScore) : null;
            $initial['edited'][$skill] = $comment !== '' && $comment !== $autoComment;
        }

        return [
            'config' => $config,
            'initial' => $initial,
            'gradeGroups' => Ui::options(PlacementRubricService::gradeGroups()),
            'skills' => Ui::options(PlacementRubricService::SKILLS),
            'classOptions' => PlacementRubricService::classOptions(),
            'noRubricNotice' => PlacementRubricService::noRubricNotice(),
        ];
    }

    /**
     * Chấm bài theo thang điểm khối lớp (BA Q2): Nghe + Đọc&Viết + Nói, tổng → lớp đề xuất, cho chọn lại lớp.
     * Chỉ Học vụ / Quản lý cơ sở / Admin (quyền placement_test.grade).
     */
    public function updateResult(Request $request, $id)
    {
        $submission = $this->visibleSubmissionsQuery()->with('test')->findOrFail($id);

        $group = (string) $request->input('grade_group');
        $validated = $request->validate(
            PlacementRubricService::scoreRules($group),
            PlacementRubricService::scoreMessages($group)
        );

        $submission->applyRubricGrade($validated);
        $submission->grader_id = Auth::id();

        // Mockup: "Lưu bản nháp" giữ bài ở trạng thái Chờ chấm (chưa đồng bộ sang khách, chưa chuyển "Đã test");
        // "Xác nhận kết quả" chốt điểm. Bài đã chấm không lùi về nháp.
        if ($request->input('action') === 'draft' && $submission->isPending()) {
            $submission->save();

            return redirect()->route('placement-tests.results.show', $submission->id)
                ->with('status', 'Đã lưu bản nháp điểm — bài vẫn ở trạng thái Chờ chấm cho tới khi Xác nhận kết quả.');
        }

        $submission->status = PlacementTestSubmission::STATUS_GRADED;
        $submission->save();

        if ($submission->customer) {
            $this->syncGradedResultToLead($submission, $submission->customer);
        }

        return redirect()->route('placement-tests.results.show', $submission->id)
            ->with('status', 'Đã chấm và lưu kết quả bài test: '.$submission->scoreSummary().'.');
    }

    public function rubricGuide(): InertiaResponse
    {
        return Inertia::render('PlacementTests/RubricGuide', [
            'config' => PlacementRubricService::clientConfig(),
            'groups' => Ui::options(collect(PlacementRubricService::rubrics())->map(fn (array $rubric) => mb_strtoupper($rubric['label']))),
            'noRubricNotice' => PlacementRubricService::noRubricNotice(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // CỔNG LÀM BÀI TRỰC TUYẾN CHO LEAD / HỌC VIÊN
    // ─────────────────────────────────────────────────────────────

    public function portalTakeTest($code, Request $request, PlacementPortalLinkService $links): InertiaResponse
    {
        $test = $this->findActiveTestByCode($code);

        // Chỉ link có chữ ký (CRM sinh, hạn 7 ngày) mới được điền sẵn thông tin lead.
        $lead = $links->leadFromSignedRequest($request);
        $leadToken = $lead ? $links->issueLeadToken($test, $lead, $request) : null;
        if ($lead) {
            // BA: thí sinh mở link test riêng → lead tự chuyển sang "Test".
            app(CrmStageService::class)->advanceTo($lead, 'testing', null, "Thí sinh mở link làm bài test [{$test->code}].");
        }

        return Inertia::render('PlacementTests/Portal/Take', [
            'test' => [
                'code' => $test->code,
                'title' => $test->title,
                'target_level' => $test->target_level,
                'duration_minutes' => $test->duration_minutes,
                'questions_count' => $test->questions_count,
            ],
            ...$this->portalQuestions(is_array($test->questions) ? $test->questions : []),
            'lead' => $lead ? ['name' => $lead->name, 'phone' => $lead->phone, 'email' => $lead->email] : null,
            'leadToken' => $leadToken,
            'maxViolations' => PlacementTestSubmission::MAX_VIOLATIONS,
        ]);
    }

    /**
     * Câu hỏi cho trang làm bài công khai — chỉ phần thí sinh cần thấy (props nằm trong mã nguồn trang:
     * không gửi đáp án, giải thích, ghi chú giáo viên). Số câu ("Câu n") và tên ô trả lời theo vị trí trong đề như cũ.
     *
     * @param  array<int|string, array<string, mixed>>  $questions
     * @return array{listening: list<array<string, mixed>>, reading: list<array<string, mixed>>, writingPrompt: string, speaking: ?array{title: string, cue_points: string}}
     */
    private function portalQuestions(array $questions): array
    {
        $item = fn (array $q, int|string $idx) => [
            'number' => (int) $idx + 1,
            'answer_key' => (string) ($q['id'] ?? $idx),
            'type' => $q['type'] ?? '',
            'title' => $q['title'] ?? '',
            'passage' => $q['passage'] ?? null,
            'image_url' => $q['image_url'] ?? null,
            'audio_src' => ! empty($q['audio_url'])
                ? (str_starts_with($q['audio_url'], 'http') || str_starts_with($q['audio_url'], '/') ? $q['audio_url'] : '/'.$q['audio_url'])
                : null,
            'options' => collect($q['options'] ?? [])->map(fn ($opt) => [
                'key' => $opt['key'] ?? '',
                'text' => $opt['text'] ?? '',
                'image_url' => $opt['image_url'] ?? null,
            ])->values()->all(),
        ];
        $bySkill = fn (array $skills) => collect($questions)
            ->filter(fn ($q) => in_array($q['skill'] ?? '', $skills, true))
            ->map($item)->values()->all();

        $writingQ = collect($questions)->first(fn ($q) => ($q['skill'] ?? '') === 'writing');
        $speakingQ = collect($questions)->first(fn ($q) => ($q['skill'] ?? '') === 'speaking');

        return [
            'listening' => $bySkill(['listening']),
            'reading' => $bySkill(['reading', 'grammar']),
            'writingPrompt' => $writingQ['title'] ?? 'Hãy viết một đoạn văn ngắn giới thiệu về bản thân, sở thích hoặc một chuyến đi đáng nhớ của bạn.',
            'speaking' => $speakingQ && ! empty($speakingQ['cue_points'])
                ? ['title' => $speakingQ['title'] ?? '', 'cue_points' => $speakingQ['cue_points']]
                : null,
        ];
    }

    public function portalSubmitTest($code, Request $request, PlacementPortalLinkService $links)
    {
        $test = $this->findActiveTestByCode($code);

        if ($request->isMethod('GET')) {
            return redirect()->route('portal.test.take', $test->code);
        }

        $validated = $request->validate([
            'candidate_name' => 'required|string|max:255',
            'candidate_phone' => 'required|string|max:20',
            'candidate_email' => 'nullable|email|max:255',
            'answers' => 'nullable|array|max:500',
            'answers.*' => 'nullable|string|max:1000',
            'writing_content' => 'nullable|string|max:5000',
            'speaking_self_rate' => 'nullable|string|in:beginner,intermediate,advanced',
            'lead_token' => 'nullable|string|max:2000',
            'violation_count' => 'nullable|integer|min:0|max:1000',
            'violation_log' => 'nullable|string|max:10000',
            'auto_submitted' => 'nullable|boolean',
        ]);

        $questions = is_array($test->questions) ? $test->questions : [];
        $submittedAnswers = $this->answersForQuestions($questions, $validated['answers'] ?? []);

        // Tự chấm theo đáp án lưu trong đề: Nghe trên thang Nghe; Đọc & Viết (các câu Đọc / Ngữ pháp / Viết có đáp án)
        // trên thang Đọc & Viết của khối lớp (theo mã đề). Bài viết tự luận và phần Nói do Học vụ chấm.
        // Điểm + nhận xét tự động chỉ là bản nháp: bài vẫn "Chờ chấm" để Admin / Học vụ xem lại, sửa và nhập điểm Nói.
        $gradeGroup = PlacementRubricService::detectGradeGroup($test->code);
        // Khối không có thang (lớp 5–9…): tự chấm online quy về thang 10 mỗi kỹ năng để Học vụ tham khảo, không xếp lớp tự động.
        $maxScores = PlacementRubricService::hasRubric($gradeGroup)
            ? PlacementRubricService::maxScores($gradeGroup)
            : PlacementRubricService::ONLINE_MANUAL_SCALE;
        $listeningScore = $this->autoGradeSkill($questions, $submittedAnswers, ['listening'], $maxScores['listening']);
        $readingWritingScore = $this->autoGradeSkill($questions, $submittedAnswers, ['reading', 'grammar', 'writing'], $maxScores['reading_writing']);

        [$customer, $viaSignedLink] = $this->resolveSubmissionLead($validated, $test, $links);

        $storedAnswers = $submittedAnswers;
        if (! empty($validated['speaking_self_rate'])) {
            $storedAnswers['speaking_self_rate'] = $validated['speaking_self_rate'];
        }

        $submission = PlacementTestSubmission::create([
            'placement_test_id' => $test->id,
            'customer_id' => $customer?->id,
            'candidate_name' => $validated['candidate_name'],
            'candidate_phone' => $validated['candidate_phone'],
            'candidate_email' => $validated['candidate_email'] ?? null,
            'grade_group' => $gradeGroup,
            'listening_score' => $listeningScore,
            // reading_score giữ cho các màn cũ; điểm dùng để chấm theo thang khối là reading_writing_score.
            'reading_score' => $readingWritingScore,
            'reading_writing_score' => $readingWritingScore,
            'listening_comment' => PlacementRubricService::skillComment($gradeGroup, 'listening', $listeningScore),
            'reading_writing_comment' => PlacementRubricService::skillComment($gradeGroup, 'reading_writing', $readingWritingScore),
            'writing_score' => null,
            'speaking_score' => null,
            'overall_score' => null,
            'cefr_level' => null,
            'writing_content' => $validated['writing_content'] ?? null,
            'answers' => $storedAnswers,
            'violation_count' => (int) ($validated['violation_count'] ?? 0),
            'violation_log' => $this->sanitizeViolationLog($validated['violation_log'] ?? null),
            'auto_submitted' => (bool) ($validated['auto_submitted'] ?? false),
            'grader_id' => null,
            'status' => PlacementTestSubmission::STATUS_PENDING,
        ]);

        if ($customer) {
            $this->recordPortalSubmissionOnLead($customer, $submission, $test, $viaSignedLink);
        }

        // Bắn email thông báo học vụ nộp bài / kiểm tra
        try {
            app(NotificationService::class)->notifyHomeworkOrTestSubmission($submission);
        } catch (\Throwable $e) {
            Log::warning('Lỗi gửi email thông báo học viên nộp bài: '.$e->getMessage());
        }

        // Thí sinh không xem điểm sau khi nộp: kết quả do Học vụ duyệt rồi mới gửi (bảng điểm là link có chữ ký cho nhân viên).
        return redirect()->route('portal.test.done', $test->code)
            ->with('placement_test_done', $submission->candidate_name);
    }

    /** Màn cảm ơn sau khi nộp bài — không hiển thị điểm / kết quả. */
    public function portalDone($code): InertiaResponse
    {
        $test = PlacementTest::query()->where('code', $code)->firstOrFail();

        return Inertia::render('PlacementTests/Portal/Done', [
            'testTitle' => $test->title,
            'candidateName' => session('placement_test_done'),
        ]);
    }

    public function portalScorecard($id)
    {
        $submission = PlacementTestSubmission::with('test')->findOrFail($id);

        return view('placement-tests.portal-scorecard', compact('submission'));
    }

    /**
     * Nhật ký vi phạm do trình duyệt gửi lên: chỉ giữ loại vi phạm đã biết và thời điểm, tối đa 50 dòng.
     *
     * @return array<int, array{type: string, at: string}>|null
     */
    private function sanitizeViolationLog(?string $raw): ?array
    {
        $entries = $raw ? json_decode($raw, true) : null;
        if (! is_array($entries)) {
            return null;
        }

        $log = [];
        foreach (array_slice($entries, 0, 50) as $entry) {
            $type = is_array($entry) ? ($entry['type'] ?? null) : null;
            if (! is_string($type) || ! array_key_exists($type, PlacementTestSubmission::VIOLATION_TYPES)) {
                continue;
            }
            $at = is_string($entry['at'] ?? null) ? substr($entry['at'], 0, 40) : '';
            $log[] = ['type' => $type, 'at' => $at];
        }

        return $log ?: null;
    }

    private function findActiveTestByCode(string $code): PlacementTest
    {
        return PlacementTest::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * Chỉ giữ đáp án của các câu hỏi có trong đề (theo id câu hỏi).
     *
     * @param  array<int, array<string, mixed>>  $questions
     * @param  array<int|string, mixed>  $answers
     * @return array<int|string, string>
     */
    private function answersForQuestions(array $questions, array $answers): array
    {
        $kept = [];
        foreach ($questions as $idx => $question) {
            $questionId = $question['id'] ?? $idx;
            $answer = $answers[$questionId] ?? null;
            if (is_string($answer) && trim($answer) !== '') {
                $kept[$questionId] = trim($answer);
            }
        }

        return $kept;
    }

    /**
     * Chấm tự động các câu của kỹ năng theo đáp án chuẩn trong đề, quy về thang điểm của khối
     * (điểm câu đúng / tổng điểm câu × điểm tối đa, làm tròn 0,5; câu không ghi điểm tính 1).
     * Chỉ tính câu có đáp án (trắc nghiệm, đúng/sai, điền từ); bài viết tự luận không có đáp án nên Học vụ chấm.
     * Đề không có câu nào của kỹ năng này (hoặc không có đáp án) => null, không bịa điểm.
     *
     * @param  array<int, array<string, mixed>>  $questions
     * @param  array<int|string, string>  $answers
     * @param  array<int, string>  $skills
     */
    private function autoGradeSkill(array $questions, array $answers, array $skills, int $maxScore): ?float
    {
        $total = 0.0;
        $correct = 0.0;
        foreach ($questions as $idx => $question) {
            $correctAnswer = trim((string) ($question['correct_answer'] ?? ''));
            if (! in_array($question['skill'] ?? '', $skills, true) || $correctAnswer === '') {
                continue;
            }
            $points = is_numeric($question['points'] ?? null) && (float) $question['points'] > 0 ? (float) $question['points'] : 1.0;
            $total += $points;
            $answer = $answers[$question['id'] ?? $idx] ?? '';
            if ($answer !== '' && self::answerMatches($answer, $correctAnswer)) {
                $correct += $points;
            }
        }

        if ($total <= 0) {
            return null;
        }

        return round($correct / $total * $maxScore * 2) / 2;
    }

    /**
     * So đáp án không phân biệt hoa thường, bỏ khoảng trắng thừa và dấu chấm câu cuối.
     * Đáp án chuẩn có thể liệt kê nhiều cách viết được chấp nhận, ngăn cách bằng "|" (VD: "7 | seven").
     */
    public static function answerMatches(string $answer, string $correctAnswer): bool
    {
        $normalize = fn (string $text) => mb_strtolower(rtrim(preg_replace('/\s+/u', ' ', trim($text)), ' .!?'));
        $given = $normalize($answer);

        foreach (explode('|', $correctAnswer) as $accepted) {
            $accepted = $normalize($accepted);
            if ($accepted !== '' && $given === $accepted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lead chưa chốt được tự gắn khi thí sinh nộp qua link công khai và SĐT khớp (lead chốt / thất bại thì không,
     * tránh ai đó gõ SĐT để đè kết quả của học viên; Học vụ gắn tay từ hồ sơ khách nếu cần).
     */
    private const PHONE_MATCH_STAGES = ['new', 'consulting', 'test_scheduled', 'testing', 'tested', 'result_sent'];

    /**
     * Không tin `customer_id` từ client. Lead chỉ được gắn khi:
     *  (a) nộp qua link có chữ ký (token được server xác minh lại), hoặc
     *  (b) SĐT chuẩn hoá khớp SĐT lead chưa chốt, hoặc khớp SĐT phụ huynh của đúng một lead chưa chốt.
     *
     * @return array{0: ?CrmCustomer, 1: bool}
     */
    private function resolveSubmissionLead(array $validated, PlacementTest $test, PlacementPortalLinkService $links): array
    {
        $linked = $links->leadFromToken($validated['lead_token'] ?? null, $test);
        if ($linked) {
            return [$linked, true];
        }

        $phone = CrmCustomer::normalizePhone($validated['candidate_phone']);
        if (strlen($phone) < 9) {
            return [null, false];
        }

        $matched = CrmCustomer::query()
            ->where('phone_normalized', $phone)
            ->whereIn('stage', self::PHONE_MATCH_STAGES)
            ->first();
        if ($matched) {
            return [$matched, false];
        }

        // Thí sinh nhỏ tuổi hay nhập SĐT của phụ huynh. Anh chị em dùng chung SĐT phụ huynh thì không đoán.
        $byParent = CrmCustomer::query()
            ->whereIn('stage', self::PHONE_MATCH_STAGES)
            ->whereRaw("REPLACE(REPLACE(REPLACE(parent_phone, ' ', ''), '.', ''), '-', '') LIKE ?", ['%'.substr($phone, -9)])
            ->limit(5)
            ->get()
            ->filter(fn (CrmCustomer $lead) => CrmCustomer::normalizePhone($lead->parent_phone) === $phone);

        return [$byParent->count() === 1 ? $byParent->first() : null, false];
    }

    private function recordPortalSubmissionOnLead(CrmCustomer $customer, PlacementTestSubmission $submission, PlacementTest $test, bool $viaSignedLink): void
    {
        // Nộp bài chưa phải "Đã test": chỉ khi Học vụ chấm xong mới chuyển (syncGradedResultToLead).
        $advanced = $customer->stage === 'testing'
            || app(CrmStageService::class)->advanceTo($customer, 'testing', null, "Thí sinh nộp bài test [{$test->code}].");

        $content = "Học viên đã nộp bài test trực tuyến [{$test->title}] (bài #{$submission->id}), chờ Học vụ chấm điểm.";
        $content .= $viaSignedLink ? ' Nộp qua link test riêng của lead.' : ' Khớp lead theo số điện thoại.';
        if (! $advanced) {
            $content .= " Giữ nguyên giai đoạn hiện tại ({$customer->stage_label}).";
        }

        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id() ?? $customer->assigned_user_id,
            'type' => 'test',
            'content' => $content,
        ]);
    }

    /**
     * Đồng bộ kết quả đã chấm sang Lead: lead chỉ đi tiến
     * (consulting / test_scheduled -> tested), không kéo lùi hay hồi sinh won/lost.
     */
    private function syncGradedResultToLead(PlacementTestSubmission $submission, CrmCustomer $customer): void
    {
        $scoreText = $submission->scoreSummary();
        // Kết quả đi theo khách cả khi khách đã chốt (chấm xong sau khi chốt); khách Thất bại giữ nguyên để đối soát.
        if ($customer->stage !== CrmCustomer::STAGE_LOST) {
            $customer->update(['test_score' => $scoreText]);
        }
        // BA: Học vụ chấm xong → lead tự chuyển "Đã test" (chỉ đi tiến, không đụng lead đã chốt / thất bại).
        $advanced = app(CrmStageService::class)->advanceTo($customer, 'tested', Auth::user(), "Học vụ chấm xong bài test #{$submission->id}.");

        $content = "Học vụ đã chấm bài test #{$submission->id} (".PlacementRubricService::groupLabel($submission->grade_group)."): {$scoreText}";
        if ($submission->classWasOverridden()) {
            $content .= " (lớp đề xuất theo thang điểm: {$submission->suggested_class})";
        }
        if (! $advanced && ! in_array($customer->stage, ['tested', 'result_sent'], true)) {
            $content .= " · Giữ nguyên giai đoạn hiện tại ({$customer->stage_label}).";
        }

        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'type' => 'test',
            'content' => $content,
        ]);
    }
}
