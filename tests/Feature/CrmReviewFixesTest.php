<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sửa theo rà soát CRM 16/10/2026: hạn chăm sóc sau khi gọi, hẹn liên hệ tiếp, khách quay lại, khách vắng test,
 * bước cần dữ liệu thật, huỷ chốt, đồng bộ học viên, khoá đổi chi nhánh, phân công hàng loạt, xuất Excel, Kanban gọn.
 */
class CrmReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    private User $manager;

    private User $academic;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Cơ sở B', 'code' => 'CSB', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin', $this->branch);
        $this->manager = $this->userWithRole('manager', $this->branch);
        $this->academic = $this->userWithRole('academic_staff', $this->branch);
        $this->sales = $this->userWithRole('sales_consultant', $this->branch);
    }

    public function test_reached_call_clears_overdue_follow_up_and_scheduled_follow_up_becomes_deadline(): void
    {
        $lead = $this->lead('consulting', ['next_follow_up_at' => now()->subDay()]);
        CrmCustomer::whereKey($lead->id)->update(['created_at' => now()->subDays(5)]);
        $this->assertSame('overdue', $lead->fresh()->contactSla()['state']);

        $this->actingAs($this->sales)->post(route('crm.customers.notes.store', $lead), ['type' => 'call', 'outcome' => 'reached', 'content' => 'Đã gọi, PH hẹn tuần sau'])
            ->assertSessionHasNoErrors();
        $fresh = $lead->fresh();
        $this->assertNull($fresh->next_follow_up_at, 'Gọi được thì hẹn cũ đã qua được xoá.');
        $this->assertNotSame('overdue', $fresh->contactSla()['state']);

        // Hẹn liên hệ tiếp ngay trong form ghi chú: hẹn đó thành hạn chăm sóc (dài hơn 72h vẫn được, tối đa 14 ngày).
        $appointment = now()->addDays(5)->setTime(10, 0);
        $this->actingAs($this->sales)->post(route('crm.customers.notes.store', $lead), [
            'type' => 'call', 'outcome' => 'reached', 'content' => 'PH bận, hẹn gọi lại', 'next_follow_up_at' => $appointment->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();
        $sla = $lead->fresh()->contactSla();
        $this->assertSame('appointment', $sla['kind']);
        $this->assertTrue($sla['deadline']->equalTo($appointment));

        $this->actingAs($this->sales)->post(route('crm.customers.notes.store', $lead), [
            'type' => 'note', 'content' => 'Hẹn lùi', 'next_follow_up_at' => now()->subHour()->format('Y-m-d H:i'),
        ])->assertSessionHasErrors('next_follow_up_at');
    }

    public function test_duplicate_phone_of_lost_lead_points_to_reopen(): void
    {
        $lost = $this->lead('lost', ['phone' => '0904777888', 'lost_reason' => 'Chưa có nhu cầu', 'lost_at' => now()->subMonth()]);

        $this->actingAs($this->sales)->post(route('crm.customers.store'), [
            'name' => 'Khách quay lại', 'phone' => '0904 777 888', 'source' => 'Facebook', 'branch_id' => $this->branch->id,
        ])->assertSessionHasErrors('phone');
        $this->assertStringContainsString('Mở lại khách', session('errors')->first('phone'));

        $this->actingAs($this->academic)->post(route('crm.customers.stage', $lost), ['stage' => 'consulting', 'reason' => 'Phụ huynh gọi lại'])
            ->assertSessionHasNoErrors();
        $this->assertSame('consulting', $lost->fresh()->stage);
    }

    public function test_test_no_show_is_logged_and_appointment_can_be_rebooked(): void
    {
        $lead = $this->lead('test_scheduled', ['appointment_at' => now()->addDay(), 'appointment_type' => 'offline']);
        $this->actingAs($this->academic)->post(route('crm.customers.test-no-show', $lead))->assertSessionHasErrors('appointment_at');

        $lead->update(['appointment_at' => now()->subHours(2)]);
        $this->actingAs($this->academic)->post(route('crm.customers.test-no-show', $lead))->assertSessionHasNoErrors();

        $this->assertNull($lead->fresh()->appointment_at);
        $this->assertSame('test_scheduled', $lead->fresh()->stage);
        $this->assertTrue(CrmCustomerHistory::where('customer_id', $lead->id)->where('type', 'test')->where('content', 'like', 'Khách không đến làm test%')->exists());
    }

    public function test_steps_that_need_data_cannot_be_moved_by_hand(): void
    {
        $testing = $this->lead('testing');
        $this->actingAs($this->academic)->postJson(route('crm.customers.next-stage', $testing))
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Chưa có điểm test. Nhập điểm test đầu vào, khách tự chuyển sang Đã test.']);
        $this->assertSame('testing', $testing->fresh()->stage);

        $testing->update(['test_score' => '30/45']);
        $this->actingAs($this->academic)->postJson(route('crm.customers.next-stage', $testing))->assertOk();
        $this->assertSame('tested', $testing->fresh()->stage);

        // Kanban ẩn nút "Sang bước" và hiện lời nhắc cho khách thiếu dữ liệu.
        $consulting = $this->lead('consulting');
        $leads = collect($this->actingAs($this->academic)->get(route('crm.pipeline'))->assertOk()->inertiaProps('stages'))
            ->firstWhere('id', 'consulting')['leads'];
        $this->assertStringContainsString('Chưa có lịch hẹn test', collect($leads)->firstWhere('id', $consulting->id)['next_blocked']);
    }

    public function test_admin_cancels_closing_of_waiting_lead_and_refund_is_prepared(): void
    {
        $student = Student::create(['code' => 'HV-HC1', 'name' => 'HV Huỷ', 'phone' => '0900000001', 'branch_id' => $this->branch->id, 'status' => 'waiting_start']);
        $tuition = StudentTuition::create([
            'student_id' => $student->id, 'branch_id' => $this->branch->id, 'total_amount' => 5000000, 'final_amount' => 5000000,
            'paid_amount' => 0, 'debt_amount' => 5000000, 'due_date' => now()->addDays(5), 'status' => 'unpaid',
        ]);
        foreach ([['PT-HC-1', 2000000, 'approved'], ['PT-HC-2', 1000000, 'pending']] as [$number, $amount, $status]) {
            TuitionReceipt::create([
                'receipt_number' => $number, 'student_tuition_id' => $tuition->id, 'student_id' => $student->id, 'amount' => $amount,
                'payment_method' => 'transfer', 'transaction_code' => $number, 'creator_id' => $this->manager->id, 'status' => $status,
            ]);
        }
        $tuition->recalculateDebt();
        $lead = $this->lead('waiting_class', ['converted_student_id' => $student->id, 'waiting_since' => today()->subDays(20)]);

        $this->actingAs($this->academic)->post(route('crm.customers.cancel-closing', $lead), ['reason' => 'Gia đình chuyển nhà'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('crm.customers.cancel-closing', $lead), ['reason' => 'Gia đình chuyển nhà'])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame('lost', $lead->stage);
        $this->assertStringStartsWith('Huỷ chốt:', $lead->lost_reason);
        $this->assertSame(Student::STATUS_DROPPED, $student->fresh()->status);
        $this->assertSame(TuitionReceipt::STATUS_CANCELLED, TuitionReceipt::where('receipt_number', 'PT-HC-2')->value('status'));
        $this->assertEquals(0, (float) $tuition->fresh()->debt_amount);
        $refund = TuitionRefundRequest::where('student_id', $student->id)->firstOrFail();
        $this->assertSame('pending', $refund->status);
        $this->assertEquals(2000000, (float) $refund->refund_amount);

        // Khách đã có lớp (Đã chốt) không huỷ chốt được.
        $won = $this->lead('won');
        $this->actingAs($this->admin)->post(route('crm.customers.cancel-closing', $won), ['reason' => 'Thử huỷ'])->assertForbidden();
    }

    public function test_editing_closed_lead_syncs_student_and_branch_only_changes_by_reassign(): void
    {
        $student = Student::create(['code' => 'HV-SY1', 'name' => 'Nguyen Van An', 'phone' => '0911111111', 'branch_id' => $this->branch->id, 'status' => 'studying']);
        $won = $this->lead('won', ['name' => 'Nguyen Van An', 'phone' => '0911111111', 'converted_student_id' => $student->id, 'source' => 'Facebook']);

        $this->actingAs($this->manager)->put(route('crm.customers.update', $won), [
            'name' => 'Nguyễn Văn An', 'phone' => '0911111112', 'parent_name' => 'Trần Thị Bình', 'parent_phone' => '0922222222',
            'dob' => '2015-05-20', 'source' => 'Facebook',
        ])->assertSessionHasNoErrors();
        $student->refresh();
        $this->assertSame('Nguyễn Văn An', $student->name);
        $this->assertSame('0911111112', $student->phone);
        $this->assertSame('0922222222', $student->parent_phone);
        $this->assertSame('2015-05-20', $student->dob->toDateString());

        $lead = $this->lead('consulting', ['source' => 'Facebook']);
        $this->actingAs($this->manager)->put(route('crm.customers.update', $lead), [
            'name' => $lead->name, 'phone' => $lead->phone, 'source' => 'Facebook', 'branch_id' => $this->otherBranch->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->branch->id, $lead->fresh()->branch_id, 'Đổi chi nhánh chỉ qua Phân công lại.');
    }

    public function test_bulk_reassign_and_export_follow_current_filters(): void
    {
        $newOwner = $this->userWithRole('academic_staff', $this->branch);
        $leads = collect([$this->lead('new'), $this->lead('consulting'), $this->lead('consulting', [], $this->otherBranch)]);

        $this->actingAs($this->sales)->post(route('crm.customers.bulk-reassign'), ['ids' => $leads->pluck('id')->all(), 'assigned_user_id' => $newOwner->id, 'reason' => 'Nghỉ việc'])
            ->assertForbidden();
        $this->actingAs($this->manager)->post(route('crm.customers.bulk-reassign'), ['ids' => $leads->pluck('id')->all(), 'assigned_user_id' => $newOwner->id])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post(route('crm.customers.bulk-reassign'), ['ids' => $leads->pluck('id')->all(), 'assigned_user_id' => $newOwner->id, 'reason' => 'Học vụ cũ nghỉ việc'])
            ->assertSessionHasNoErrors();

        $this->assertSame($newOwner->id, $leads[0]->fresh()->assigned_user_id);
        $this->assertSame($newOwner->id, $leads[1]->fresh()->assigned_user_id);
        // Khách chi nhánh khác nằm ngoài phạm vi Quản lý cơ sở A: không bị đổi.
        $this->assertSame($this->sales->id, $leads[2]->fresh()->assigned_user_id);
        $this->assertSame(2, CrmCustomerHistory::where('type', 'assign')->where('reason', 'Học vụ cũ nghỉ việc')->count());

        $export = $this->actingAs($this->manager)->get(route('crm.customers.index', ['stage' => 'consulting', 'export' => 'csv']))->assertOk();
        $this->assertStringContainsString('danh-sach-khach', (string) $export->headers->get('content-disposition'));
    }

    public function test_kanban_column_shows_first_cards_and_links_to_the_rest(): void
    {
        foreach (range(1, 32) as $i) {
            $this->lead('consulting');
        }

        $column = collect($this->actingAs($this->manager)->get(route('crm.pipeline'))->assertOk()->inertiaProps('stages'))->firstWhere('id', 'consulting');
        $this->assertSame(32, $column['count']);
        $this->assertCount(30, $column['leads']);
        $this->assertSame(2, $column['hidden']);
        $this->assertStringContainsString('stage=consulting', $column['more_url']);
    }

    private function lead(string $stage, array $attributes = [], ?Branch $branch = null): CrmCustomer
    {
        return CrmCustomer::create($attributes + [
            'code' => CrmCustomer::generateCode(),
            'name' => 'Lead '.$stage.' '.random_int(100, 999),
            'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => ($branch ?? $this->branch)->id,
            'assigned_user_id' => $this->sales->id,
            'stage' => $stage,
        ]);
    }

    private function userWithRole(string $role, Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
