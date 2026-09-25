<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\User;
use App\Services\PlacementRubricService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chấm test đầu vào theo khối lớp (BA chốt Q2 — "Thang điểm + hướng dẫn nhận xét"):
 * Tổng = Nghe + Đọc&Viết + Nói, tra tổng → lớp đề xuất, nhận xét gợi ý theo băng, chọn lại lớp được,
 * không còn quy đổi CEFR; khối chưa có thang điểm → chọn lớp thủ công.
 */
class PlacementRubricScoringTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $academic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cơ sở thang điểm', 'code' => 'TD', 'is_active' => true]);
        $this->academic = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->academic->assignRole('academic_staff');
    }

    public function test_grade_groups_scales_and_total_to_class_bands_follow_the_spec(): void
    {
        $this->assertSame(['listening' => 10, 'reading_writing' => 15, 'speaking' => 10], PlacementRubricService::maxScores('khoi_1_2'));
        $this->assertSame(['listening' => 15, 'reading_writing' => 15, 'speaking' => 10], PlacementRubricService::maxScores('khoi_2_3'));
        $this->assertSame(['listening' => 15, 'reading_writing' => 20, 'speaking' => 10], PlacementRubricService::maxScores('khoi_3_4'));
        $this->assertSame(['listening' => 15, 'reading_writing' => 15, 'speaking' => 10], PlacementRubricService::maxScores('khoi_4_5'));

        $cases = [
            ['khoi_1_2', 9.5, 'PRE STARTERS (FAM 0)'],
            ['khoi_1_2', 10, 'STARTERS (FAM 1 _ Ở NHỮNG BÀI ĐẦU)'],
            ['khoi_1_2', 15, 'STARTERS (FAM 1 _ Ở NHỮNG BÀI ĐẦU)'],
            ['khoi_1_2', 16, 'STARTERS (FAM 1 _ TỪ BÀI 5 - 10)'],
            ['khoi_1_2', 25, 'STARTERS (FAM 1 _ TỪ BÀI 5 - 10)'],
            ['khoi_2_3', 5, 'PRE STARTERS _ FAM 1 (TỪ ĐẦU _ DƯỚI U5)'],
            ['khoi_2_3', 19.5, 'PRE STARTERS _ FAM 1 (TỪ ĐẦU _ DƯỚI U5)'],
            ['khoi_2_3', 20, 'STARTERS (FAM 1 _ UNIT 6 - 10)'],
            ['khoi_2_3', 30, 'STARTERS (FAM 1 _ UNIT 6 - 10)'],
            ['khoi_2_3', 30.5, 'STARTERS (FAM 1 _ UNIT 7 - 12)'],
            ['khoi_3_4', 19, 'FAM 2 (NỬA ĐẦU)'],
            ['khoi_3_4', 35, 'FAM 2 (NỬA SAU)'],
            ['khoi_3_4', 36, 'Luyện MOVERS'],
            ['khoi_4_5', 20, 'FAM 2 (NỬA SAU)'],
            ['khoi_4_5', 31, 'Luyện MOVERS'],
        ];
        foreach ($cases as [$group, $total, $class]) {
            $this->assertSame($class, PlacementRubricService::suggestClass($group, $total), "{$group} tổng {$total}");
        }

        $this->assertNull(PlacementRubricService::suggestClass(PlacementRubricService::MANUAL_GROUP, 30));
        $this->assertFalse(PlacementRubricService::hasRubric(PlacementRubricService::MANUAL_GROUP));
    }

    public function test_skill_comments_are_picked_by_band(): void
    {
        $this->assertStringStartsWith('Con bắt đầu hình thành kĩ năng nghe', PlacementRubricService::skillComment('khoi_1_2', 'listening', 4.5));
        $this->assertStringStartsWith('Con đã có kĩ năng nghe cơ bản', PlacementRubricService::skillComment('khoi_1_2', 'listening', 5));
        $this->assertStringStartsWith('Con nghe tốt', PlacementRubricService::skillComment('khoi_1_2', 'listening', 8));
        $this->assertStringStartsWith('Con nhận diện các từ đơn cơ bản', PlacementRubricService::skillComment('khoi_3_4', 'reading_writing', 6.5));
        $this->assertStringStartsWith('Con có kiến thức cơ bản', PlacementRubricService::skillComment('khoi_3_4', 'reading_writing', 7));
        $this->assertStringStartsWith('Con có nền từ vựng và cấu trúc khá', PlacementRubricService::skillComment('khoi_3_4', 'reading_writing', 15));
        $this->assertNull(PlacementRubricService::skillComment(PlacementRubricService::MANUAL_GROUP, 'speaking', 9));
    }

    public function test_grade_group_is_detected_from_test_code(): void
    {
        $this->assertSame('khoi_1_2', PlacementRubricService::detectGradeGroup('TEST-G1-G2'));
        $this->assertSame('khoi_2_3', PlacementRubricService::detectGradeGroup('TEST-G2-G3'));
        $this->assertSame('khoi_3_4', PlacementRubricService::detectGradeGroup('TEST-SPEAKING-G3-G4'));
        $this->assertSame('khoi_4_5', PlacementRubricService::detectGradeGroup('TEST-G4-G5'));
        foreach (['TEST-G5-G6-CB', 'TEST-G8-G9', 'TEST-IE-2026', 'TEST-SPEAKING-PRE-G1', null] as $code) {
            $this->assertSame(PlacementRubricService::MANUAL_GROUP, PlacementRubricService::detectGradeGroup($code));
        }
    }

    public function test_crm_score_entry_validates_per_skill_max_and_requires_speaking(): void
    {
        [$lead, $test] = $this->leadWithTest();

        $this->actingAs($this->academic)->post(route('crm.customers.save-test-score', $lead), [
            'placement_test_id' => $test->id, 'grade_group' => 'khoi_1_2',
            'listening_score' => 11, 'reading_writing_score' => 16, 'speaking_score' => 10.5,
        ])->assertSessionHasErrors(['listening_score', 'reading_writing_score', 'speaking_score']);

        $this->actingAs($this->academic)->post(route('crm.customers.save-test-score', $lead), [
            'placement_test_id' => $test->id, 'grade_group' => 'khoi_1_2',
            'listening_score' => 8, 'reading_writing_score' => 12,
        ])->assertSessionHasErrors('speaking_score');

        // Cùng điểm Nghe 11 hợp lệ ở khối có thang Nghe /15.
        $this->actingAs($this->academic)->post(route('crm.customers.save-test-score', $lead), [
            'placement_test_id' => $test->id, 'grade_group' => 'khoi_2_3',
            'listening_score' => 11, 'reading_writing_score' => 12, 'speaking_score' => 8,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->academic)->post(route('crm.customers.save-test-score', $lead), [
            'placement_test_id' => $test->id, 'grade_group' => 'invalid',
            'listening_score' => 1, 'reading_writing_score' => 1, 'speaking_score' => 1,
        ])->assertSessionHasErrors('grade_group');

        // Thang cũ (0–100, CEFR) không còn được chấp nhận.
        $this->actingAs($this->academic)->post(route('crm.customers.save-test-score', $lead), [
            'placement_test_id' => $test->id, 'grade_group' => 'khoi_2_3',
            'listening_score' => 60, 'reading_writing_score' => 60, 'speaking_score' => 60, 'cefr_level' => 'B1',
        ])->assertSessionHasErrors(['listening_score', 'reading_writing_score', 'speaking_score']);
        $this->assertSame(1, PlacementTestSubmission::count());
        $this->assertNull(PlacementTestSubmission::first()->cefr_level);
    }

    public function test_suggested_and_chosen_class_are_both_stored_and_comments_can_be_edited(): void
    {
        [$lead, $test] = $this->leadWithTest();

        $this->actingAs($this->academic)->post(route('crm.customers.save-test-score', $lead), [
            'placement_test_id' => $test->id, 'grade_group' => 'khoi_2_3',
            'listening_score' => 12, 'reading_writing_score' => 12, 'speaking_score' => 8,
            'chosen_class' => 'STARTERS (FAM 1 _ UNIT 6 - 10)',
            'speaking_comment' => 'Con nói tốt nhưng còn ngại, cần thêm môi trường giao tiếp.',
        ])->assertSessionHasNoErrors();

        $submission = PlacementTestSubmission::where('customer_id', $lead->id)->firstOrFail();
        $this->assertEquals(32.0, (float) $submission->total_score);
        $this->assertSame('STARTERS (FAM 1 _ UNIT 7 - 12)', $submission->suggested_class);
        $this->assertSame('STARTERS (FAM 1 _ UNIT 6 - 10)', $submission->chosen_class);
        $this->assertTrue($submission->classWasOverridden());
        // Nhận xét Nghe / Đọc&Viết để trống → lấy gợi ý theo băng; Nói giữ nội dung người chấm sửa.
        $this->assertStringStartsWith('Con nghe khá, quen với một số các dạng nghe cơ bản', $submission->listening_comment);
        $this->assertStringStartsWith('Con có nền từ khá tốt', $submission->reading_writing_comment);
        $this->assertSame('Con nói tốt nhưng còn ngại, cần thêm môi trường giao tiếp.', $submission->speaking_comment);
        $this->assertSame('tested', $lead->fresh()->stage);
        $this->assertDatabaseHas('crm_customer_histories', ['customer_id' => $lead->id, 'type' => 'test']);
        $this->assertStringContainsString('lớp đề xuất theo thang điểm: STARTERS (FAM 1 _ UNIT 7 - 12)', $lead->histories()->where('type', 'test')->latest('id')->value('content'));

        $this->actingAs($this->academic)->get(route('crm.customers.show', $lead))->assertOk()
            ->assertSee('Kết quả test đầu vào')
            ->assertSee('32')
            ->assertSee('Con nói tốt nhưng còn ngại')
            ->assertSee('Học vụ đã chọn lại');
    }

    public function test_group_without_rubric_requires_manual_class_and_shows_notice(): void
    {
        [$lead, $test] = $this->leadWithTest();
        $payload = [
            'placement_test_id' => $test->id, 'grade_group' => PlacementRubricService::MANUAL_GROUP,
            'listening_score' => 7, 'reading_writing_score' => 6, 'speaking_score' => 8,
        ];

        $this->actingAs($this->academic)->post(route('crm.customers.save-test-score', $lead), $payload)
            ->assertSessionHasErrors('chosen_class');
        $this->actingAs($this->academic)->post(route('crm.customers.save-test-score', $lead), $payload + ['chosen_class' => 'IELTS Foundation'])
            ->assertSessionHasNoErrors();

        $submission = PlacementTestSubmission::where('customer_id', $lead->id)->firstOrFail();
        $this->assertNull($submission->suggested_class);
        $this->assertSame('IELTS Foundation', $submission->chosen_class);
        $this->assertNull($submission->listening_comment);

        $this->actingAs($this->academic)->get(route('crm.customers.show', $lead))->assertOk()
            ->assertSee('Chưa có thang điểm — Học thuật chọn lớp thủ công');
    }

    public function test_results_screen_grades_online_submission_on_the_rubric_scale(): void
    {
        $test = PlacementTest::create([
            'code' => 'TEST-G1-G2', 'title' => 'Đề lớp 1-2', 'is_active' => true, 'duration_minutes' => 30,
            'questions' => [
                ['id' => 1, 'skill' => 'listening', 'type' => 'multiple_choice', 'title' => 'L1', 'correct_answer' => 'A'],
                ['id' => 2, 'skill' => 'listening', 'type' => 'multiple_choice', 'title' => 'L2', 'correct_answer' => 'B'],
                ['id' => 3, 'skill' => 'reading', 'type' => 'multiple_choice', 'title' => 'R1', 'correct_answer' => 'C'],
            ],
        ]);

        $this->post(route('portal.test.submit', $test->code), [
            'candidate_name' => 'Bé Na', 'candidate_phone' => '0901234567', 'answers' => ['1' => 'A', '2' => 'A', '3' => 'C'],
        ])->assertRedirect();

        $submission = PlacementTestSubmission::latest('id')->firstOrFail();
        // Tự chấm quy về thang khối 1-2: Nghe 1/2 × 10 = 5; phần Đọc 1/1 × 15 = 15 (gợi ý cho ô Đọc & Viết).
        $this->assertSame('khoi_1_2', $submission->grade_group);
        $this->assertEquals(5.0, (float) $submission->listening_score);
        $this->assertEquals(15.0, (float) $submission->reading_score);
        $this->assertSame('pending', $submission->status);

        $this->actingAs($this->academic)->get(route('placement-tests.results.show', $submission->id))->assertOk()
            ->assertSee('Chấm điểm theo thang điểm khối lớp');

        $this->actingAs($this->academic)->post(route('placement-tests.results.update', $submission->id), [
            'grade_group' => 'khoi_1_2', 'listening_score' => 5, 'reading_writing_score' => 11, 'speaking_score' => 11,
        ])->assertSessionHasErrors('speaking_score');

        $this->actingAs($this->academic)->post(route('placement-tests.results.update', $submission->id), [
            'grade_group' => 'khoi_1_2', 'listening_score' => 5, 'reading_writing_score' => 11, 'speaking_score' => 7,
        ])->assertRedirect(route('placement-tests.results.show', $submission->id));

        $submission->refresh();
        $this->assertSame('graded', $submission->status);
        $this->assertEquals(23.0, (float) $submission->total_score);
        $this->assertSame('STARTERS (FAM 1 _ TỪ BÀI 5 - 10)', $submission->suggested_class);
        $this->assertSame('STARTERS (FAM 1 _ TỪ BÀI 5 - 10)', $submission->chosen_class);
        $this->assertNull($submission->cefr_level);
    }

    public function test_sales_and_teachers_cannot_enter_scores_or_comments(): void
    {
        [$lead, $test] = $this->leadWithTest();
        foreach (['sales_consultant', 'teacher'] as $role) {
            $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
            $user->assignRole($role);
            $lead->update(['assigned_user_id' => $user->id]);
            $this->actingAs($user)->post(route('crm.customers.save-test-score', $lead), [
                'placement_test_id' => $test->id, 'grade_group' => 'khoi_2_3',
                'listening_score' => 5, 'reading_writing_score' => 5, 'speaking_score' => 5, 'listening_comment' => 'x',
            ])->assertForbidden();
        }
        $this->assertSame(0, PlacementTestSubmission::count());
    }

    /** @return array{0: CrmCustomer, 1: PlacementTest} */
    private function leadWithTest(): array
    {
        $test = PlacementTest::create(['code' => 'TEST-G2-G3', 'title' => 'Đề lớp 2-3', 'is_active' => true, 'duration_minutes' => 30]);
        $lead = CrmCustomer::create([
            'code' => CrmCustomer::generateCode(), 'name' => 'Khách chấm điểm', 'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => $this->branch->id, 'stage' => 'consulting', 'assigned_test_id' => $test->id,
        ]);

        return [$lead, $test];
    }
}
