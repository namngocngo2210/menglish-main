<?php

namespace Tests\Feature;

use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\User;
use App\Services\PlacementPortalLinkService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PlacementPortalSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function makeTest(array $overrides = []): PlacementTest
    {
        return PlacementTest::create(array_merge([
            'code' => 'SEC-TEST-01',
            'title' => 'Đề bảo mật cổng test',
            'target_level' => 'A2 - B1',
            'duration_minutes' => 45,
            'questions_count' => 4,
            'is_active' => true,
            'questions' => [
                ['id' => 1, 'skill' => 'listening', 'type' => 'multiple_choice', 'title' => 'L1', 'options' => [['key' => 'A', 'text' => 'a'], ['key' => 'B', 'text' => 'b']], 'correct_answer' => 'A'],
                ['id' => 2, 'skill' => 'listening', 'type' => 'multiple_choice', 'title' => 'L2', 'options' => [['key' => 'A', 'text' => 'a'], ['key' => 'B', 'text' => 'b']], 'correct_answer' => 'B'],
                ['id' => 3, 'skill' => 'reading', 'type' => 'multiple_choice', 'title' => 'R1', 'options' => [['key' => 'C', 'text' => 'c'], ['key' => 'D', 'text' => 'd']], 'correct_answer' => 'C'],
                ['id' => 4, 'skill' => 'grammar', 'type' => 'fill_blank', 'title' => 'G1', 'correct_answer' => 'had studied'],
            ],
        ], $overrides));
    }

    private function makeLead(string $stage = 'test_scheduled', array $overrides = []): CrmCustomer
    {
        $phone = $overrides['phone'] ?? '0977 888 999';

        return CrmCustomer::create(array_merge([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Lê Thanh Hằng',
            'phone' => $phone,
            'phone_normalized' => CrmCustomer::normalizePhone($phone),
            'email' => 'thanhhang.secret@example.com',
            'dob' => '2012-03-04',
            'parent_name' => 'Phụ huynh Bí Mật',
            'address' => 'Số 99 Phố Kín, Hà Nội',
            'stage' => $stage,
        ], $overrides));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function signedTakeUrl(PlacementTest $test, CrmCustomer $lead): string
    {
        return app(PlacementPortalLinkService::class)->signedLinkForLead($test, $lead);
    }

    private function leadTokenFrom(string $html): string
    {
        $this->assertSame(1, preg_match('/name="lead_token" value="([^"]+)"/', $html, $m));

        return html_entity_decode($m[1]);
    }

    private function submitPayload(array $overrides = []): array
    {
        return array_merge([
            'candidate_name' => 'Thí Sinh Tự Do',
            'candidate_phone' => '0911 000 111',
            'candidate_email' => 'tudo@example.com',
            'answers' => ['1' => 'A', '2' => 'A', '3' => 'C', '4' => 'Had Studied'],
            'writing_content' => 'My essay.',
            'speaking_self_rate' => 'advanced',
        ], $overrides);
    }

    // ── 1. PII leak qua ?lead_id= ───────────────────────────────────

    public function test_unsigned_lead_query_does_not_prefill_lead_pii(): void
    {
        $test = $this->makeTest();
        $lead = $this->makeLead();

        foreach (['lead_id', 'lead'] as $param) {
            $response = $this->get("/portal/placement-test/{$test->code}?{$param}={$lead->id}");
            $response->assertOk();
            $response->assertDontSee('Lê Thanh Hằng');
            $response->assertDontSee('0977 888 999');
            $response->assertDontSee('thanhhang.secret@example.com');
            $response->assertDontSee('lead_token');
        }
    }

    public function test_signed_link_prefills_only_its_own_lead_and_rejects_tampering(): void
    {
        $test = $this->makeTest();
        $lead = $this->makeLead();
        $other = $this->makeLead('consulting', ['name' => 'Người Khác', 'phone' => '0900 111 222', 'email' => 'other@example.com']);

        $url = $this->signedTakeUrl($test, $lead);
        $response = $this->get($url);
        $response->assertOk();
        $response->assertSee('Lê Thanh Hằng');
        $response->assertSee('name="lead_token"', false);

        $tampered = str_replace("lead={$lead->id}", "lead={$other->id}", $url);
        $this->get($tampered)->assertOk()->assertDontSee('Người Khác')->assertDontSee('lead_token');

        $this->travel(8)->days();
        $this->get($url)->assertOk()->assertDontSee('Lê Thanh Hằng')->assertDontSee('lead_token');
    }

    public function test_crm_page_uses_server_signed_link_without_fake_test_code(): void
    {
        $admin = $this->userWithRole('admin');
        $test = $this->makeTest();
        $lead = $this->makeLead('test_scheduled', ['assigned_test_id' => $test->id, 'appointment_at' => now()->addDay()]);

        $response = $this->actingAs($admin)->get(route('crm.customers.show', $lead->id));
        $response->assertOk();
        $response->assertDontSee('TEST-IE-2026');
        $response->assertDontSee('lead_id=', false);
        $response->assertSee('signature=', false);
        $response->assertSee('lead='.$lead->id, false);
    }

    // ── 2. Result hijack ─────────────────────────────────────────────

    public function test_client_supplied_customer_id_is_ignored(): void
    {
        $test = $this->makeTest();
        $victim = $this->makeLead('won');

        $this->post(route('portal.test.submit', $test->code), $this->submitPayload([
            'customer_id' => $victim->id,
        ]))->assertRedirect();

        $submission = PlacementTestSubmission::latest('id')->firstOrFail();
        $this->assertNull($submission->customer_id);
        $victim->refresh();
        $this->assertSame('won', $victim->stage);
        $this->assertNull($victim->test_score);
    }

    public function test_phone_match_attaches_only_consulting_or_test_scheduled_leads(): void
    {
        $test = $this->makeTest();
        $consulting = $this->makeLead('consulting', ['phone' => '0977 888 999']);
        $won = $this->makeLead('won', ['phone' => '0966-555-444', 'email' => 'won@example.com']);

        // SĐT gõ khác định dạng vẫn khớp theo phone_normalized
        $this->post(route('portal.test.submit', $test->code), $this->submitPayload(['candidate_phone' => '0977.888.999']))->assertRedirect();
        $first = PlacementTestSubmission::latest('id')->firstOrFail();
        $this->assertSame($consulting->id, $first->customer_id);
        // BA: nộp bài → "Test"; chỉ khi Học vụ chấm xong mới sang "Đã test".
        $this->assertSame('testing', $consulting->fresh()->stage);
        $this->assertTrue(CrmCustomerHistory::where('customer_id', $consulting->id)->where('type', 'test')->exists());

        $this->post(route('portal.test.submit', $test->code), $this->submitPayload(['candidate_phone' => '0966555444']))->assertRedirect();
        $second = PlacementTestSubmission::latest('id')->firstOrFail();
        $this->assertNull($second->customer_id);
        $this->assertSame('won', $won->fresh()->stage);
    }

    public function test_signed_link_submission_attaches_but_never_moves_lead_backwards(): void
    {
        $test = $this->makeTest();
        $lead = $this->makeLead('won');

        $token = $this->leadTokenFrom($this->get($this->signedTakeUrl($test, $lead))->getContent());

        $this->post(route('portal.test.submit', $test->code), $this->submitPayload([
            'candidate_phone' => '0123 000 000',
            'lead_token' => $token,
        ]))->assertRedirect();

        $submission = PlacementTestSubmission::latest('id')->firstOrFail();
        $this->assertSame($lead->id, $submission->customer_id);
        $lead->refresh();
        $this->assertSame('won', $lead->stage);
        $this->assertTrue(CrmCustomerHistory::where('customer_id', $lead->id)->where('type', 'test')->exists());
    }

    public function test_signed_link_submission_advances_test_scheduled_lead(): void
    {
        $test = $this->makeTest();
        $lead = $this->makeLead('test_scheduled');
        $token = $this->leadTokenFrom($this->get($this->signedTakeUrl($test, $lead))->getContent());

        $this->post(route('portal.test.submit', $test->code), $this->submitPayload(['lead_token' => $token]))->assertRedirect();

        // Mở link → "Test"; nộp bài giữ "Test" chờ Học vụ chấm.
        $this->assertSame('testing', $lead->fresh()->stage);
        $this->assertSame($lead->id, PlacementTestSubmission::latest('id')->value('customer_id'));
    }

    public function test_forged_or_foreign_lead_token_is_ignored(): void
    {
        $test = $this->makeTest();
        $otherTest = $this->makeTest(['code' => 'SEC-TEST-02']);
        $lead = $this->makeLead('won');
        $tokenForOtherTest = $this->leadTokenFrom($this->get($this->signedTakeUrl($otherTest, $lead))->getContent());

        foreach (['not-a-real-token', $tokenForOtherTest] as $token) {
            $this->post(route('portal.test.submit', $test->code), $this->submitPayload(['lead_token' => $token]))->assertRedirect();
            $this->assertNull(PlacementTestSubmission::latest('id')->value('customer_id'));
        }
    }

    public function test_scorecard_shows_only_candidate_input_not_lead_profile(): void
    {
        $test = $this->makeTest();
        $lead = $this->makeLead('consulting');

        $this->post(route('portal.test.submit', $test->code), $this->submitPayload([
            'candidate_name' => 'Tên Thí Sinh Nhập',
            'candidate_phone' => '0977 888 999',
        ]))->assertRedirect();
        $submission = PlacementTestSubmission::latest('id')->firstOrFail();
        $this->assertSame($lead->id, $submission->customer_id);

        $response = $this->get(URL::signedRoute('portal.test.scorecard', ['id' => $submission->id]));
        $response->assertOk();
        $response->assertSee('Tên Thí Sinh Nhập');
        foreach (['Lê Thanh Hằng', 'Phụ huynh Bí Mật', 'Số 99 Phố Kín', '2012-03-04', '04/03/2012', 'Ba Đình, Hà Nội', 'Kinh doanh / Công chức', 'Tiểu học / THCS MEnglish', 'crm/customers'] as $secret) {
            $response->assertDontSee($secret, false);
        }
    }

    // ── 3 & 4. Chấm theo đáp án của đề, bài chờ chấm ────────────────

    public function test_submission_is_graded_against_stored_keys_and_awaits_academic_grading(): void
    {
        User::factory()->create();
        $test = $this->makeTest();

        $this->post(route('portal.test.submit', $test->code), $this->submitPayload([
            // khóa đáp án cứng cũ phải bị bỏ qua
            'listening_answers' => ['q1' => 'B', 'q2' => 'A', 'q3' => 'C', 'q4' => 'A', 'q5' => 'D'],
            'reading_answers' => ['q1' => 'C', 'q2' => 'B', 'q3' => 'A', 'q4' => 'D', 'q5' => 'B'],
        ]))->assertRedirect();

        $submission = PlacementTestSubmission::latest('id')->firstOrFail();
        // Đề không thuộc khối có thang điểm → thang tạm /10: điểm = tỉ lệ đúng × 10 (làm tròn 0,5).
        $this->assertSame('khac', $submission->grade_group);
        $this->assertEquals(5.0, (float) $submission->listening_score); // 1/2 đúng
        $this->assertEquals(10.0, (float) $submission->reading_score);  // 2/2 đúng (không phân biệt hoa thường)
        $this->assertNull($submission->writing_score);
        $this->assertNull($submission->speaking_score);
        $this->assertNull($submission->overall_score);
        $this->assertNull($submission->cefr_level);
        $this->assertSame('pending', $submission->status);
        $this->assertNull($submission->grader_id);
        $this->assertSame('A', $submission->answers['1']);
        $this->assertSame('Had Studied', $submission->answers['4']);
        $this->assertSame('advanced', $submission->answers['speaking_self_rate']);
        $this->assertSame('My essay.', $submission->writing_content);
    }

    public function test_test_without_questions_gets_no_fabricated_scores_and_shows_up_for_grading(): void
    {
        $academic = $this->userWithRole('academic_staff');
        $test = $this->makeTest(['questions' => null]);

        $this->post(route('portal.test.submit', $test->code), $this->submitPayload([
            'listening_answers' => ['q1' => 'B'],
        ]))->assertRedirect();

        $submission = PlacementTestSubmission::latest('id')->firstOrFail();
        $this->assertNull($submission->listening_score);
        $this->assertNull($submission->reading_score);

        $this->actingAs($academic)->get(route('placement-tests.index'))->assertOk()->assertSee('Thí Sinh Tự Do')->assertSee('Chờ chấm');

        $result = $this->actingAs($academic)->get(route('placement-tests.results.show', $submission->id));
        $result->assertOk();
        $this->assertSame([], $result->viewData('questions'));
        $result->assertDontSee('Melbourne International Airport');
    }

    // ── 5. Tra cứu đề ─────────────────────────────────────────────────

    public function test_unknown_partial_or_inactive_test_code_returns_404_without_creating_rows(): void
    {
        $this->makeTest(['code' => 'SEC-TEST-01']);
        $this->makeTest(['code' => 'SEC-OFF', 'is_active' => false]);
        $countBefore = PlacementTest::count();

        $this->get('/portal/placement-test/DOES-NOT-EXIST')->assertNotFound();
        $this->get('/portal/placement-test/SEC-TEST')->assertNotFound();
        $this->get('/portal/placement-test/SEC-OFF')->assertNotFound();
        $this->post('/portal/placement-test/DOES-NOT-EXIST/submit', $this->submitPayload())->assertNotFound();
        $this->post('/portal/placement-test/SEC-OFF/submit', $this->submitPayload())->assertNotFound();

        $this->assertSame($countBefore, PlacementTest::count());
        $this->assertSame(0, PlacementTestSubmission::count());
    }

    // ── 6. Hardening ─────────────────────────────────────────────────

    public function test_public_submit_is_rate_limited(): void
    {
        $test = $this->makeTest();

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('portal.test.submit', $test->code), $this->submitPayload())->assertRedirect();
        }

        $this->post(route('portal.test.submit', $test->code), $this->submitPayload())->assertStatus(429);
    }

    public function test_duplicate_test_is_post_only(): void
    {
        $lead = $this->userWithRole('academic_lead');
        $test = $this->makeTest();

        $this->actingAs($lead)->get("/placement-tests/{$test->id}/duplicate")->assertStatus(405);
        $this->assertSame(1, PlacementTest::count());

        $this->actingAs($lead)->post(route('placement-tests.duplicate', $test->id))->assertRedirect();
        $this->assertSame(2, PlacementTest::count());
    }

    // ── 7. Quyền chấm điểm ───────────────────────────────────────────

    public function test_only_admin_manager_and_academic_staff_can_grade_results(): void
    {
        $test = $this->makeTest();
        $submission = PlacementTestSubmission::create([
            'placement_test_id' => $test->id,
            'candidate_name' => 'Chờ Chấm',
            'candidate_phone' => '0900000000',
            'status' => 'pending',
        ]);
        $payload = [
            'grade_group' => 'khoi_2_3', 'listening_score' => 6, 'reading_writing_score' => 6, 'speaking_score' => 6,
        ];

        foreach (['sales_consultant', 'teacher', 'teacher_fulltime', 'teacher_parttime'] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->get(route('placement-tests.results.show', $submission->id))->assertForbidden();
            $this->actingAs($user)->post(route('placement-tests.results.update', $submission->id), $payload)->assertForbidden();
        }

        foreach (['admin', 'manager', 'academic_staff'] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->post(route('placement-tests.results.update', $submission->id), $payload)
                ->assertRedirect(route('placement-tests.results.show', $submission->id));
            $this->assertSame($user->id, $submission->fresh()->grader_id);
        }
    }

    public function test_manager_sees_placement_menu_and_can_open_it(): void
    {
        $manager = $this->userWithRole('manager');

        $this->actingAs($manager)->get(route('placement-tests.index'))->assertOk();
        // IX-4: Đề test là tab của workspace "Test đầu vào & học thử" (sidebar trỏ tới tab đầu tiên).
        $this->actingAs($manager)->get(route('dashboard'))->assertSee('data-menu-item="trial"', false);
        $menuRoutes = collect(app(\App\Support\Navigation\SidebarMenu::class)->groupsFor($manager->fresh()))
            ->flatMap(fn (array $g) => collect($g['items'])->pluck('route'));
        $this->assertContains('placement-tests.index', $menuRoutes);

        $sales = $this->userWithRole('sales_consultant');
        $this->actingAs($sales)->get(route('placement-tests.index'))->assertForbidden();
    }

    public function test_grading_result_never_moves_lead_backwards_and_logs_history(): void
    {
        // Phase 1: Học vụ chỉ chấm bài của khách thuộc chi nhánh mình (phạm vi CRM).
        $branch = \App\Models\Branch::create(['name' => 'Cơ sở chấm', 'code' => 'CHAM', 'is_active' => true]);
        $academic = $this->userWithRole('academic_staff');
        $academic->update(['branch_id' => $branch->id]);
        $test = $this->makeTest();
        $won = $this->makeLead('won', ['branch_id' => $branch->id]);
        $scheduled = $this->makeLead('test_scheduled', ['phone' => '0911 222 333', 'email' => 'b@example.com', 'branch_id' => $branch->id]);
        $payload = [
            'grade_group' => 'khoi_2_3', 'listening_score' => 6, 'reading_writing_score' => 7, 'speaking_score' => 7,
        ];

        foreach ([$won, $scheduled] as $lead) {
            $submission = PlacementTestSubmission::create([
                'placement_test_id' => $test->id,
                'customer_id' => $lead->id,
                'candidate_name' => $lead->name,
                'candidate_phone' => $lead->phone,
                'status' => 'pending',
            ]);
            $this->actingAs($academic)->post(route('placement-tests.results.update', $submission->id), $payload)->assertRedirect();
            $this->assertTrue(CrmCustomerHistory::where('customer_id', $lead->id)->where('type', 'test')->exists());
        }

        $this->assertSame('won', $won->fresh()->stage);
        $this->assertSame('tested', $scheduled->fresh()->stage);
        // 6 + 7 + 7 = 20/40 → băng 20 - 30 của Khối 2 lên 3.
        $this->assertSame('20/40 · STARTERS (FAM 1 _ UNIT 6 - 10)', $scheduled->fresh()->test_score);
    }

    public function test_show_result_unknown_id_is_404(): void
    {
        $academic = $this->userWithRole('academic_staff');
        $test = $this->makeTest();
        $submission = PlacementTestSubmission::create([
            'placement_test_id' => $test->id,
            'candidate_name' => 'Ai Đó',
            'candidate_phone' => '0900000000',
            'status' => 'pending',
        ]);

        $this->actingAs($academic)->get(route('placement-tests.results.show', 999999))->assertNotFound();
        $this->actingAs($academic)->get(route('placement-tests.results.show', $submission->id))->assertOk()
            ->assertSee('name="grade_group"', false);
    }

    // ── 8. Nhập điểm từ CRM ──────────────────────────────────────────

    public function test_crm_save_test_score_forbidden_for_sales_and_teachers(): void
    {
        $test = $this->makeTest();
        foreach (['sales_consultant', 'teacher'] as $role) {
            $user = $this->userWithRole($role);
            $lead = $this->makeLead('consulting', [
                'phone' => '09'.random_int(10000000, 99999999),
                'email' => null,
                'assigned_user_id' => $user->id,
            ]);
            $this->actingAs($user)->post(route('crm.customers.save-test-score', $lead->id), [
                'placement_test_id' => $test->id, 'grade_group' => 'khoi_2_3',
                'listening_score' => 6, 'reading_writing_score' => 6, 'speaking_score' => 6,
            ])->assertForbidden();
        }
        $this->assertSame(0, PlacementTestSubmission::count());
    }

    public function test_crm_save_test_score_keeps_missing_values_null_and_requires_test(): void
    {
        // Quản lý cơ sở chỉ thấy lead thuộc chi nhánh của mình.
        $branch = \App\Models\Branch::create(['name' => 'Cơ sở test', 'code' => 'CST', 'is_active' => true]);
        $manager = $this->userWithRole('manager');
        $manager->update(['branch_id' => $branch->id]);
        $this->makeTest();
        $lead = $this->makeLead('consulting', ['branch_id' => $branch->id]);

        // Không có đề: không được lấy đại PlacementTest::first()
        $this->actingAs($manager)->post(route('crm.customers.save-test-score', $lead->id), [
            'grade_group' => 'khoi_1_2', 'listening_score' => 6, 'reading_writing_score' => 8, 'speaking_score' => 7,
        ])->assertSessionHasErrors('placement_test_id');
        $this->assertSame(0, PlacementTestSubmission::count());

        $test = PlacementTest::firstOrFail();
        $this->actingAs($manager)->post(route('crm.customers.save-test-score', $lead->id), [
            'placement_test_id' => $test->id, 'grade_group' => 'khoi_1_2',
            'listening_score' => 6, 'reading_writing_score' => 8, 'speaking_score' => 7,
        ])->assertSessionHasNoErrors();

        $submission = PlacementTestSubmission::where('customer_id', $lead->id)->firstOrFail();
        $this->assertNull($submission->writing_score);
        $this->assertNull($submission->cefr_level);
        $this->assertEquals(21.0, (float) $submission->total_score);
        $this->assertSame('21/35 · STARTERS (FAM 1 _ TỪ BÀI 5 - 10)', $lead->fresh()->test_score);
    }

    public function test_crm_score_modal_has_no_fabricated_defaults(): void
    {
        $admin = $this->userWithRole('admin');
        $lead = $this->makeLead('consulting');

        $response = $this->actingAs($admin)->get(route('crm.customers.show', $lead->id));
        $response->assertOk();
        $html = $response->getContent();
        // Ô điểm dùng x-model, giá trị khởi tạo trống (không bịa điểm mặc định).
        $this->assertStringContainsString('\\u0022listening\\u0022:\\u0022\\u0022', $html);
        $this->assertStringContainsString('\\u0022speaking\\u0022:\\u0022\\u0022', $html);
        $this->assertStringNotContainsString('name="cefr_level"', $html);
        $response->assertDontSee('IELTS 6.5 Intensive');
        $response->assertDontSee('Học viên có phản xạ nói tự nhiên, vốn từ cơ bản tốt.');
    }
}
