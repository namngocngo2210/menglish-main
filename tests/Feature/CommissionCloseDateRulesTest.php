<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CommissionAdjustment;
use App\Models\CommissionItem;
use App\Models\CommissionTier;
use App\Models\CrmCustomer;
use App\Models\PayrollPeriod;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Services\SalesCommissionService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Quyết định Q7: chốt theo tiền về, giữ % lúc chốt và không thu hồi hoa hồng. */
class CommissionCloseDateRulesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $sales;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-01 08:00:00'));
        $this->branch = Branch::create(['code' => 'Q7', 'name' => 'Cơ sở Q7', 'is_active' => true]);
        $this->sales = User::factory()->create(['branch_id' => $this->branch->id, 'base_salary' => 0, 'is_active' => true]);
        $this->sales->assignRole('sales_consultant');
        $this->admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function customer(string $code, string $convertedAt = '2026-10-01 09:00:00'): array
    {
        $student = Student::create([
            'code' => 'HV-'.$code, 'name' => 'Học viên '.$code, 'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => $this->branch->id, 'status' => 'studying',
        ]);
        $customer = CrmCustomer::create([
            'code' => 'KH-'.$code, 'name' => $student->name, 'phone' => $student->phone, 'stage' => 'won',
            'branch_id' => $this->branch->id, 'assigned_user_id' => $this->sales->id, 'commission_user_id' => $this->sales->id,
            'converted_student_id' => $student->id, 'converted_at' => $convertedAt,
            'care_checklist' => ['session_1' => true, 'session_4_5' => true, 'day_30' => true],
        ]);
        $tuition = StudentTuition::create([
            'student_id' => $student->id, 'branch_id' => $this->branch->id,
            'total_amount' => 12000000, 'final_amount' => 12000000, 'paid_amount' => 0, 'debt_amount' => 12000000, 'status' => 'unpaid',
        ]);

        return [$customer, $tuition];
    }

    private function receipt(StudentTuition $tuition, string $approvedAt, array $attributes = []): TuitionReceipt
    {
        $this->travelTo(Carbon::parse($approvedAt));

        return TuitionReceipt::create($attributes + [
            'receipt_number' => TuitionReceipt::generateReceiptNumber(), 'student_id' => $tuition->student_id,
            'student_tuition_id' => $tuition->id, 'amount' => 1000000, 'payment_method' => 'cash',
            'payment_date' => $approvedAt, 'status' => TuitionReceipt::STATUS_APPROVED, 'approved_at' => $approvedAt,
        ]);
    }

    private function period(int $month): PayrollPeriod
    {
        $start = Carbon::create(2026, $month, 1);

        return PayrollPeriod::create([
            'code' => 'PR-Q7-'.$month, 'title' => 'Lương tháng '.$month, 'month' => $month, 'year' => 2026,
            'start_date' => $start, 'end_date' => $start->copy()->endOfMonth(), 'status' => 'draft',
        ]);
    }

    public function test_conversion_in_october_and_payment_in_november_closes_in_november_and_starts_the_gate(): void
    {
        [$customer, $tuition] = $this->customer('M1');
        $service = app(SalesCommissionService::class);
        $this->assertNull($service->closingRank($customer));
        $this->receipt($tuition, '2026-11-10 10:00:00');

        $this->assertSame(0, $service->closedCountFor($this->sales->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')));
        $this->assertSame(1, $service->closedCountFor($this->sales->id, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')));
        $service->syncItemsForPeriod($this->period(11));
        $this->assertSame(1, $service->closingRank($customer));
        $item = CommissionItem::sole();
        $this->assertSame('2026-11-10 10:00:00', $item->closed_at->format('Y-m-d H:i:s'));
        $this->assertFalse($service->gateFor($item, Carbon::parse('2026-11-01'))['passes']);
        $this->assertFalse($service->gateFor($item, Carbon::parse('2026-12-09'))['passes']);
        $this->assertTrue($service->gateFor($item, Carbon::parse('2026-12-10'))['passes']);
        $this->assertSame(0, $service->statementFor($this->sales->id, Carbon::parse('2026-10-01'))['closed']);
        $statement = $service->statementFor($this->sales->id, Carbon::parse('2026-11-01'));
        $this->assertSame(1, $statement['closed']);
        $this->assertSame('10/11/2026', $statement['rows'][0]['closed_at']);
    }

    public function test_payment_order_sets_monthly_rank_and_progressive_percent(): void
    {
        [$a, $aTuition] = $this->customer('A', '2026-10-01 09:00:00');
        [$b, $bTuition] = $this->customer('B', '2026-10-02 09:00:00');
        $this->receipt($bTuition, '2026-10-03 09:00:00');
        $this->receipt($aTuition, '2026-10-04 09:00:00');
        foreach (range(3, 6) as $i) {
            [, $tuition] = $this->customer('R'.$i);
            $this->receipt($tuition, sprintf('2026-10-%02d 09:00:00', $i + 2));
        }
        $service = app(SalesCommissionService::class);
        $receipts = $service->commissionableReceipts(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))->sortBy('closing_rank')->values();
        $this->assertSame(1, $service->closingRank($b));
        $this->assertSame(2, $service->closingRank($a));
        $this->assertEquals([3, 3, 3, 3, 3, 4], $receipts->pluck('commission_percent')->all());
        [$next, $nextTuition] = $this->customer('NEXT');
        $this->receipt($nextTuition, '2026-11-01 09:00:00');
        $service->commissionableReceipts(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertSame(1, $service->closingRank($next));
    }

    public function test_installment_and_deferred_item_keep_the_tier_table_at_close_date(): void
    {
        CommissionTier::query()->delete();
        foreach ([[3, null, '2026-10-31'], [8, '2026-11-01', null]] as [$percent, $from, $to]) {
            CommissionTier::create([
                'tier_name' => 'Mốc '.$percent.'%', 'min_revenue' => 0, 'min_students' => 1, 'new_sale_percent' => $percent,
                'bonus_amount' => 0, 'effective_from' => $from, 'effective_to' => $to,
            ]);
        }
        [, $tuition] = $this->customer('RATE');
        $first = $this->receipt($tuition, '2026-10-20 09:00:00');
        $second = $this->receipt($tuition, '2026-11-20 09:00:00');
        $october = $this->period(10);
        $october->calculatePayrollForPeriod();
        $this->assertEquals(30000, $october->records()->where('user_id', $this->sales->id)->sole()->commission_deferred);
        $november = $this->period(11);
        $november->calculatePayrollForPeriod();
        $items = CommissionItem::whereIn('tuition_receipt_id', [$first->id, $second->id])->get();
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertEquals(3, $item->percent);
            $this->assertSame(CommissionItem::STATUS_PAYABLE, $item->status);
        }
        $this->assertEquals(60000, $november->records()->where('user_id', $this->sales->id)->sole()->commission_bonus);
    }

    public function test_refund_ignores_clawback_input_preserves_paid_item_and_voids_unpaid_item(): void
    {
        [$customer, $tuition] = $this->customer('REFUND');
        $paidReceipt = $this->receipt($tuition, '2026-10-01 09:00:00');
        $service = app(SalesCommissionService::class);
        $service->syncItemsForPeriod($this->period(10));
        $paid = CommissionItem::where('tuition_receipt_id', $paidReceipt->id)->sole();
        $paid->update(['status' => CommissionItem::STATUS_PAID, 'settled_at' => '2026-11-03 09:00:00']);
        $deferredReceipt = $this->receipt($tuition, '2026-11-10 09:00:00');
        $service->syncItemsForPeriod($this->period(11));
        $deferred = CommissionItem::where('tuition_receipt_id', $deferredReceipt->id)->sole();
        $refund = TuitionRefundRequest::create([
            'student_id' => $customer->converted_student_id, 'type' => 'refund', 'total_paid' => 2000000,
            'refund_amount' => 500000, 'reason' => 'Chuyển nơi ở', 'requester_id' => $this->admin->id, 'status' => 'pending',
        ]);
        Storage::fake(TuitionRefundRequest::PROOF_DISK);
        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $refund->id), [
            'proof_image' => UploadedFile::fake()->image('bang-chung.jpg'), 'clawback_commission' => 1, 'clawback_amount' => 999999,
        ])->assertSessionHasNoErrors();
        $this->assertFalse($refund->fresh()->clawback_commission);
        $this->assertEquals(0, $refund->fresh()->clawback_amount);
        $this->assertNull($refund->fresh()->clawback_user_id);
        $this->assertSame(CommissionItem::STATUS_PAID, $paid->fresh()->status);
        $this->assertSame(CommissionItem::STATUS_VOID, $deferred->fresh()->status);
        $this->assertNull($deferred->fresh()->payroll_record_id);
        $this->assertSame('Không trả do học viên hoàn phí #'.$refund->id, $deferred->fresh()->deferred_reason);
        $this->sales->update(['base_salary' => 5000000]);
        $december = $this->period(12);
        $december->calculatePayrollForPeriod();
        $record = $december->records()->where('user_id', $this->sales->id)->sole();
        $this->assertEquals(0, $record->commission_bonus);
        $this->assertEquals(0, $record->commission_clawback);
        $this->assertDatabaseCount('commission_adjustments', 0);
    }

    public function test_close_dates_use_receipt_filters_allow_zero_base_and_query_in_batches(): void
    {
        [$customer, $tuition] = $this->customer('FILTER');
        [$unpaid] = $this->customer('UNPAID');
        $renewal = $tuition->replicate();
        $renewal->save();
        foreach ([
            ['status' => TuitionReceipt::STATUS_PENDING],
            ['status' => TuitionReceipt::STATUS_REJECTED],
            ['status' => TuitionReceipt::STATUS_CANCELLED],
            ['amount' => 0], ['amount' => -100], ['transaction_code' => 'XFER-IN-123'],
        ] as $attributes) {
            $this->receipt($tuition, '2026-10-02 09:00:00', $attributes);
        }
        $this->receipt($renewal, '2026-10-03 09:00:00');
        $noDate = $this->receipt($tuition, '2026-10-04 09:00:00');
        DB::table('tuition_receipts')->where('id', $noDate->id)->update(['approved_at' => null]);
        // Phiếu cũ thiếu student_id; chỉ thu phụ thu vẫn là mốc chốt theo quyết định Q7.
        $this->receipt($tuition, '2026-10-05 10:00:00', ['student_id' => null, 'surcharge_amount' => 1000000]);
        $this->receipt($tuition, '2026-10-06 10:00:00');
        $service = app(SalesCommissionService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $dates = $service->closeDates([$customer, $unpaid]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(2, $queries);
        $this->assertSame('2026-10-05 10:00:00', $dates[$customer->id]->format('Y-m-d H:i:s'));
        $this->assertArrayNotHasKey($unpaid->id, $dates);
        $this->assertNull($service->closingRank($unpaid));
        $start = Carbon::parse('2026-10-05');
        $end = Carbon::parse('2026-10-05');
        $this->assertSame(1, $service->closedCountsBySales($start, $end, CrmCustomer::whereKey($customer->id))->get($this->sales->id));
        $this->assertTrue($service->closedCountsBySales($start, $end, CrmCustomer::whereKey($unpaid->id))->isEmpty());
    }

    public function test_transfer_leaves_unpaid_commission_unchanged_and_history_adjustments_still_apply(): void
    {
        [$customer, $tuition] = $this->customer('TRANSFER');
        $this->receipt($tuition, '2026-10-20 09:00:00');
        $service = app(SalesCommissionService::class);
        $october = $this->period(10);
        $october->calculatePayrollForPeriod();
        $item = CommissionItem::sole();
        $refund = TuitionRefundRequest::create([
            'student_id' => $customer->converted_student_id, 'type' => 'transfer', 'total_paid' => 1000000,
            'refund_amount' => 500000, 'reason' => 'Chuyển phí', 'requester_id' => $this->admin->id, 'status' => 'approved',
        ]);
        $this->assertSame(0, $service->recordRefundApproval($refund));
        $this->assertSame(CommissionItem::STATUS_DEFERRED, $item->fresh()->status);
        $this->assertDatabaseCount('commission_adjustments', 0);
        // Dòng lịch sử / thủ công còn phải được quyết toán, dù hai luồng mới không tạo điều chỉnh.
        $adjustment = CommissionAdjustment::create([
            'user_id' => $this->sales->id, 'student_id' => $customer->converted_student_id,
            'amount' => -10000, 'reason' => 'Điều chỉnh lịch sử', 'created_by' => $this->admin->id,
        ]);
        $november = $this->period(11);
        $november->calculatePayrollForPeriod();
        $record = $november->records()->where('user_id', $this->sales->id)->sole();
        $this->assertEquals(30000, $record->commission_bonus);
        $this->assertEquals(10000, $record->commission_clawback);
        $this->assertSame($record->id, $adjustment->fresh()->payroll_record_id);
    }

    public function test_invoice_cancellation_preserves_paid_item_and_voids_deferred_item_without_adjustments(): void
    {
        [, $tuition] = $this->customer('CANCEL');
        $paidReceipt = $this->receipt($tuition, '2026-10-01 09:00:00');
        $deferredReceipt = $this->receipt($tuition, '2026-10-20 09:00:00');
        $service = app(SalesCommissionService::class);
        $service->syncItemsForPeriod($this->period(10));
        $paid = CommissionItem::where('tuition_receipt_id', $paidReceipt->id)->sole();
        $paid->update(['status' => CommissionItem::STATUS_PAID, 'settled_at' => '2026-11-03 09:00:00']);
        $service->voidForCancelledReceipt($paidReceipt, 'HD-01');
        $service->voidForCancelledReceipt($deferredReceipt, 'HD-02');
        $this->assertSame(CommissionItem::STATUS_PAID, $paid->fresh()->status);
        $deferred = CommissionItem::where('tuition_receipt_id', $deferredReceipt->id)->sole();
        $this->assertSame(CommissionItem::STATUS_VOID, $deferred->status);
        $this->assertSame('Không trả do hủy hóa đơn HD-02', $deferred->deferred_reason);
        $this->assertDatabaseCount('commission_adjustments', 0);
    }
}
