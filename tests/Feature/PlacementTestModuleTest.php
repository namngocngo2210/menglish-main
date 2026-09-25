<?php

namespace Tests\Feature;

use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\User;
use App\Services\PlacementRubricService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SpeakingTestsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PlacementTestModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    public function test_can_create_test_and_grade_submission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('academic_lead');

        $response = $this->actingAs($user)->post('/placement-tests', [
            'code' => 'TEST-NEW',
            'title' => 'Đề thi Tuyển sinh Quý 4',
            'target_level' => 'IELTS 5.5 - 6.5',
            'duration_minutes' => 60,
            'questions_count' => 50,
        ]);

        $response->assertRedirect(route('placement-tests.index'));
        $this->assertDatabaseHas('placement_tests', ['code' => 'TEST-NEW']);

        $test = PlacementTest::where('code', 'TEST-NEW')->first();
        $submission = PlacementTestSubmission::create([
            'placement_test_id' => $test->id,
            'candidate_name' => 'Ngô Bảo Châu',
            'candidate_phone' => '0912 333 444',
            'listening_score' => 6.0,
            'reading_score' => 6.0,
            'writing_score' => 5.5,
            'speaking_score' => 6.5,
            'overall_score' => 6.0,
            'cefr_level' => 'B2',
            'status' => 'pending',
        ]);

        // Đề IELTS / người lớn: chưa có thang điểm khối lớp → Học thuật chọn lớp thủ công.
        $this->actingAs($user)->post("/placement-tests/results/{$submission->id}", [
            'grade_group' => 'khac',
            'listening_score' => 6.5,
            'reading_writing_score' => 6.5,
            'speaking_score' => 7.0,
            'teacher_comments' => 'Rất tốt, đủ điều kiện vào học ngay.',
        ])->assertSessionHasErrors('chosen_class');

        $this->actingAs($user)->post("/placement-tests/results/{$submission->id}", [
            'grade_group' => 'khac',
            'listening_score' => 6.5,
            'reading_writing_score' => 6.5,
            'speaking_score' => 7.0,
            'chosen_class' => 'IELTS 6.5 Intensive',
            'teacher_comments' => 'Rất tốt, đủ điều kiện vào học ngay.',
        ])->assertSessionHasNoErrors();

        $submission->refresh();
        $this->assertEquals(20.0, (float) $submission->total_score);
        $this->assertNull($submission->suggested_class);
        $this->assertSame('IELTS 6.5 Intensive', $submission->chosen_class);
        $this->assertEquals('graded', $submission->status);
    }

    public function test_portal_take_test_and_submit(): void
    {
        $test = PlacementTest::create([
            'code' => 'TEST-IE-2026',
            'title' => 'Đề Test Đầu Vào IELTS Intensive 2026',
            'target_level' => 'IELTS 5.0 - 6.5',
            'duration_minutes' => 60,
            'questions_count' => 2,
            'is_active' => true,
            'questions' => [
                ['id' => 1, 'skill' => 'listening', 'type' => 'multiple_choice', 'title' => 'L1', 'options' => [['key' => 'A', 'text' => 'a'], ['key' => 'B', 'text' => 'b']], 'correct_answer' => 'B'],
                ['id' => 2, 'skill' => 'reading', 'type' => 'multiple_choice', 'title' => 'R1', 'options' => [['key' => 'C', 'text' => 'c'], ['key' => 'D', 'text' => 'd']], 'correct_answer' => 'C'],
            ],
        ]);

        // Link công khai (không chữ ký) chỉ hiển thị form trống.
        $response = $this->get('/portal/placement-test/TEST-IE-2026');
        $response->assertOk();
        $response->assertSee('Đề Test Đầu Vào IELTS Intensive 2026');

        $submitResponse = $this->post('/portal/placement-test/TEST-IE-2026/submit', [
            'candidate_name' => 'Nguyễn Minh Anh',
            'candidate_phone' => '0988 123 456',
            'candidate_email' => 'minhanh@gmail.com',
            'answers' => ['1' => 'B', '2' => 'D'],
            'writing_content' => 'Learning English is very important because it helps people travel, study abroad, and get better jobs in international companies.',
            'speaking_self_rate' => 'intermediate',
        ]);

        $submitResponse->assertRedirect();
        $this->assertDatabaseHas('placement_test_submissions', [
            'candidate_name' => 'Nguyễn Minh Anh',
            'placement_test_id' => $test->id,
            'status' => 'pending',
        ]);
    }

    public function test_can_view_test_details_and_duplicate_test(): void
    {
        $user = User::factory()->create();
        $user->assignRole('academic_lead');

        $test = PlacementTest::create([
            'code' => 'TEST-G1-G2',
            'title' => 'Đề Test Đánh Giá Năng Lực Đầu Vào Lớp 1 Lên Lớp 2',
            'target_level' => 'Tiểu học Pre-A1',
            'duration_minutes' => 35,
            'questions_count' => 16,
            'is_active' => true,
            'is_preset' => true,
        ]);

        // 1. Can view test details page
        $viewResponse = $this->actingAs($user)->get(route('placement-tests.show', $test->id));
        $viewResponse->assertOk();
        $viewResponse->assertSee('TEST-G1-G2');
        $viewResponse->assertSee('Đề Test Đánh Giá Năng Lực');

        // 2. Can duplicate preset test into editable copy via POST
        $responseDuplicate = $this->actingAs($user)->post(route('placement-tests.duplicate', $test->id));
        $responseDuplicate->assertRedirect();

        $copy = PlacementTest::where('is_preset', false)->latest()->first();
        $this->assertNotNull($copy);
        $this->assertStringContainsString('Bản sao tùy biến', $copy->title);
        $this->assertFalse($copy->is_preset);

        // 3. GET không được phép tạo bản sao (tránh CSRF qua link/ảnh)
        $this->actingAs($user)->get(route('placement-tests.duplicate', $test->id))->assertStatus(405);
    }

    public function test_speaking_tests_and_rubric_evaluation(): void
    {
        $this->seed(SpeakingTestsSeeder::class);

        $this->assertDatabaseHas('placement_tests', [
            'code' => 'TEST-SPEAKING-PRE-G1',
            'is_preset' => true,
        ]);
        $this->assertDatabaseHas('placement_tests', [
            'code' => 'TEST-SPEAKING-G4-G6',
            'is_preset' => true,
        ]);

        $group = PlacementRubricService::detectGradeGroup('TEST-SPEAKING-G3-G4');
        $this->assertSame('khoi_3_4', $group);
        $eval = PlacementRubricService::evaluate($group, 13, 17, 8);

        $this->assertEquals(38.0, $eval['total']);
        $this->assertSame(45, $eval['max_total']);
        $this->assertSame('Luyện MOVERS', $eval['suggested_class']);
        $this->assertStringContainsString('level Movers', $eval['comments']['listening']);
        $this->assertStringContainsString('mô tả tranh khá', $eval['comments']['speaking']);
    }

    public function test_portal_submit_speaking_pre_g1_and_redirect_to_scorecard(): void
    {
        $this->seed(SpeakingTestsSeeder::class);

        $response = $this->post('/portal/placement-test/TEST-SPEAKING-PRE-G1/submit', [
            'candidate_name' => 'Bé Mai An',
            'candidate_phone' => '0987 654 321',
            'candidate_email' => 'maian@gmail.com',
            'speaking_self_rate' => 'beginner',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('placement_test_submissions', [
            'candidate_name' => 'Bé Mai An',
            'candidate_phone' => '0987 654 321',
        ]);

        $sub = PlacementTestSubmission::where('candidate_name', 'Bé Mai An')->first();
        $this->assertNotNull($sub);
        // Speaking do Học vụ chấm: bài chờ chấm, không tự sinh CEFR / khóa đề xuất.
        $this->assertSame('pending', $sub->status);
        $this->assertNull($sub->speaking_score);
        $this->assertNull($sub->cefr_level);

        // Check scorecard page (public route yêu cầu URL có chữ ký)
        $scorecardResp = $this->get(URL::signedRoute('portal.test.scorecard', ['id' => $sub->id]));
        $scorecardResp->assertOk();
        $scorecardResp->assertSee('Bé Mai An');
        $scorecardResp->assertSee('BẢN ĐÁNH GIÁ NĂNG LỰC TIẾNG ANH');

        // Check GET submit graceful redirect
        $getSubmitResp = $this->get('/portal/placement-test/TEST-SPEAKING-PRE-G1/submit');
        $getSubmitResp->assertRedirect(route('portal.test.take', 'TEST-SPEAKING-PRE-G1'));
    }

    public function test_can_view_detailed_result_with_answer_comparison_and_explanations(): void
    {
        $user = User::factory()->create();
        $user->assignRole('academic_lead');
        $test = PlacementTest::create([
            'code' => 'TEST-COMPARE-01',
            'title' => 'Đề Thi Đối Chiếu Đáp Án Chuẩn',
            'duration_minutes' => 45,
            'is_active' => true,
            'questions' => [
                [
                    'id' => 1,
                    'type' => 'multiple_choice',
                    'skill' => 'listening',
                    'title' => 'What is the passenger destination?',
                    'points' => 1,
                    'options' => [
                        ['key' => 'A', 'text' => 'London Heathrow'],
                        ['key' => 'B', 'text' => 'Melbourne International Airport'],
                    ],
                    'explanation' => 'The passenger confirmed connecting flight to Melbourne.',
                    'correct_answer' => 'B',
                ],
                [
                    'id' => 2,
                    'type' => 'fill_blank',
                    'skill' => 'grammar',
                    'title' => 'Complete sentence: If she _____ (study) harder.',
                    'points' => 1,
                    'explanation' => 'Third conditional structure.',
                    'correct_answer' => 'had studied',
                ],
            ],
        ]);

        $submission = PlacementTestSubmission::create([
            'placement_test_id' => $test->id,
            'candidate_name' => 'Micheal Owen',
            'candidate_phone' => '0832575905',
            'listening_score' => 6.5,
            'reading_score' => 6.0,
            'writing_score' => 6.5,
            'speaking_score' => 7.0,
            'overall_score' => 6.5,
            'cefr_level' => 'B2',
            'answers' => [
                1 => 'B',
                2 => 'had studied',
            ],
            'writing_content' => 'Sample IELTS writing essay body text.',
            'status' => 'graded',
        ]);

        $response = $this->actingAs($user)->get(route('placement-tests.results.show', $submission->id));
        $response->assertOk();
        $response->assertSee('Đối Chiếu Chi Tiết Từng Câu Hỏi &amp; Đáp Án Thí Sinh Đã Chọn', false);
        $response->assertSee('What is the passenger destination?');
        $response->assertSee('Melbourne International Airport');
        $response->assertSee('The passenger confirmed connecting flight to Melbourne.');
        $response->assertSee('Thí sinh chọn (Đúng)');
    }

    public function test_can_create_test_with_full_questions_payload_via_form(): void
    {
        $user = User::factory()->create();
        $user->assignRole('academic_lead');

        $questionsPayload = [
            [
                'id' => 1,
                'skill' => 'listening',
                'type' => 'multiple_choice',
                'section' => 'A. LISTENING',
                'title' => 'Where is the train heading?',
                'options' => [
                    ['key' => 'A', 'text' => 'Manchester'],
                    ['key' => 'B', 'text' => 'Birmingham'],
                ],
                'correct_answer' => 'A',
                'points' => 1,
                'explanation' => 'The conductor announced Manchester express.',
            ],
            [
                'id' => 2,
                'skill' => 'reading',
                'type' => 'multiple_choice',
                'section' => 'B. READING',
                'title' => 'What is the main idea of paragraph 1?',
                'passage' => 'Global warming has accelerated polar ice melting.',
                'options' => [
                    ['key' => 'A', 'text' => 'Environmental degradation'],
                    ['key' => 'B', 'text' => 'Economic growth'],
                ],
                'correct_answer' => 'A',
                'points' => 1,
                'explanation' => 'Paragraph 1 focuses on climate changes.',
            ],
        ];

        $response = $this->actingAs($user)->post(route('placement-tests.store'), [
            'code' => 'TEST-BUILDER-99',
            'title' => 'Đề Test Builder Mới 2026',
            'duration_minutes' => 45,
            'target_level' => 'Lớp 3-4 (Movers)',
            'description' => 'Mô tả bài kiểm tra',
            'questions' => json_encode($questionsPayload),
            'questions_count' => count($questionsPayload),
        ]);

        $response->assertRedirect(route('placement-tests.index'));
        $this->assertDatabaseHas('placement_tests', [
            'code' => 'TEST-BUILDER-99',
            'title' => 'Đề Test Builder Mới 2026',
            'questions_count' => 2,
        ]);

        $created = PlacementTest::where('code', 'TEST-BUILDER-99')->first();
        $this->assertCount(2, $created->questions);
        $this->assertEquals('Where is the train heading?', $created->questions[0]['title']);
        $this->assertEquals('Global warming has accelerated polar ice melting.', $created->questions[1]['passage']);
    }
}
