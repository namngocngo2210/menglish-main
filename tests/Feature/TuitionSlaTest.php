<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SLA học phí: nộp tiền mặt về TK công ty trước 19:00; hạn xử lý hoàn phí / chuyển nhượng 1 tuần. */
class TuitionSlaTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $accountant;

    private User $staff;

    private Student $student;

    private StudentTuition $tuition;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'CN A', 'code' => 'CNA-SLA', 'is_active' => true]);
        $this->admin = $this->makeUser('admin');
        $this->accountant = $this->makeUser('accountant');
        $this->staff = $this->makeUser('academic_staff');
        $this->student = Student::create(['code' => 'HV-SLA-1', 'name' => 'Trần Nộp Tiền', 'phone' => '0900000777', 'branch_id' => $this->branch->id, 'status' => 'studying']);
        $this->tuition = StudentTuition::create([
            'student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total_amount' => 5000000, 'final_amount' => 5000000,
            'paid_amount' => 0, 'debt_amount' => 5000000, 'due_date' => now()->addDays(10), 'status' => 'unpaid',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function receipt(array $extra = []): TuitionReceipt
    {
        return TuitionReceipt::create(array_merge([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(),
            'invoice_number' => 'C26MEN-'.random_int(100000, 999999),
            'student_tuition_id' => $this->tuition->id, 'student_id' => $this->student->id,
            'amount' => 1000000, 'payment_method' => 'cash', 'payment_date' => now()->toDateString(),
            'creator_id' => $this->staff->id, 'status' => 'approved',
        ], $extra));
    }

    public function test_accountant_confirms_deposit_before_cutoff_and_it_is_not_late(): void
    {
        $receipt = $this->receipt();
        $this->assertSame('pending', $receipt->depositState());

        Carbon::setTestNow('2026-10-14 18:30:00');
        $this->actingAs($this->accountant)->post(route('tuition.receipts.confirm-deposit', $receipt->id))->assertSessionHasNoErrors();

        $receipt->refresh();
        $this->assertSame($this->accountant->id, $receipt->deposited_by);
        $this->assertSame('on_time', $receipt->depositState());
    }

    public function test_deposit_after_19h_is_flagged_late(): void
    {
        $receipt = $this->receipt();

        Carbon::setTestNow('2026-10-14 19:20:00');
        $this->actingAs($this->admin)->post(route('tuition.receipts.confirm-deposit', $receipt->id))->assertSessionHasNoErrors();

        $this->assertSame('late', $receipt->fresh()->depositState());
    }

    public function test_confirm_deposit_needs_permission_and_only_applies_to_active_cash_receipts(): void
    {
        $receipt = $this->receipt();
        $this->actingAs($this->staff)->post(route('tuition.receipts.confirm-deposit', $receipt->id))->assertForbidden();

        $transfer = $this->receipt(['payment_method' => 'transfer', 'transaction_code' => 'FT1']);
        $this->actingAs($this->accountant)->post(route('tuition.receipts.confirm-deposit', $transfer->id))->assertSessionHas('error');
        $this->assertNull($transfer->fresh()->deposited_at);
        $this->assertNull($transfer->depositState());

        // Đã nộp rồi thì không ghi đè lần 2.
        $this->actingAs($this->accountant)->post(route('tuition.receipts.confirm-deposit', $receipt->id));
        $first = $receipt->fresh()->deposited_at;
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->actingAs($this->admin)->post(route('tuition.receipts.confirm-deposit', $receipt->id))->assertSessionHas('warning');
        $this->assertEquals($first, $receipt->fresh()->deposited_at);
    }

    public function test_history_and_approval_lists_show_deposit_badges(): void
    {
        $this->receipt();
        $late = $this->receipt(['deposited_at' => '2026-10-14 20:00:00', 'deposited_by' => $this->accountant->id]);

        $this->actingAs($this->accountant)->get(route('tuition.history'))
            ->assertOk()->assertSee('Chưa nộp về TK')->assertSee('Nộp trễ sau 19h');
        $this->actingAs($this->accountant)->get(route('tuition.receipts.approve', ['status' => 'all', 'selected_id' => $late->id]))
            ->assertOk()->assertSee('Chưa nộp về TK')->assertSee('Nộp trễ sau 19h');
    }

    public function test_check_cash_deposits_notifies_collector_and_confirmers_once(): void
    {
        $open = $this->receipt();
        $this->receipt(['deposited_at' => '2026-10-14 17:00:00', 'deposited_by' => $this->accountant->id]); // đã nộp
        $this->receipt(['payment_method' => 'transfer', 'transaction_code' => 'FT2']);
        $this->receipt(['status' => 'cancelled']);
        $this->receipt(['status' => 'rejected']);
        $this->receipt(['payment_date' => '2026-10-13']); // không phải hôm nay

        Carbon::setTestNow('2026-10-14 19:05:00');
        $this->artisan('tuition:check-cash-deposits')->assertSuccessful();
        $this->artisan('tuition:check-cash-deposits')->assertSuccessful(); // idempotent

        $notes = AdminNotification::where('type', 'cash_deposit_overdue')->get();
        $this->assertEqualsCanonicalizing(
            [$this->staff->id, $this->accountant->id, $this->admin->id],
            $notes->pluck('user_id')->all(),
        );
        $this->assertSame([$open->id], $notes->pluck('data.receipt_id')->unique()->values()->all());
        $this->assertSame('Tiền mặt chưa nộp về TK', $notes->first()->type_label);
        $this->assertNotSame('notifications', $notes->first()->icon);
    }

    public function test_notify_refund_deadlines_reaches_approvers_once_a_day_per_kind(): void
    {
        // Lập 14/10 → hạn 21/10 (cuối ngày).
        $refund = TuitionRefundRequest::create([
            'student_id' => $this->student->id, 'type' => 'refund', 'refund_amount' => 1000000, 'reason' => 'x',
            'requester_id' => $this->staff->id, 'status' => 'pending',
        ]);
        $transfer = TuitionRefundRequest::create([
            'student_id' => $this->student->id, 'type' => 'transfer', 'reason' => 'x',
            'requester_id' => $this->staff->id, 'status' => 'pending',
        ]);
        TuitionRefundRequest::create(['student_id' => $this->student->id, 'type' => 'extension', 'reason' => 'x', 'requester_id' => $this->staff->id, 'status' => 'pending']);
        TuitionRefundRequest::create(['student_id' => $this->student->id, 'type' => 'refund', 'reason' => 'x', 'requester_id' => $this->staff->id, 'status' => 'approved']);

        // Còn 7 ngày: chưa nhắc.
        $this->artisan('tuition:notify-refund-deadlines')->assertSuccessful();
        $this->assertSame(0, AdminNotification::where('type', 'refund_deadline')->count());

        // Ngày 20/10: còn 1 ngày → nhắc; chạy lại cùng ngày không trùng.
        Carbon::setTestNow('2026-10-20 08:40:00');
        $this->artisan('tuition:notify-refund-deadlines')->assertSuccessful();
        $this->artisan('tuition:notify-refund-deadlines')->assertSuccessful();
        $soon = AdminNotification::where('type', 'refund_deadline')->where('data->kind', 'due_soon')->get();
        // Hoàn phí: chỉ Admin; chuyển nhượng: Admin + Kế toán.
        $this->assertSame(1, $soon->where('data.request_id', $refund->id)->count());
        $this->assertEquals($this->admin->id, $soon->firstWhere('data.request_id', $refund->id)->user_id);
        $this->assertEqualsCanonicalizing([$this->admin->id, $this->accountant->id], $soon->where('data.request_id', $transfer->id)->pluck('user_id')->all());

        // Qua hạn (22/10): thông báo "quá hạn" mới; ngày kế tiếp lại nhắc một lần nữa.
        Carbon::setTestNow('2026-10-22 08:40:00');
        $this->artisan('tuition:notify-refund-deadlines')->assertSuccessful();
        $this->assertSame(3, AdminNotification::where('type', 'refund_deadline')->where('data->kind', 'overdue')->count());
        Carbon::setTestNow('2026-10-23 08:40:00');
        $this->artisan('tuition:notify-refund-deadlines')->assertSuccessful();
        $this->assertSame(6, AdminNotification::where('type', 'refund_deadline')->where('data->kind', 'overdue')->count());
    }

    public function test_permission_is_granted_to_accountant_not_staff(): void
    {
        $this->assertTrue($this->accountant->can('tuition.confirm_deposit'));
        $this->assertFalse($this->staff->can('tuition.confirm_deposit'));
    }
}
