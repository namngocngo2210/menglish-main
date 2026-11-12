<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TK-2026-0103 / R-10: gửi duyệt phiếu thu bắt buộc chọn rõ Có/Không xuất hóa đơn VAT. */
class ReceiptVatFlagRequiredTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private StudentTuition $tuition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $branch = Branch::create(['name' => 'CN A', 'code' => 'CNA', 'is_active' => true]);
        $this->staff = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->staff->assignRole('academic_staff');
        $student = Student::create(['code' => 'HV-VAT-1', 'name' => 'HV VAT', 'phone' => '0900000003', 'branch_id' => $branch->id, 'status' => 'studying']);
        $this->tuition = StudentTuition::create([
            'student_id' => $student->id, 'branch_id' => $branch->id, 'total_amount' => 5000000, 'final_amount' => 5000000,
            'paid_amount' => 0, 'debt_amount' => 5000000, 'due_date' => now()->addDays(10), 'status' => 'unpaid',
        ]);
    }

    private function submitReceipt(array $extra = [])
    {
        return $this->actingAs($this->staff)->post(route('tuition.receipts.store'), array_merge([
            'student_tuition_id' => $this->tuition->id, 'amount' => 1000000, 'tuition_amount' => 1000000,
            'payment_method' => 'cash', 'paper_invoice_number' => 'HDG-VAT-1', 'submit_action' => 'submit',
        ], $extra));
    }

    public function test_submit_without_vat_choice_is_rejected_even_with_paper_invoice_number(): void
    {
        $this->submitReceipt()->assertSessionHasErrors('is_vat_invoice');
        $this->assertSame(0, TuitionReceipt::count());
    }

    public function test_submit_with_yes_or_no_is_accepted_and_stored(): void
    {
        $this->submitReceipt(['is_vat_invoice' => '1'])->assertSessionHasNoErrors();
        $this->submitReceipt(['is_vat_invoice' => '0', 'paper_invoice_number' => 'HDG-VAT-2', 'amount' => 1100000, 'tuition_amount' => 1100000])->assertSessionHasNoErrors();
        $this->assertSame([false, true], TuitionReceipt::orderBy('id')->get()->pluck('is_vat_invoice')->sort()->values()->all());
    }

    public function test_draft_without_vat_choice_is_still_saved(): void
    {
        $this->submitReceipt(['submit_action' => 'draft'])->assertSessionHasNoErrors();
        $this->assertSame('draft', TuitionReceipt::firstOrFail()->status);
    }
}
