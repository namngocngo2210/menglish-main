<?php

namespace Tests\Feature;

use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BigTestResultsSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    /** Học thuật: người duyệt kết quả / gửi phụ huynh (big_test.approve). */
    private User $lead;

    private User $teacherA;

    private User $teacherB;

    private ClassModel $classA;

    private ClassModel $classB;

    private Student $studentA;

    private Student $studentB;

    private BigTest $testA;

    private BigTest $testB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        config(['services.zalo.mode' => 'sandbox']);

        $branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-BTS', 'is_active' => true]);
        $this->manager = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->manager->assignRole('manager');
        $this->lead = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->lead->assignRole('academic_lead');
        $this->teacherA = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->teacherA->assignRole('teacher');
        $this->teacherB = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->teacherB->assignRole('teacher');

        $course = Course::create(['name' => 'IELTS BTS', 'code' => 'IELTS-BTS', 'total_lessons' => 24, 'is_active' => true]);
        $this->classA = ClassModel::create([
            'name' => 'Lớp A', 'code' => 'BTS-A', 'course_id' => $course->id, 'program' => $course->name,
            'branch_id' => $branch->id, 'teacher_id' => $this->teacherA->id, 'room' => 'P1', 'status' => 'active',
        ]);
        $this->classB = ClassModel::create([
            'name' => 'Lớp B', 'code' => 'BTS-B', 'course_id' => $course->id, 'program' => $course->name,
            'branch_id' => $branch->id, 'teacher_id' => $this->teacherB->id, 'room' => 'P2', 'status' => 'active',
        ]);
        $this->studentA = Student::create([
            'name' => 'HV Lớp A', 'code' => 'HV-BTS-A', 'phone' => '0901000001', 'parent_phone' => '0911000001',
            'current_class_id' => $this->classA->id, 'branch_id' => $branch->id, 'status' => 'studying',
        ]);
        $this->studentB = Student::create([
            'name' => 'HV Lớp B', 'code' => 'HV-BTS-B', 'phone' => '0901000002', 'parent_phone' => '0911000002',
            'current_class_id' => $this->classB->id, 'branch_id' => $branch->id, 'status' => 'studying',
        ]);
        $this->testA = $this->makeTest($this->classA, 'BT-A', 'PASS-AAAA');
        $this->testB = $this->makeTest($this->classB, 'BT-B', 'PASS-BBBB');
    }

    private function makeTest(ClassModel $class, string $code, string $passcode, bool $distributed = true): BigTest
    {
        return BigTest::create([
            'code' => $code, 'title' => 'Big Test '.$code, 'class_id' => $class->id, 'test_type' => 'midterm',
            'scheduled_at' => now()->addDay(), 'room' => 'Lab', 'passcode' => $passcode,
            'is_distributed' => $distributed, 'status' => $distributed ? 'distributed' : 'draft',
        ]);
    }

    private function scoreRow(Student $student, float $score = 7): array
    {
        return [
            'student_id' => $student->id, 'listening_score' => $score, 'reading_score' => $score,
            'writing_score' => $score, 'speaking_score' => $score, 'progress_note' => 'Tốt',
        ];
    }

    private function makeResult(BigTest $test, Student $student, string $status, bool $notified = false): BigTestResult
    {
        return BigTestResult::create([
            'big_test_id' => $test->id, 'student_id' => $student->id,
            'listening_score' => 8, 'reading_score' => 8, 'writing_score' => 8, 'speaking_score' => 8,
            'overall_score' => 8, 'status' => $status, 'parent_notified' => $notified,
            'notified_at' => $notified ? now()->subDay() : null,
        ]);
    }

    // ---- 1. Cross-class grading ----

    public function test_teacher_cannot_grade_another_teachers_class(): void
    {
        $this->actingAs($this->teacherA)
            ->post(route('syllabus.big-tests.results.store', $this->testB->id), ['results' => [$this->scoreRow($this->studentB)]])
            ->assertNotFound();

        $this->assertDatabaseCount('big_test_results', 0);
    }

    public function test_teacher_can_grade_own_class_and_foreign_teacher_too(): void
    {
        $this->actingAs($this->teacherA)
            ->post(route('syllabus.big-tests.results.store', $this->testA->id), ['results' => [$this->scoreRow($this->studentA)]])
            ->assertRedirect();
        $this->assertDatabaseHas('big_test_results', ['student_id' => $this->studentA->id, 'status' => 'pending_review']);

        $foreign = User::factory()->create(['is_active' => true]);
        $foreign->assignRole('teacher');
        $this->classB->update(['foreign_teacher_id' => $foreign->id]);
        $this->actingAs($foreign)
            ->post(route('syllabus.big-tests.results.store', $this->testB->id), ['results' => [$this->scoreRow($this->studentB)]])
            ->assertRedirect();
        $this->assertDatabaseHas('big_test_results', ['student_id' => $this->studentB->id, 'graded_by' => $foreign->id]);
    }

    public function test_academic_role_can_grade_any_class(): void
    {
        $this->actingAs($this->lead)
            ->post(route('syllabus.big-tests.results.store', $this->testB->id), ['results' => [$this->scoreRow($this->studentB)]])
            ->assertRedirect();
        $this->assertDatabaseHas('big_test_results', ['student_id' => $this->studentB->id]);
    }

    public function test_results_page_only_lists_and_opens_own_class_tests_for_teacher(): void
    {
        $this->actingAs($this->teacherA)->get(route('syllabus.big-tests.results', $this->testB->id))->assertNotFound();

        $response = $this->actingAs($this->teacherA)->get(route('syllabus.big-tests.results'));
        $response->assertOk();
        $response->assertSee('[BT-A]', false);
        $response->assertDontSee('[BT-B]', false);

        $this->actingAs($this->lead)->get(route('syllabus.big-tests.results'))
            ->assertOk()->assertSee('[BT-A]', false)->assertSee('[BT-B]', false);
    }

    // ---- 2 & 3. Zalo send: no fake phone, idempotent, failure-aware ----

    public function test_send_zalo_skips_students_without_phone_and_never_uses_fallback_number(): void
    {
        // Không có SĐT phụ huynh (hồ sơ HV + khách CRM) → bỏ qua, KHÔNG gửi vào SĐT của chính học viên.
        $this->studentA->update(['parent_phone' => null]);
        $result = $this->makeResult($this->testA, $this->studentA, 'approved');

        $response = $this->actingAs($this->lead)->post(route('syllabus.big-tests.send-zalo', $this->testA->id));
        $response->assertRedirect();
        $response->assertSessionHas('warning', fn ($msg) => str_contains($msg, 'HV Lớp A') && str_contains($msg, 'Thiếu SĐT phụ huynh'));
        $this->actingAs($this->lead)->get(route('syllabus.big-tests.results', $this->testA->id))->assertOk()->assertSee('Thiếu SĐT phụ huynh');

        $result->refresh();
        $this->assertFalse($result->parent_notified);
        $this->assertSame('approved', $result->status);
    }

    public function test_send_zalo_only_sends_unsent_results_and_marks_them_sent(): void
    {
        $other = Student::create([
            'name' => 'HV Đã gửi', 'code' => 'HV-BTS-C', 'phone' => '0901000003', 'parent_phone' => '0911000003',
            'current_class_id' => $this->classA->id, 'status' => 'studying',
        ]);
        $sentAt = now()->subDay()->startOfSecond();
        $alreadySent = $this->makeResult($this->testA, $other, 'sent', true);
        $alreadySent->update(['notified_at' => $sentAt]);
        $pending = $this->makeResult($this->testA, $this->studentA, 'approved');

        $this->actingAs($this->lead)->post(route('syllabus.big-tests.send-zalo', $this->testA->id))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($msg) => str_contains($msg, '1'));

        $pending->refresh();
        $this->assertTrue($pending->parent_notified);
        $this->assertSame('sent', $pending->status);
        $this->assertNotNull($pending->notified_at);
        $this->assertTrue($alreadySent->fresh()->notified_at->equalTo($sentAt));
    }

    public function test_send_zalo_does_not_mark_notified_when_provider_fails(): void
    {
        config(['services.zalo.mode' => 'live', 'services.zalo.access_token' => 'test-token']);
        Http::fake(['*' => Http::response(['error' => -124, 'message' => 'Invalid'], 200)]);
        $result = $this->makeResult($this->testA, $this->studentA, 'approved');

        $this->actingAs($this->lead)->post(route('syllabus.big-tests.send-zalo', $this->testA->id))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $result->refresh();
        $this->assertFalse($result->parent_notified);
        $this->assertSame('approved', $result->status);
        $this->assertNull($result->notified_at);
    }

    public function test_single_send_is_idempotent_and_failure_aware(): void
    {
        $sent = $this->makeResult($this->testA, $this->studentA, 'sent', true);
        $this->actingAs($this->lead)->post(route('syllabus.big-tests.send-single-zalo', $sent->id))
            ->assertRedirect()->assertSessionHas('error');

        $noPhone = $this->makeResult($this->testB, $this->studentB, 'approved');
        $this->studentB->update(['parent_phone' => ' ']);
        $this->actingAs($this->lead)->post(route('syllabus.big-tests.send-single-zalo', $noPhone->id))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertFalse($noPhone->fresh()->parent_notified);

        $this->studentB->update(['parent_phone' => '0911000002']);
        config(['services.zalo.mode' => 'live', 'services.zalo.access_token' => 'test-token']);
        Http::fake(['*' => Http::response(['error' => -1], 200)]);
        $this->actingAs($this->lead)->post(route('syllabus.big-tests.send-single-zalo', $noPhone->id))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertFalse($noPhone->fresh()->parent_notified);

        Http::fake(['*' => Http::response(['error' => 0], 200)]);
        config(['services.zalo.mode' => 'sandbox']);
        $this->actingAs($this->lead)->post(route('syllabus.big-tests.send-single-zalo', $noPhone->id))
            ->assertRedirect()->assertSessionHas('status');
        $this->assertSame('sent', $noPhone->fresh()->status);
        $this->assertTrue($noPhone->fresh()->parent_notified);
    }

    public function test_parent_phone_falls_back_to_crm_customer_parent_phone(): void
    {
        $this->studentA->update(['parent_phone' => null]);
        \App\Models\CrmCustomer::create(['code' => 'KH-BTS-1', 'name' => 'HV Lớp A', 'phone' => '0901000001', 'phone_normalized' => '0901000001',
            'parent_phone' => '0987654321', 'stage' => 'won', 'converted_student_id' => $this->studentA->id]);
        config(['services.zalo.mode' => 'live', 'services.zalo.access_token' => 'test-token']);
        Http::fake(['*' => Http::response(['error' => 0], 200)]);
        $result = $this->makeResult($this->testA, $this->studentA, 'approved');

        $this->actingAs($this->lead)->post(route('syllabus.big-tests.send-single-zalo', $result->id))->assertSessionHas('status');
        Http::assertSent(fn ($request) => $request['phone'] === '84987654321');
        $this->assertTrue($result->fresh()->parent_notified);

        // SĐT phụ huynh trên hồ sơ học viên được ưu tiên hơn khách CRM.
        $this->studentA->update(['parent_phone' => '0911222333']);
        $this->assertSame('0911222333', $this->studentA->fresh()->parentContactPhone());
    }

    public function test_only_academic_lead_and_admin_approve_or_send_results(): void
    {
        $result = $this->makeResult($this->testA, $this->studentA, 'pending_review');
        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('academic_staff');

        foreach ([$this->manager, $staff] as $user) {
            $this->assertFalse($user->can('big_test.approve'));
            $this->assertFalse($user->can('syllabus.approve_adjustment'));
            $this->actingAs($user)->post(route('syllabus.big-tests.results.approve', $this->testA->id))->assertForbidden();
            $this->actingAs($user)->post(route('syllabus.big-tests.send-zalo', $this->testA->id))->assertForbidden();
            $this->actingAs($user)->post(route('syllabus.big-tests.results.approve-send', $result->id))->assertForbidden();
            $this->actingAs($user)->get(route('syllabus.big-tests.results', $this->testA->id))->assertOk()
                ->assertDontSee(route('syllabus.big-tests.results.approve', $this->testA->id));
        }
        $this->assertSame('pending_review', $result->fresh()->status);
        $this->assertTrue($this->lead->can('big_test.approve'));
        $this->assertTrue($this->lead->can('syllabus.approve_adjustment'));
    }

    // ---- 4. Regrading locked results / blank rows ----

    public function test_cannot_regrade_approved_or_sent_results(): void
    {
        $result = $this->makeResult($this->testA, $this->studentA, 'approved');

        $this->actingAs($this->teacherA)
            ->post(route('syllabus.big-tests.results.store', $this->testA->id), ['results' => [$this->scoreRow($this->studentA, 2)]])
            ->assertRedirect()->assertSessionHas('error');

        $result->refresh();
        $this->assertSame('approved', $result->status);
        $this->assertEquals(8.0, (float) $result->listening_score);
    }

    public function test_blank_rows_are_skipped_instead_of_stored_as_zero(): void
    {
        $absent = Student::create([
            'name' => 'HV Vắng', 'code' => 'HV-BTS-V', 'phone' => '0901000009',
            'current_class_id' => $this->classA->id, 'status' => 'studying',
        ]);

        $this->actingAs($this->teacherA)->post(route('syllabus.big-tests.results.store', $this->testA->id), [
            'results' => [
                $this->scoreRow($this->studentA),
                ['student_id' => $absent->id, 'listening_score' => '', 'reading_score' => '', 'writing_score' => '', 'speaking_score' => '', 'progress_note' => ''],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('big_test_results', ['student_id' => $this->studentA->id]);
        $this->assertDatabaseMissing('big_test_results', ['student_id' => $absent->id]);
    }

    public function test_partially_filled_row_is_rejected(): void
    {
        $this->actingAs($this->teacherA)->post(route('syllabus.big-tests.results.store', $this->testA->id), [
            'results' => [['student_id' => $this->studentA->id, 'listening_score' => 7, 'reading_score' => '', 'writing_score' => '', 'speaking_score' => '']],
        ])->assertSessionHasErrors('results.0.reading_score');

        $this->assertDatabaseCount('big_test_results', 0);
    }

    // ---- 5. Passcode exposure ----

    public function test_passcode_hidden_from_unassigned_teachers(): void
    {
        $draftA = $this->makeTest($this->classA, 'BT-A-DRAFT', 'PASS-DRAFT', false);

        foreach (['syllabus.big-tests.schedules', 'syllabus.big-tests.distribution'] as $route) {
            $this->actingAs($this->teacherA)->get(route($route))
                ->assertOk()
                ->assertSee('PASS-AAAA')
                ->assertDontSee('PASS-BBBB')
                ->assertDontSee($draftA->passcode);

            $this->actingAs($this->lead)->get(route($route))
                ->assertOk()->assertSee('PASS-AAAA')->assertSee('PASS-BBBB')->assertSee('PASS-DRAFT');
        }
    }

    // ---- 6. UI ----

    public function test_results_page_shows_vietnamese_status_and_hides_academic_buttons_for_teacher(): void
    {
        $this->makeResult($this->testA, $this->studentA, 'pending_review');

        $teacherView = $this->actingAs($this->teacherA)->get(route('syllabus.big-tests.results', $this->testA->id));
        $teacherView->assertOk()
            ->assertSee('Chờ duyệt')
            ->assertDontSee('>pending_review<', false)
            ->assertDontSee(route('syllabus.big-tests.results.approve', $this->testA->id))
            ->assertDontSee(route('syllabus.big-tests.send-zalo', $this->testA->id))
            ->assertDontSee('/ 9.0');

        $this->actingAs($this->lead)->get(route('syllabus.big-tests.results', $this->testA->id))
            ->assertOk()
            ->assertSee(route('syllabus.big-tests.results.approve', $this->testA->id))
            ->assertSee(route('syllabus.big-tests.send-zalo', $this->testA->id));
    }

    public function test_results_page_without_tests_has_no_grade_form(): void
    {
        BigTest::query()->delete();

        $this->actingAs($this->lead)->get(route('syllabus.big-tests.results'))
            ->assertOk()
            ->assertDontSee('action="#"', false);
    }
}
