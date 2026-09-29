<?php

namespace Tests\Feature;

use App\Http\Controllers\PlacementTestController;
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
 * Tự chấm bài test đầu vào khi thí sinh nộp: Nghe và Đọc & Viết theo đáp án của đề, nhận xét từng kỹ năng theo
 * băng điểm; kết quả là bản nháp để Admin / Học vụ sửa và xác nhận. Thí sinh chỉ thấy lời chúc mừng, không thấy điểm.
 */
class PlacementAutoGradeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $academic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cơ sở tự chấm', 'code' => 'TC', 'is_active' => true]);
        $this->academic = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->academic->assignRole('academic_staff');
    }

    public function test_submission_auto_grades_reading_writing_and_drafts_comments_without_showing_results(): void
    {
        [$lead, $test] = $this->leadWithTest();

        $response = $this->post(route('portal.test.submit', $test->code), [
            'candidate_name' => $lead->name, 'candidate_phone' => $lead->phone,
            // Nghe 2/2; Đọc & Viết: R1 đúng (1đ), G1 sai (1đ), W1 điền từ đúng dù khác hoa thường / dấu chấm (2đ) → 3/4.
            'answers' => ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'A', '5' => ' Big  Dog. '],
            'writing_content' => 'My favourite animal is a dog.',
        ]);

        $response->assertRedirect(route('portal.test.done', $test->code));
        $this->followRedirects($response)->assertOk()
            ->assertSee('Chúc mừng con đã hoàn thiện bài test')
            ->assertDontSee('LISTENING')
            ->assertDontSee('Đề xuất');

        $submission = PlacementTestSubmission::latest('id')->firstOrFail();
        // Khối 2 lên 3: Nghe /15, Đọc & Viết /15.
        $this->assertSame($lead->id, $submission->customer_id);
        $this->assertSame('khoi_2_3', $submission->grade_group);
        $this->assertEquals(15.0, (float) $submission->listening_score);
        $this->assertEquals(11.5, (float) $submission->reading_writing_score); // 3/4 × 15 = 11.25 → 11.5
        $this->assertSame(PlacementRubricService::skillComment('khoi_2_3', 'listening', 15), $submission->listening_comment);
        $this->assertSame(PlacementRubricService::skillComment('khoi_2_3', 'reading_writing', 11.5), $submission->reading_writing_comment);
        $this->assertNull($submission->speaking_score);
        $this->assertNull($submission->speaking_comment);
        $this->assertNull($submission->total_score);
        $this->assertSame(PlacementTestSubmission::STATUS_PENDING, $submission->status);

        // Hồ sơ lead (cổng Admin / Học vụ) thấy ngay điểm + nhận xét nháp, chưa có lớp đề xuất.
        $this->actingAs($this->academic)->get(route('crm.customers.show', $lead->id))->assertOk()
            ->assertSee('data-testid="rubric-draft"', false)
            ->assertSee($submission->reading_writing_comment);
    }

    public function test_academic_staff_can_adjust_the_auto_grade_before_confirming(): void
    {
        [$lead, $test] = $this->leadWithTest();
        $this->post(route('portal.test.submit', $test->code), [
            'candidate_name' => $lead->name, 'candidate_phone' => $lead->phone,
            'answers' => ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'B', '5' => 'big dog'],
        ])->assertRedirect();
        $submission = PlacementTestSubmission::latest('id')->firstOrFail();

        $this->actingAs($this->academic)->get(route('placement-tests.results.show', $submission->id))->assertOk()
            ->assertSee('Hệ thống đã tự chấm theo đáp án của đề');

        $this->actingAs($this->academic)->post(route('placement-tests.results.update', $submission->id), [
            'grade_group' => 'khoi_2_3', 'listening_score' => 14, 'reading_writing_score' => 12, 'speaking_score' => 8,
            'reading_writing_comment' => 'Bài viết còn sai chính tả.',
        ])->assertRedirect(route('placement-tests.results.show', $submission->id));

        $submission->refresh();
        $this->assertSame(PlacementTestSubmission::STATUS_GRADED, $submission->status);
        $this->assertEquals(34.0, (float) $submission->total_score);
        $this->assertSame('Bài viết còn sai chính tả.', $submission->reading_writing_comment);
        $this->assertSame(PlacementRubricService::skillComment('khoi_2_3', 'speaking', 8), $submission->speaking_comment);
        $this->assertSame('STARTERS (FAM 1 _ UNIT 7 - 12)', $submission->suggested_class);
        $this->assertStringContainsString('34/40', (string) $lead->fresh()->test_score);
    }

    public function test_answer_matching_accepts_listed_alternatives(): void
    {
        $this->assertTrue(PlacementTestController::answerMatches('Seven', '7 | seven'));
        $this->assertTrue(PlacementTestController::answerMatches('7', '7|seven'));
        $this->assertTrue(PlacementTestController::answerMatches('  had   studied ', 'had studied.'));
        $this->assertFalse(PlacementTestController::answerMatches('eight', '7 | seven'));
        $this->assertFalse(PlacementTestController::answerMatches('B', 'A'));
    }

    public function test_result_stays_on_customer_after_closing(): void
    {
        [$lead, $test] = $this->leadWithTest();
        $this->post(route('portal.test.submit', $test->code), [
            'candidate_name' => $lead->name, 'candidate_phone' => $lead->phone, 'answers' => ['1' => 'A', '2' => 'B'],
        ])->assertRedirect();
        $submission = PlacementTestSubmission::latest('id')->firstOrFail();
        $this->actingAs($this->academic)->post(route('placement-tests.results.update', $submission->id), [
            'grade_group' => 'khoi_2_3', 'listening_score' => 14, 'reading_writing_score' => 12, 'speaking_score' => 8,
        ])->assertSessionHasNoErrors();
        $lead->update(['stage' => 'won']);

        $this->actingAs($this->academic)->get(route('crm.customers.show', $lead->id))->assertOk()
            ->assertSee('data-testid="rubric-result"', false)
            ->assertSee('Đã làm bài test (34/40', false)
            ->assertDontSee('Chưa có kết quả test đầu vào');
    }

    public function test_closed_customer_without_test_has_no_empty_tested_badge(): void
    {
        [$lead] = $this->leadWithTest();
        $lead->update(['stage' => 'won']);

        $this->actingAs($this->academic)->get(route('crm.customers.show', $lead->id))->assertOk()
            ->assertDontSee('Đã làm bài test')
            ->assertSee('Chưa có kết quả test đầu vào');
    }

    public function test_phone_typed_as_parent_phone_matches_the_lead(): void
    {
        [$lead, $test] = $this->leadWithTest();
        $lead->update(['parent_phone' => '0912 345 678']);

        $this->post(route('portal.test.submit', $test->code), [
            'candidate_name' => 'Bé', 'candidate_phone' => '0912345678', 'answers' => ['1' => 'A'],
        ])->assertRedirect();

        $this->assertSame($lead->id, PlacementTestSubmission::latest('id')->value('customer_id'));
    }

    public function test_academic_staff_links_an_unmatched_submission_to_a_closed_customer(): void
    {
        [$lead, $test] = $this->leadWithTest();
        $lead->update(['stage' => 'won']);
        // Lead đã chốt: nộp qua link công khai không tự gắn (chống gõ SĐT để đè kết quả).
        $this->post(route('portal.test.submit', $test->code), [
            'candidate_name' => $lead->name, 'candidate_phone' => $lead->phone, 'answers' => ['1' => 'A'],
        ])->assertRedirect();
        $submission = PlacementTestSubmission::latest('id')->firstOrFail();
        $this->assertNull($submission->customer_id);

        $this->actingAs($this->academic)->get(route('crm.customers.show', $lead->id))->assertOk()
            ->assertSee('data-testid="unlinked-submissions"', false)
            ->assertSee('Gắn vào khách này');

        $this->actingAs($this->academic)->post(route('crm.customers.link-submission', $lead->id), ['submission_id' => $submission->id])
            ->assertRedirect(route('crm.customers.show', $lead->id));
        $this->assertSame($lead->id, $submission->fresh()->customer_id);
        $this->assertSame('won', $lead->fresh()->stage);

        // Bài đã gắn không gắn lại được sang khách khác.
        $other = CrmCustomer::create([
            'code' => CrmCustomer::generateCode(), 'name' => 'Khách khác', 'phone' => '0987000111', 'branch_id' => $this->branch->id, 'stage' => 'consulting',
        ]);
        $this->actingAs($this->academic)->post(route('crm.customers.link-submission', $other->id), ['submission_id' => $submission->id])
            ->assertSessionHasErrors('submission_id');
        $this->assertSame($lead->id, $submission->fresh()->customer_id);
    }

    public function test_backfill_links_orphan_submission_by_phone(): void
    {
        [$lead, $test] = $this->leadWithTest();
        $submission = PlacementTestSubmission::create([
            'placement_test_id' => $test->id, 'candidate_name' => 'Bé', 'candidate_phone' => $lead->phone,
            'grade_group' => 'khoi_2_3', 'status' => PlacementTestSubmission::STATUS_PENDING,
        ]);
        $lead->update(['stage' => 'won']);

        (require database_path('migrations/2026_10_16_090000_link_orphan_placement_submissions_to_leads.php'))->up();

        $this->assertSame($lead->id, $submission->fresh()->customer_id);
    }

    /** @return array{0: CrmCustomer, 1: PlacementTest} */
    private function leadWithTest(): array
    {
        $test = PlacementTest::create([
            'code' => 'TEST-G2-G3', 'title' => 'Đề lớp 2-3', 'is_active' => true, 'duration_minutes' => 30,
            'questions' => [
                ['id' => 1, 'skill' => 'listening', 'type' => 'multiple_choice', 'title' => 'L1', 'correct_answer' => 'A'],
                ['id' => 2, 'skill' => 'listening', 'type' => 'multiple_choice', 'title' => 'L2', 'correct_answer' => 'B'],
                ['id' => 3, 'skill' => 'reading', 'type' => 'multiple_choice', 'title' => 'R1', 'correct_answer' => 'C', 'points' => 1],
                ['id' => 4, 'skill' => 'grammar', 'type' => 'multiple_choice', 'title' => 'G1', 'correct_answer' => 'B', 'points' => 1],
                ['id' => 5, 'skill' => 'writing', 'type' => 'fill_blank', 'title' => 'W1', 'correct_answer' => 'big dog', 'points' => 2],
                // Bài viết tự luận không có đáp án: không tính vào điểm tự chấm.
                ['id' => 6, 'skill' => 'writing', 'type' => 'essay', 'title' => 'Essay', 'points' => 5],
                ['id' => 7, 'skill' => 'speaking', 'type' => 'speaking_prompt', 'title' => 'S1', 'points' => 9],
            ],
        ]);
        $phone = '09'.random_int(10000000, 99999999);
        $lead = CrmCustomer::create([
            'code' => CrmCustomer::generateCode(), 'name' => 'Bé Tự Chấm', 'phone' => $phone, 'phone_normalized' => CrmCustomer::normalizePhone($phone),
            'branch_id' => $this->branch->id, 'stage' => 'test_scheduled', 'assigned_test_id' => $test->id,
        ]);

        return [$lead, $test];
    }
}
