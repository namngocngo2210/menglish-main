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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PlacementTestController extends Controller
{
    public function index(Request $request)
    {
        $allTests = PlacementTest::withCount('submissions')->latest()->get()
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
        $tests = new \Illuminate\Pagination\LengthAwarePaginator(
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

        return view('placement-tests.index', compact('tests', 'recentSubmissions', 'selectedTest', 'stats'));
    }

    /** Mockup: nút Ẩn / Kích hoạt đề ngay trên danh sách (đề đã có bài làm không xóa được — ẩn để ngừng phát hành). */
    public function toggleActive($id)
    {
        $test = PlacementTest::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $test->update(['is_active' => ! $test->is_active]);

        return back()->with('status', $test->is_active ? "Đã kích hoạt đề {$test->code}." : "Đã ẩn đề {$test->code} — link làm bài của đề này ngừng hoạt động.");
    }

    public function create()
    {
        return view('placement-tests.create', [
            'gradeGroups' => PlacementRubricService::gradeGroups(),
            'gradeCodeTokens' => self::GRADE_CODE_TOKENS,
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
            'grade_group' => 'nullable|string|in:'.implode(',', array_keys(PlacementRubricService::gradeGroups())),
            'target_level' => 'required_without:grade_group|nullable|string|max:255',
            'duration_minutes' => 'required|integer|min:10',
            'questions_count' => 'nullable|integer|min:1',
            'questions' => 'nullable',
            'save_mode' => 'nullable|in:draft,publish',
        ]);
        // Mockup Tạo đề — "Cấp độ" = khối lớp (A6 Q2); mã đề phải khớp khối để chấm đúng thang điểm.
        $gradeGroup = $validated['grade_group'] ?? null;
        if ($gradeGroup && PlacementRubricService::hasRubric($gradeGroup)
            && PlacementRubricService::detectGradeGroup($validated['code']) !== $gradeGroup) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'code' => 'Mã đề phải chứa "'.self::GRADE_CODE_TOKENS[$gradeGroup].'" để hệ thống chấm theo thang điểm '.PlacementRubricService::groupLabel($gradeGroup).'.',
            ]);
        }
        $validated['target_level'] = ($validated['target_level'] ?? null) ?: PlacementRubricService::groupLabel($gradeGroup);

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
        $allowed = $validated['kind'] === 'audio' ? \App\Services\SafeUploadService::AUDIO : \App\Services\SafeUploadService::IMAGES;
        $path = \App\Services\SafeUploadService::store($request->file('file'), 'placement_tests/'.now()->format('Y/m'), $allowed, 'file');

        return response()->json(['url' => \Illuminate\Support\Facades\Storage::disk('public')->url($path), 'path' => $path]);
    }

    public function showTest($id)
    {
        $test = PlacementTest::with(['submissions.customer', 'submissions.grader'])->where('id', $id)->orWhere('code', $id)->firstOrFail();

        return view('placement-tests.show', compact('test'));
    }

    public function editTest($id)
    {
        $test = PlacementTest::where('id', $id)->orWhere('code', $id)->firstOrFail();

        if ($test->is_preset) {
            return redirect()->route('placement-tests.index')
                ->with('error', "Đề thi mẫu hệ thống [{$test->code}] đã khóa chỉnh sửa để bảo đảm tính toàn vẹn. Vui lòng bấm 'Nhân bản đề' để tạo bản sao và tùy biến!");
        }

        return view('placement-tests.edit', compact('test'));
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

    public function showResult($id)
    {
        $submission = $this->visibleSubmissionsQuery()->with(['test', 'grader', 'customer', 'student'])->findOrFail($id);

        $test = $submission->test;
        $questions = is_array($test?->questions) ? $test->questions : [];

        return view('placement-tests.result', compact('submission', 'questions'));
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

    public function rubricGuide()
    {
        return view('placement-tests.rubric-guide');
    }

    // ─────────────────────────────────────────────────────────────
    // CỔNG LÀM BÀI TRỰC TUYẾN CHO LEAD / HỌC VIÊN
    // ─────────────────────────────────────────────────────────────

    public function portalTakeTest($code, Request $request, PlacementPortalLinkService $links)
    {
        $test = $this->findActiveTestByCode($code);

        // Chỉ link có chữ ký (CRM sinh, hạn 7 ngày) mới được điền sẵn thông tin lead.
        $lead = $links->leadFromSignedRequest($request);
        $leadToken = $lead ? $links->issueLeadToken($test, $lead, $request) : null;
        if ($lead) {
            // BA: thí sinh mở link test riêng → lead tự chuyển sang "Test".
            app(CrmStageService::class)->advanceTo($lead, 'testing', null, "Thí sinh mở link làm bài test [{$test->code}].");
        }

        return view('placement-tests.portal-take', compact('test', 'lead', 'leadToken'));
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
        ]);

        $questions = is_array($test->questions) ? $test->questions : [];
        $submittedAnswers = $this->answersForQuestions($questions, $validated['answers'] ?? []);

        // Chỉ chấm tự động Nghe / Đọc-Ngữ pháp theo đáp án lưu trong đề.
        // Viết / Nói do Học vụ chấm (BA) nên để trống, bài ở trạng thái chờ chấm.
        // Điểm quy về thang của khối lớp (theo mã đề): Nghe trên thang Nghe, phần Đọc trắc nghiệm trên thang Đọc & Viết.
        $gradeGroup = PlacementRubricService::detectGradeGroup($test->code);
        $maxScores = PlacementRubricService::maxScores($gradeGroup);
        $listeningScore = $this->autoGradeSkill($questions, $submittedAnswers, ['listening'], $maxScores['listening']);
        $readingScore = $this->autoGradeSkill($questions, $submittedAnswers, ['reading', 'grammar'], $maxScores['reading_writing']);

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
            'reading_score' => $readingScore,
            'writing_score' => null,
            'speaking_score' => null,
            'overall_score' => null,
            'cefr_level' => null,
            'writing_content' => $validated['writing_content'] ?? null,
            'answers' => $storedAnswers,
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

        // Scorecard là trang public nên bắt buộc link có chữ ký — chống dò id tuần tự
        return redirect()->to(URL::signedRoute('portal.test.scorecard', ['id' => $submission->id]))
            ->with('status', 'Hoàn thành bài thi! Học vụ MEnglish sẽ chấm phần Viết/Nói và gửi kết quả xếp lớp cho bạn.');
    }

    public function portalScorecard($id)
    {
        $submission = PlacementTestSubmission::with('test')->findOrFail($id);

        return view('placement-tests.portal-scorecard', compact('submission'));
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
     * (tỉ lệ đúng × điểm tối đa, làm tròn 0,5). Đây là điểm gợi ý — Học vụ xác nhận khi chấm (phần Viết / Nói nhập tay).
     * Đề không có câu nào của kỹ năng này (hoặc không có đáp án) => null, không bịa điểm.
     *
     * @param  array<int, array<string, mixed>>  $questions
     * @param  array<int|string, string>  $answers
     * @param  array<int, string>  $skills
     */
    private function autoGradeSkill(array $questions, array $answers, array $skills, int $maxScore): ?float
    {
        $total = 0;
        $correct = 0;
        foreach ($questions as $idx => $question) {
            $correctAnswer = trim((string) ($question['correct_answer'] ?? ''));
            if (! in_array($question['skill'] ?? '', $skills, true) || $correctAnswer === '') {
                continue;
            }
            $total++;
            $answer = $answers[$question['id'] ?? $idx] ?? '';
            if ($answer !== '' && strcasecmp($answer, $correctAnswer) === 0) {
                $correct++;
            }
        }

        if ($total === 0) {
            return null;
        }

        return round($correct / $total * $maxScore * 2) / 2;
    }

    /**
     * Không tin `customer_id` từ client. Lead chỉ được gắn khi:
     *  (a) nộp qua link có chữ ký (token được server xác minh lại), hoặc
     *  (b) SĐT chuẩn hoá khớp lead đang ở bước tư vấn / hẹn test.
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
        if ($phone === '') {
            return [null, false];
        }

        $matched = CrmCustomer::query()
            ->where('phone_normalized', $phone)
            ->whereIn('stage', CrmCustomer::TEST_ADVANCEABLE_STAGES)
            ->first();

        return [$matched, false];
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
        if ($customer->canAdvanceToTested() || in_array($customer->stage, ['tested', 'result_sent'], true)) {
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
