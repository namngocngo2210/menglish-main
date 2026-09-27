<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InvoiceCancellation;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SupportTicket;
use App\Models\TuitionReceipt;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rà soát đợt 8: không hủy riêng phiếu hoàn/chuyển phí, đếm quá hạn theo hạn đóng thực tế, bỏ công nợ của
 * học viên đã xóa, ticket chỉ chuyển "Đang xử lý" khi người xử lý trả lời, nút chỉ hiện khi có quyền.
 */
class ReviewRoundFinalTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở F', 'code' => 'CSF', 'is_active' => true]);
        $this->accountant = $this->userWithRole('accountant');
    }

    public function test_system_refund_receipt_cannot_be_cancelled_alone(): void
    {
        $tuition = $this->tuition($this->student('HV-F1'), 5000000, now()->addDays(5));
        TuitionReceipt::create([
            'receipt_number' => 'PT-F-1', 'invoice_number' => 'C26MEN-REF1', 'student_tuition_id' => $tuition->id,
            'student_id' => $tuition->student_id, 'amount' => -1000000, 'payment_method' => 'transfer',
            'transaction_code' => 'REFUND-1', 'creator_id' => $this->accountant->id, 'status' => 'approved',
        ]);

        $this->actingAs($this->accountant)->post(route('tuition.invoices.cancellations.store'), [
            'invoice_number' => 'C26MEN-REF1', 'amount' => -1000000, 'reason' => 'Thử hủy',
        ])->assertSessionHasErrors('invoice_number');
        $this->assertSame(0, InvoiceCancellation::count());
    }

    public function test_overdue_stat_uses_due_date_and_skips_deleted_students(): void
    {
        $partial = $this->tuition($this->student('HV-F2'), 5000000, now()->subDays(3));
        $partial->update(['paid_amount' => 1000000, 'debt_amount' => 4000000, 'status' => 'partial']);
        $gone = $this->student('HV-F3');
        $this->tuition($gone, 3000000, now()->subDays(3));
        $gone->delete();

        $stats = $this->actingAs($this->accountant)->get(route('tuition.students'))->assertOk()->viewData('stats');

        $this->assertSame(1, $stats['overdue']);
        $this->assertEquals(4000000, $stats['debt']);
    }

    public function test_creator_reply_does_not_mark_ticket_in_progress_but_reopens_resolved(): void
    {
        $creator = $this->userWithRole('sales_consultant');
        $ticket = SupportTicket::create([
            'code' => 'TK-F1', 'title' => 'Lỗi', 'category' => 'technical', 'priority' => 'medium',
            'status' => 'open', 'creator_id' => $creator->id, 'description' => 'Mô tả',
        ]);

        $this->actingAs($creator)->post(route('tickets.messages.store', $ticket->id), ['message' => 'Bổ sung thông tin']);
        $this->assertSame('open', $ticket->fresh()->status);

        $ticket->update(['status' => 'resolved']);
        $this->actingAs($creator)->post(route('tickets.messages.store', $ticket->id), ['message' => 'Vẫn còn lỗi']);
        $this->assertSame('in_progress', $ticket->fresh()->status);

        $this->actingAs($creator)->get(route('tickets.show', $ticket->id))->assertOk()
            ->assertDontSee('Cập nhật Phân công')->assertDontSee('aria-label="Trạng thái ticket"', false);
    }

    public function test_future_deferral_keeps_student_in_class_until_start_date(): void
    {
        $student = $this->student('HV-F4');
        $tuition = $this->tuition($student, 5000000, now()->addDays(20));
        $from = now()->addDays(7)->toDateString();

        $this->actingAs($this->accountant)->post(route('tuition.refunds.store'), [
            'student_id' => $student->id, 'type' => 'deferral', 'defer_from' => $from, 'defer_to' => now()->addMonth()->toDateString(),
            'reason' => 'Đi du lịch',
        ])->assertSessionHasNoErrors();
        $request = \App\Models\TuitionRefundRequest::where('type', 'deferral')->firstOrFail();
        $this->actingAs($this->userWithRole('admin'))->post(route('tuition.refunds.approve', $request->id))->assertSessionHasNoErrors();

        $this->assertSame('studying', $student->fresh()->status);
        $this->assertNull($tuition->fresh()->frozen_debt_amount);

        $this->artisan('students:start-deferrals')->assertSuccessful();
        $this->assertSame('studying', $student->fresh()->status);

        $this->travelTo(now()->addDays(7)->setTime(7, 0));
        $this->artisan('students:start-deferrals')->assertSuccessful();
        $this->assertSame('deferred', $student->fresh()->status);
        $this->assertEquals(5000000, (float) $tuition->fresh()->frozen_debt_amount);
    }

    private function student(string $code): Student
    {
        return Student::create(['code' => $code, 'name' => 'HV '.$code, 'phone' => '0900000000', 'branch_id' => $this->branch->id, 'status' => 'studying']);
    }

    private function tuition(Student $student, float $amount, $due): StudentTuition
    {
        return StudentTuition::create([
            'student_id' => $student->id, 'branch_id' => $this->branch->id, 'total_amount' => $amount, 'final_amount' => $amount,
            'paid_amount' => 0, 'debt_amount' => $amount, 'due_date' => $due, 'status' => 'unpaid',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
