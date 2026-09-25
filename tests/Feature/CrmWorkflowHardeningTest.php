<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\MerchandiseItem;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CrmWorkflowHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $salesA;

    private User $salesB;

    private ClassModel $classModel;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở CRM', 'code' => 'CRM', 'is_active' => true]);
        $this->salesA = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->salesA->assignRole('sales_consultant');
        $this->salesB = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->salesB->assignRole('sales_consultant');

        $course = Course::create([
            'code' => 'SEC-COURSE', 'name' => 'Khóa chuẩn', 'tuition_fee' => 15000000, 'is_active' => true,
        ]);
        $this->classModel = ClassModel::create([
            'code' => 'SEC-CLASS', 'name' => 'Lớp chuẩn', 'course_id' => $course->id,
            'branch_id' => $this->branch->id, 'max_capacity' => 2, 'status' => 'active',
        ]);
        $this->bank = BankAccount::create([
            'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '999999',
            'account_holder' => 'MENGLISH', 'branch_id' => $this->branch->id,
            'is_default_vietqr' => true, 'is_active' => true,
        ]);
    }

    public function test_non_crm_role_cannot_access_crm(): void
    {
        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');

        $this->actingAs($teacher)->get(route('crm.pipeline'))->assertForbidden();
    }

    public function test_sales_cannot_mutate_or_convert_another_sales_lead(): void
    {
        $lead = $this->leadFor($this->salesB);

        $this->actingAs($this->salesA)->put(route('crm.customers.update', $lead), [
            'name' => 'Bị sửa', 'phone' => $lead->phone,
        ])->assertNotFound();
        $this->actingAs($this->salesA)->post(route('crm.customers.notes.store', $lead), [
            'type' => 'note', 'content' => 'xâm nhập',
        ])->assertNotFound();
        $this->actingAs($this->salesA)->post(route('crm.closing-wizard.store'), $this->closingPayload($lead))
            ->assertNotFound();

        $this->assertDatabaseMissing('crm_customer_histories', ['customer_id' => $lead->id, 'content' => 'xâm nhập']);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_won_requires_closing_wizard_and_lost_requires_reason(): void
    {
        $lead = $this->leadFor($this->salesA);

        $this->actingAs($this->salesA)->postJson(route('crm.customers.stage', $lead), ['stage' => 'won'])
            ->assertUnprocessable();
        $this->actingAs($this->salesA)->post(route('crm.customers.stage', $lead), ['stage' => 'lost'])
            ->assertSessionHasErrors('lost_reason');

        $lead->refresh();
        $this->assertSame('closing', $lead->stage);
    }

    public function test_closing_is_idempotent_uses_server_prices_and_links_student_account(): void
    {
        $lead = $this->leadFor($this->salesA, 'student.unique@example.com');
        $payload = $this->closingPayload($lead) + [
            'base_tuition' => 1,
            'discount' => 14999999,
        ];

        $this->actingAs($this->salesA)->post(route('crm.closing-wizard.store'), $payload)
            ->assertRedirect(route('crm.customers.won'));
        $this->actingAs($this->salesA)->post(route('crm.closing-wizard.store'), $payload)
            ->assertRedirect(route('crm.customers.won'));

        $lead->refresh();
        $student = Student::findOrFail($lead->converted_student_id);
        $tuition = StudentTuition::where('student_id', $student->id)->firstOrFail();

        $this->assertSame('won', $lead->stage);
        $this->assertEquals(15000000, (float) $lead->deal_value);
        $this->assertEquals(15000000, (float) $tuition->final_amount);
        $this->assertNotNull($student->user_id);
        $this->assertSame($student->email, $student->user->email);
        $this->assertFalse(Hash::check('Password123!', $student->user->password));
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('class_enrollments', 1);
        $this->assertDatabaseCount('student_tuitions', 1);
        $this->assertDatabaseCount('tuition_receipts', 1);
    }

    public function test_split_payment_must_match_and_rolls_back_everything(): void
    {
        $lead = $this->leadFor($this->salesA);
        $payload = array_merge($this->closingPayload($lead), [
            'payment_method' => 'split',
            'split_cash_amount' => 1000000,
            'split_transfer_amount' => 1000000,
        ]);

        $this->actingAs($this->salesA)->post(route('crm.closing-wizard.store'), $payload)
            ->assertSessionHasErrors('payment_method');

        $this->assertDatabaseCount('students', 0);
        $this->assertSame('closing', $lead->fresh()->stage);
    }

    public function test_duplicate_phone_is_detected_after_normalization(): void
    {
        $this->actingAs($this->salesA)->post(route('crm.customers.store'), [
            'name' => 'Lead một', 'phone' => '0988 777 666',
            'source' => 'Hotline', 'branch_id' => $this->branch->id,
        ])->assertRedirect();

        $this->actingAs($this->salesA)->post(route('crm.customers.store'), [
            'name' => 'Lead hai', 'phone' => '0988777666',
            'source' => 'Hotline', 'branch_id' => $this->branch->id,
        ])->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('crm_customers', 1);
    }

    public function test_trial_feedback_and_no_test_waiting_list_paths_are_recorded(): void
    {
        $teacher = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $teacher->assignRole('teacher');
        $academic = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $academic->assignRole('academic_staff');
        $trialLead = $this->leadFor($this->salesA, null, 'tested');
        $trialLead->update(['test_decision' => 'test', 'test_score' => '60 (B1)']);

        $this->actingAs($this->salesA)->post(route('crm.customers.schedule-trial', $trialLead), [
            'trial_date' => now()->addDay()->format('Y-m-d'),
            'trial_time' => '18:30',
            'trial_teacher_id' => $teacher->id,
            'trial_mode' => 'offline',
        ])->assertRedirect();
        $this->assertSame('trial_scheduled', $trialLead->fresh()->stage);

        $this->actingAs($academic)->post(route('crm.customers.trial-feedback', $trialLead), [
            'trial_rating' => 5,
            'trial_feedback' => 'Phù hợp lớp mục tiêu, tương tác tốt.',
        ])->assertRedirect();
        $this->assertDatabaseHas('crm_customers', [
            'id' => $trialLead->id, 'stage' => 'trial_completed', 'trial_rating' => 5, 'test_decision' => 'test',
        ]);

        $waitingLead = $this->leadFor($this->salesA, null, 'consulting');
        $this->actingAs($this->salesA)->post(route('crm.customers.waiting-list', $waitingLead), [
            'preferred_schedule' => 'T2-T4-T6 19:30',
            'waiting_course_id' => $this->classModel->course_id,
            'waiting_branch_id' => $this->branch->id,
            'waiting_priority' => 4,
        ])->assertRedirect();
        $this->assertDatabaseHas('crm_customers', [
            'id' => $waitingLead->id, 'stage' => 'waiting_class', 'test_decision' => 'no_test', 'preferred_schedule' => 'T2-T4-T6 19:30',
        ]);
    }

    public function test_paid_cash_closing_needs_no_bank_and_paid_bill_has_no_payment_qr(): void
    {
        $lead = $this->leadFor($this->salesA);
        $payload = $this->closingPayload($lead);
        $payload['payment_method'] = 'cash';
        unset($payload['bank_account_id']);

        $this->actingAs($this->salesA)->post(route('crm.closing-wizard.store'), $payload)
            ->assertRedirect(route('crm.customers.won'));

        $tuition = StudentTuition::where('student_id', $lead->fresh()->converted_student_id)->firstOrFail();
        $this->assertSame('unpaid', $tuition->status);
        $this->assertNull($tuition->bank_account_id);
        $this->assertDatabaseHas('tuition_receipts', [
            'student_tuition_id' => $tuition->id,
            'status' => 'pending',
            'approver_id' => null,
            'invoice_number' => null,
        ]);

        $this->actingAs($this->salesA)->get(route('crm.tuition-bill', $tuition))
            ->assertOk()
            ->assertSee('Bill này không phát sinh mã thanh toán mới.')
            ->assertDontSee('img.vietqr.io', false);
    }

    public function test_partial_won_deal_displays_real_debt_instead_of_paid_one_hundred_percent(): void
    {
        $lead = $this->leadFor($this->salesA);
        $payload = $this->closingPayload($lead);
        $payload['paid_amount'] = 5000000;

        $this->actingAs($this->salesA)->post(route('crm.closing-wizard.store'), $payload);

        $this->actingAs($this->salesA)->get(route('crm.customers.won'))
            ->assertOk()
            ->assertSee('Chờ đối soát 5,000,000đ')
            ->assertSee('Còn 10,000,000đ')
            ->assertDontSee('Đã đóng 100%');
    }

    public function test_branching_state_machine_rejects_skips_and_closing_preselects_requested_lead(): void
    {
        $lead = $this->leadFor($this->salesA, null, 'consulting');
        $other = $this->leadFor($this->salesA, null, 'closing');

        $this->actingAs($this->salesA)->postJson(route('crm.customers.stage', $lead), ['stage' => 'closing'])
            ->assertUnprocessable();
        $this->assertSame('consulting', $lead->fresh()->stage);

        $response = $this->actingAs($this->salesA)->get(route('crm.closing-wizard', ['customer_id' => $other->id]));
        $response->assertOk();
        $this->assertSame($other->id, $response->viewData('customers')->first()->id);
    }

    public function test_retests_keep_attempt_history_and_consulting_lead_becomes_tested(): void
    {
        $academic = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $academic->assignRole('academic_staff');
        $lead = $this->leadFor($this->salesA, null, 'consulting');
        $test = PlacementTest::create([
            'code' => 'RETEST-01', 'title' => 'Đề kiểm tra lại', 'is_active' => true,
        ]);
        $payload = [
            'placement_test_id' => $test->id,
            'listening_score' => 60, 'reading_score' => 60,
            'speaking_score' => 60, 'writing_score' => 60,
            'cefr_level' => 'B1',
        ];

        $this->actingAs($academic)->post(route('crm.customers.save-test-score', $lead), $payload)->assertRedirect();
        $this->assertSame('tested', $lead->fresh()->stage);

        $payload['listening_score'] = 80;
        $this->actingAs($academic)->post(route('crm.customers.save-test-score', $lead), $payload)->assertRedirect();

        $this->assertSame(2, PlacementTestSubmission::where('customer_id', $lead->id)->count());
        $this->assertEquals([60.0, 65.0], PlacementTestSubmission::where('customer_id', $lead->id)->oldest()->pluck('overall_score')->map(fn ($score) => (float) $score)->all());
    }

    public function test_closing_validates_and_decrements_merchandise_stock(): void
    {
        $lead = $this->leadFor($this->salesA);
        $item = MerchandiseItem::create([
            'code' => 'BOOK-CRM', 'name' => 'Giáo trình CRM', 'category' => 'book',
            'unit' => 'Cuốn', 'price' => 200000, 'stock_quantity' => 1, 'is_active' => true,
        ]);
        $payload = $this->closingPayload($lead);
        $payload['paid_amount'] = 15200000;
        $payload['fee_items'] = json_encode([['id' => $item->id, 'amount' => 1]]);

        $this->actingAs($this->salesA)->post(route('crm.closing-wizard.store'), $payload)
            ->assertRedirect(route('crm.customers.won'));

        $this->assertSame(0, $item->fresh()->stock_quantity);
        $this->assertEquals(15200000, (float) $lead->fresh()->deal_value);
    }

    public function test_reports_use_conversion_and_receipt_dates_not_lead_creation_date(): void
    {
        $lead = $this->leadFor($this->salesA);
        $lead->forceFill(['created_at' => now()->subMonths(2)])->saveQuietly();

        $this->actingAs($this->salesA)->post(route('crm.closing-wizard.store'), $this->closingPayload($lead));

        $accountant = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $accountant->assignRole('accountant');
        $receipt = TuitionReceipt::where('student_id', $lead->fresh()->converted_student_id)->firstOrFail();
        $this->actingAs($accountant)->post(route('tuition.receipts.approve.action', $receipt))->assertRedirect();

        $response = $this->actingAs($this->salesA)->get(route('crm.reports', ['preset' => 'today']));
        $response->assertOk();
        $this->assertSame(1, $response->viewData('metricWonDeals'));
        $salesRow = collect($response->viewData('repsData'))->firstWhere('name', $this->salesA->name);
        $this->assertEquals(15000000, (float) $salesRow['revenue']);
    }

    public function test_editing_a_score_updates_the_selected_attempt_instead_of_creating_a_retest(): void
    {
        $academic = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $academic->assignRole('academic_staff');
        $lead = $this->leadFor($this->salesA, null, 'tested');
        $test = PlacementTest::create(['code' => 'EDIT-01', 'title' => 'Đề sửa điểm', 'is_active' => true]);
        $submission = PlacementTestSubmission::create([
            'placement_test_id' => $test->id,
            'customer_id' => $lead->id,
            'candidate_name' => $lead->name,
            'candidate_phone' => $lead->phone,
            'overall_score' => 50,
            'status' => 'graded',
        ]);

        $this->actingAs($academic)->post(route('crm.customers.save-test-score', $lead), [
            'submission_id' => $submission->id,
            'placement_test_id' => $test->id,
            'listening_score' => 80,
            'reading_score' => 80,
            'speaking_score' => 80,
            'writing_score' => 80,
            'cefr_level' => 'B2',
        ])->assertRedirect();

        $this->assertSame(1, PlacementTestSubmission::where('customer_id', $lead->id)->count());
        $this->assertEquals(80.0, (float) $submission->fresh()->overall_score);
    }

    public function test_sales_receipt_requires_accounting_approval_before_invoice_and_collection(): void
    {
        $lead = $this->leadFor($this->salesA);
        $this->actingAs($this->salesA)->post(route('crm.closing-wizard.store'), $this->closingPayload($lead));

        $tuition = StudentTuition::where('student_id', $lead->fresh()->converted_student_id)->firstOrFail();
        $receipt = TuitionReceipt::where('student_tuition_id', $tuition->id)->firstOrFail();
        $this->assertSame('pending', $receipt->status);
        $this->assertNull($receipt->invoice_number);
        $this->assertEquals(0, (float) $tuition->paid_amount);

        $accountant = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $accountant->assignRole('accountant');
        $this->actingAs($accountant)->post(route('tuition.receipts.approve.action', $receipt))->assertRedirect();

        $this->assertSame('approved', $receipt->fresh()->status);
        $this->assertNotNull($receipt->fresh()->invoice_number);
        $this->assertEquals(15000000, (float) $tuition->fresh()->paid_amount);
        $this->assertSame('paid', $tuition->fresh()->status);
    }

    public function test_new_student_must_change_temporary_password_before_using_system(): void
    {
        $lead = $this->leadFor($this->salesA, 'first.login@example.com');
        $response = $this->actingAs($this->salesA)->post(route('crm.closing-wizard.store'), $this->closingPayload($lead));
        $temporaryPassword = $response->getSession()->get('temporary_password');
        $studentUser = Student::findOrFail($lead->fresh()->converted_student_id)->user;

        $this->assertTrue($studentUser->must_change_password);
        $this->actingAs($studentUser)->get(route('dashboard'))
            ->assertRedirect(route('profile.edit', ['force_password' => 1]));

        $this->actingAs($studentUser)->put(route('password.update'), [
            'current_password' => $temporaryPassword,
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($studentUser->fresh()->must_change_password);
    }

    private function leadFor(User $sales, ?string $email = null, string $stage = 'closing'): CrmCustomer
    {
        return CrmCustomer::create([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Lead bảo mật',
            'phone' => '09'.random_int(10000000, 99999999),
            'email' => $email,
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $sales->id,
            'stage' => $stage,
        ]);
    }

    private function closingPayload(CrmCustomer $lead): array
    {
        return [
            'customer_id' => $lead->id,
            'class_id' => $this->classModel->id,
            'paid_amount' => 15000000,
            'payment_method' => 'transfer',
            'bank_account_id' => $this->bank->id,
        ];
    }

    public function test_edit_lead_form_includes_source_and_update_succeeds(): void
    {
        $lead = $this->leadFor($this->salesA, stage: 'consulting');
        $lead->update(['source' => 'Nguồn cũ']);

        $admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('crm.customers.edit', $lead->id))
            ->assertOk()
            ->assertSee('name="source"', false)
            ->assertSee('Nguồn cũ');

        $this->actingAs($admin)->put(route('crm.customers.update', $lead->id), [
            'name' => $lead->name,
            'phone' => $lead->phone,
            'branch_id' => $this->branch->id,
            'source' => 'Facebook Ads',
            'course_interest' => 'IELTS 6.5 Intensive',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Facebook Ads', $lead->fresh()->source);
    }

    public function test_scheduling_placement_test_conflicts_with_teaching_session(): void
    {
        $teacher = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $teacher->assignRole('teacher');

        ClassSession::create([
            'class_id' => $this->classModel->id, 'branch_id' => $this->branch->id,
            'date' => now()->addDay()->toDateString(), 'shift_name' => 'Ca 1',
            'start_time' => '09:00', 'end_time' => '10:30', 'teacher_id' => $teacher->id,
            'status' => 'scheduled',
        ]);

        $lead = $this->leadFor($this->salesA, stage: 'consulting');

        $this->actingAs($this->salesA)->post(route('crm.customers.schedule-test', $lead->id), [
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '10:00',
            'appointment_type' => 'offline',
            'examiner_id' => $teacher->id,
        ])->assertSessionHasErrors('appointment_time');

        $this->assertSame('consulting', $lead->fresh()->stage);
    }

    public function test_manual_score_entry_stores_only_provided_data(): void
    {
        $academic = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $academic->assignRole('academic_staff');
        $lead = $this->leadFor($this->salesA, stage: 'consulting');
        $test = PlacementTest::create(['code' => 'SCORE-01', 'title' => 'Đề nhập điểm', 'is_active' => true]);

        $this->actingAs($academic)->post(route('crm.customers.save-test-score', $lead->id), [
            'placement_test_id' => $test->id,
            'listening_score' => 6,
            'reading_score' => 7,
            'speaking_score' => 6,
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Writing không nhập -> null (không lấy điểm Reading thay thế); Overall = TB các kỹ năng có điểm
        $lead->refresh();
        $this->assertSame('6.3', $lead->test_score);

        $submission = PlacementTestSubmission::where('customer_id', $lead->id)->firstOrFail();
        $this->assertNull($submission->writing_score);
        $this->assertNull($submission->cefr_level);
        $this->assertNull($submission->teacher_comments);
        $this->assertNull($submission->recommended_course);
    }
}
