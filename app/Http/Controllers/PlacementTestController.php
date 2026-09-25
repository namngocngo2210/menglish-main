<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\User;
use App\Services\NotificationService;
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
        $tests = PlacementTest::withCount('submissions')->latest()->get();

        $submissionsQuery = PlacementTestSubmission::with(['test', 'grader', 'customer'])->latest();

        $selectedTest = null;
        if ($request->filled('test_id')) {
            $submissionsQuery->where('placement_test_id', $request->input('test_id'));
            $selectedTest = PlacementTest::find($request->input('test_id'));
        }

        $recentSubmissions = $submissionsQuery->take(50)->get();

        $stats = [
            'total_tests' => $tests->count(),
            'preset_tests' => $tests->where('is_preset', true)->count(),
            'custom_tests' => $tests->where('is_preset', false)->count(),
            'total_submissions' => PlacementTestSubmission::count(),
            'avg_duration' => round($tests->avg('duration_minutes') ?: 0),
        ];

        return view('placement-tests.index', compact('tests', 'recentSubmissions', 'selectedTest', 'stats'));
    }

    public function create()
    {
        return view('placement-tests.create');
    }

    public function storeTest(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:placement_tests,code|max:50',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'target_level' => 'required|string|max:255',
            'duration_minutes' => 'required|integer|min:10',
            'questions_count' => 'nullable|integer|min:1',
            'questions' => 'nullable',
        ]);

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
            'is_active' => true,
        ]);

        return redirect()->route('placement-tests.index')
            ->with('status', "Đã tạo đề kiểm tra trình độ {$test->title} ({$test->code}) thành công!");
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

        $title = $test->title;
        $test->submissions()->delete();
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

    public function showResult($id)
    {
        $submission = PlacementTestSubmission::with(['test', 'grader', 'customer', 'student'])
            ->where('id', $id)
            ->first();

        if (! $submission) {
            $submission = PlacementTestSubmission::with(['test', 'grader', 'customer', 'student'])->firstOrFail();
        }

        $test = $submission->test;
        $questions = is_array($test?->questions) && ! empty($test->questions) ? $test->questions : [
            [
                'id' => 1,
                'type' => 'multiple_choice',
                'skill' => 'listening',
                'title' => "What is the passenger's final destination in the conversation?",
                'points' => 1,
                'options' => [
                    ['key' => 'A', 'text' => 'London Heathrow'],
                    ['key' => 'B', 'text' => 'Melbourne International Airport'],
                    ['key' => 'C', 'text' => 'Tokyo Narita'],
                    ['key' => 'D', 'text' => 'Singapore Changi'],
                ],
                'passage' => 'Listen to the audio clip at Customer Service Desk.',
                'audio_url' => '/uploads/2026/dethitest/de-test-lop-6-len-7/track-1-4-20260819105025-7k9aa.mp3',
                'explanation' => 'The passenger confirms connecting flight to Melbourne.',
                'correct_answer' => 'B',
            ],
            [
                'id' => 2,
                'type' => 'multiple_choice',
                'skill' => 'reading',
                'title' => 'According to the passage, what is the primary benefit of renewable energy?',
                'points' => 1,
                'options' => [
                    ['key' => 'A', 'text' => 'It eliminates the need for power grids'],
                    ['key' => 'B', 'text' => 'It significantly reduces greenhouse gas emissions'],
                    ['key' => 'C', 'text' => 'It requires no initial capital investment'],
                    ['key' => 'D', 'text' => 'It operates without any maintenance'],
                ],
                'passage' => 'Renewable energy sources, such as solar and wind power, emit little to no greenhouse gases during operation. In addition, they decrease reliance on finite fossil fuel reserves and stimulate local job growth in clean tech sectors.',
                'explanation' => 'The passage explicitly states that renewable energy emits little to no greenhouse gases.',
                'correct_answer' => 'B',
            ],
            [
                'id' => 3,
                'type' => 'fill_blank',
                'skill' => 'grammar',
                'title' => 'Complete the sentence: If she _____ (study) harder last month, she would have passed the IELTS exam.',
                'points' => 1,
                'explanation' => 'Third conditional structure: If + S + had + V3/ed, S + would have + V3/ed.',
                'correct_answer' => 'had studied',
            ],
            [
                'id' => 4,
                'type' => 'essay',
                'skill' => 'writing',
                'title' => 'Writing Task: Some people believe that studying online is more effective than traditional classroom learning. Discuss both views and give your opinion.',
                'points' => 9,
                'min_words' => 120,
                'rubric_note' => 'Chấm theo tiêu chí: Task Response, Coherence & Cohesion, Lexical Resource, Grammar Accuracy.',
            ],
            [
                'id' => 5,
                'type' => 'speaking_prompt',
                'skill' => 'speaking',
                'title' => 'Speaking Part 2: Describe a memorable journey or trip you took.',
                'points' => 9,
                'cue_points' => "• Where you went and who you went with\n• How you travelled there\n• What you did during the trip\n• And explain why this trip was so memorable for you",
            ],
        ];

        $graders = User::all();

        return view('placement-tests.result', compact('submission', 'graders', 'questions'));
    }

    public function updateResult(Request $request, $id)
    {
        $submission = PlacementTestSubmission::findOrFail($id);

        $validated = $request->validate([
            'listening_score' => 'required|numeric|min:0|max:9',
            'reading_score' => 'required|numeric|min:0|max:9',
            'writing_score' => 'required|numeric|min:0|max:9',
            'speaking_score' => 'required|numeric|min:0|max:9',
            'cefr_level' => 'required|string|max:255',
            'recommended_course' => 'nullable|string|max:255',
            'teacher_comments' => 'nullable|string|max:5000',
        ]);

        $submission->fill($validated);
        $submission->calculateOverall();

        if (empty($validated['teacher_comments']) || empty($validated['recommended_course'])) {
            $eval = PlacementRubricService::evaluate(
                $submission->test?->code ?? 'TEST-GENERAL',
                (float) $submission->listening_score,
                (float) $submission->reading_score,
                (float) $submission->writing_score,
                (float) $submission->speaking_score,
                (float) $submission->overall_score
            );
            if (empty($validated['teacher_comments'])) {
                $submission->teacher_comments = $eval['teacher_comments'];
            }
            if (empty($validated['recommended_course'])) {
                $submission->recommended_course = $eval['recommended_course'];
            }
        }

        $submission->grader_id = Auth::id();
        $submission->status = 'graded';
        $submission->save();

        // Cập nhật ngược lại CRM Lead nếu có
        if ($submission->customer) {
            $submission->customer->update([
                'test_score' => "{$submission->overall_score} ({$submission->cefr_level})",
                'stage' => 'tested',
            ]);
        }

        return redirect()->route('placement-tests.results.show', $submission->id)
            ->with('status', "Đã chấm và lưu kết quả bài test (Overall: {$submission->overall_score} - {$submission->cefr_level})!");
    }

    public function rubricGuide()
    {
        return view('placement-tests.rubric-guide');
    }

    // ─────────────────────────────────────────────────────────────
    // CỔNG LÀM BÀI TRỰC TUYẾN CHO LEAD / HỌC VIÊN
    // ─────────────────────────────────────────────────────────────

    public function portalTakeTest($code, Request $request)
    {
        $test = PlacementTest::where('code', $code)
            ->orWhere('id', $code)
            ->orWhere('code', 'LIKE', "%{$code}%")
            ->first();

        if (! $test) {
            $test = PlacementTest::where('is_active', true)->first() ?? PlacementTest::first();
        }

        if (! $test) {
            $test = PlacementTest::create([
                'code' => $code ?: 'TEST-01',
                'title' => 'Đề Kiểm Tra Trình Độ 4 Kỹ Năng - Standard 2026',
                'target_level' => 'Tổng hợp A1 - B2',
                'duration_minutes' => 45,
                'questions_count' => 5,
                'is_active' => true,
            ]);
        }

        $lead = null;
        if ($customerId = $request->query('lead_id')) {
            $lead = CrmCustomer::find($customerId);
        }

        return view('placement-tests.portal-take', compact('test', 'lead'));
    }

    public function portalSubmitTest($code, Request $request)
    {
        $test = PlacementTest::where('code', $code)
            ->orWhere('id', $code)
            ->orWhere('code', 'LIKE', "%{$code}%")
            ->first();

        if (! $test) {
            $test = PlacementTest::where('is_active', true)->first() ?? PlacementTest::first();
        }

        if (! $test) {
            $test = PlacementTest::create([
                'code' => $code ?: 'TEST-01',
                'title' => 'Đề Kiểm Tra Trình Độ 4 Kỹ Năng - Standard 2026',
                'target_level' => 'Tổng hợp A1 - B2',
                'duration_minutes' => 45,
                'questions_count' => 5,
                'is_active' => true,
            ]);
        }

        if ($request->isMethod('GET')) {
            return redirect()->route('portal.test.take', $test->code);
        }

        $validated = $request->validate([
            'candidate_name' => 'required|string|max:255',
            'candidate_phone' => 'required|string|max:20',
            'candidate_email' => 'nullable|email|max:255',
            'answers' => 'nullable|array',
            'listening_answers' => 'nullable|array',
            'reading_answers' => 'nullable|array',
            'writing_content' => 'nullable|string|max:5000',
            'speaking_self_rate' => 'nullable|string',
            'customer_id' => 'nullable|exists:crm_customers,id',
        ]);

        $submittedAnswers = $validated['answers'] ?? [];
        $questions = is_array($test->questions) ? $test->questions : [];

        // 1. Chấm điểm Listening tự động
        $listeningKey = ['q1' => 'B', 'q2' => 'A', 'q3' => 'C', 'q4' => 'A', 'q5' => 'D'];
        if (! empty($validated['listening_answers'])) {
            $listeningCorrect = 0;
            foreach ($listeningKey as $q => $ans) {
                if (isset($validated['listening_answers'][$q]) && $validated['listening_answers'][$q] === $ans) {
                    $listeningCorrect++;
                }
            }
            $listeningScore = match ($listeningCorrect) {
                5 => 8.5,
                4 => 7.0,
                3 => 5.5,
                2 => 4.5,
                1 => 3.5,
                default => 2.5,
            };
        } else {
            $listeningTotal = 0;
            $listeningCorrect = 0;
            foreach ($questions as $idx => $q) {
                if (($q['skill'] ?? '') === 'listening') {
                    $listeningTotal++;
                    $qId = $q['id'] ?? $idx;
                    $userAns = trim((string) ($submittedAnswers[$qId] ?? ''));
                    $correctAns = trim((string) ($q['correct_answer'] ?? ''));
                    if ($userAns !== '' && strcasecmp($userAns, $correctAns) === 0) {
                        $listeningCorrect++;
                    }
                }
            }
            if ($listeningTotal > 0) {
                $ratio = $listeningCorrect / $listeningTotal;
                $listeningScore = match (true) {
                    $ratio >= 0.9 => 8.5,
                    $ratio >= 0.75 => 7.0,
                    $ratio >= 0.6 => 5.5,
                    $ratio >= 0.4 => 4.5,
                    $ratio >= 0.2 => 3.5,
                    default => 2.5,
                };
            } else {
                $listeningScore = 5.0;
            }
        }

        // 2. Chấm điểm Reading & Grammar tự động
        $readingKey = ['q1' => 'C', 'q2' => 'B', 'q3' => 'A', 'q4' => 'D', 'q5' => 'B'];
        if (! empty($validated['reading_answers'])) {
            $readingCorrect = 0;
            foreach ($readingKey as $q => $ans) {
                if (isset($validated['reading_answers'][$q]) && $validated['reading_answers'][$q] === $ans) {
                    $readingCorrect++;
                }
            }
            $readingScore = match ($readingCorrect) {
                5 => 8.5,
                4 => 7.0,
                3 => 5.5,
                2 => 4.5,
                1 => 3.5,
                default => 2.5,
            };
        } else {
            $readingTotal = 0;
            $readingCorrect = 0;
            foreach ($questions as $idx => $q) {
                if (in_array($q['skill'] ?? '', ['reading', 'grammar'])) {
                    $readingTotal++;
                    $qId = $q['id'] ?? $idx;
                    $userAns = trim((string) ($submittedAnswers[$qId] ?? ''));
                    $correctAns = trim((string) ($q['correct_answer'] ?? ''));
                    if ($userAns !== '' && strcasecmp($userAns, $correctAns) === 0) {
                        $readingCorrect++;
                    }
                }
            }
            if ($readingTotal > 0) {
                $ratio = $readingCorrect / $readingTotal;
                $readingScore = match (true) {
                    $ratio >= 0.9 => 8.5,
                    $ratio >= 0.75 => 7.0,
                    $ratio >= 0.6 => 5.5,
                    $ratio >= 0.4 => 4.5,
                    $ratio >= 0.2 => 3.5,
                    default => 2.5,
                };
            } else {
                $readingScore = 5.0;
            }
        }

        // 3. Chấm điểm Writing sơ bộ tự động theo heuristic độ dài & độ phức tạp
        $wordCount = str_word_count($validated['writing_content'] ?? '');
        $writingScore = match (true) {
            $wordCount >= 100 => 6.5,
            $wordCount >= 60 => 5.5,
            $wordCount >= 30 => 4.5,
            $wordCount >= 10 => 3.5,
            default => 2.5,
        };

        // 4. Điểm Speaking ước tính
        $speakingScore = match ($validated['speaking_self_rate'] ?? 'intermediate') {
            'advanced' => 7.0,
            'intermediate' => 5.5,
            'beginner' => 4.0,
            default => 5.0,
        };

        // 5. Tính Overall & Đánh giá năng lực theo Thang điểm chuẩn MEnglish (Rubric Service)
        $overallScore = round(($listeningScore + $readingScore + $writingScore + $speakingScore) / 4, 1);

        $evaluation = PlacementRubricService::evaluate(
            $test->code,
            $listeningScore,
            $readingScore,
            $writingScore,
            $speakingScore,
            $overallScore
        );

        $cefrLevel = $evaluation['cefr_level'];
        $recommendedCourse = $evaluation['recommended_course'];
        $teacherComments = $evaluation['teacher_comments'];

        // Tìm Lead CRM nếu có
        $customer = null;
        if (! empty($validated['customer_id'])) {
            $customer = CrmCustomer::find($validated['customer_id']);
        } elseif (! empty($validated['candidate_phone'])) {
            $customer = CrmCustomer::where('phone', $validated['candidate_phone'])->first();
        }

        // Tạo Submission bài làm
        $graderId = Auth::id() ?? User::first()?->id;

        $submission = PlacementTestSubmission::create([
            'placement_test_id' => $test->id,
            'customer_id' => $customer?->id,
            'candidate_name' => $validated['candidate_name'],
            'candidate_phone' => $validated['candidate_phone'],
            'candidate_email' => $validated['candidate_email'] ?? $customer?->email,
            'listening_score' => $listeningScore,
            'reading_score' => $readingScore,
            'writing_score' => $writingScore,
            'speaking_score' => $speakingScore,
            'overall_score' => $overallScore,
            'cefr_level' => $cefrLevel,
            'writing_content' => $validated['writing_content'] ?? null,
            'recommended_course' => $recommendedCourse,
            'teacher_comments' => $teacherComments,
            'grader_id' => $graderId,
            'status' => 'graded',
        ]);

        // Cập nhật CRM Lead
        if ($customer) {
            $customer->update([
                'test_score' => "{$overallScore} ({$cefrLevel})",
                'stage' => 'tested',
            ]);

            CrmCustomerHistory::create([
                'customer_id' => $customer->id,
                'user_id' => Auth::id() ?? $customer->assigned_user_id ?? $graderId,
                'type' => 'test',
                'content' => "Học viên đã nộp bài test trực tuyến [{$test->title}]: Đạt {$overallScore} Band ({$cefrLevel}) · Khóa đề xuất: {$recommendedCourse}",
            ]);
        }

        // Bắn email thông báo học vụ nộp bài / kiểm tra
        try {
            app(NotificationService::class)->notifyHomeworkOrTestSubmission($submission);
        } catch (\Throwable $e) {
            Log::warning('Lỗi gửi email thông báo học viên nộp bài: '.$e->getMessage());
        }

        // Scorecard là trang public nên bắt buộc link có chữ ký — chống dò id tuần tự
        return redirect()->to(URL::signedRoute('portal.test.scorecard', ['id' => $submission->id]))
            ->with('status', 'Hoàn thành bài thi! Hệ thống đã tự động chấm điểm và đánh giá trình độ của bạn.');
    }

    public function portalScorecard($id)
    {
        $submission = PlacementTestSubmission::with(['test', 'customer'])->findOrFail($id);

        return view('placement-tests.portal-scorecard', compact('submission'));
    }
}
