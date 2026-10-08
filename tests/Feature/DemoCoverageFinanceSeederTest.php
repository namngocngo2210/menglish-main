<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\CrmCustomer;
use App\Models\InvoiceCancellation;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Services\Tuition\PaymentReportService;
use App\Services\Tuition\TuitionSlaService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCoverageFinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dữ liệu demo tài chính bổ sung: báo đóng học phí, hồ sơ bị từ chối, hủy HĐ bị từ chối, nộp tiền mặt đúng hạn / trễ / chưa nộp. */
class DemoCoverageFinanceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_every_finance_case_and_is_idempotent(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoCoverageFinanceSeeder::class);

        $customers = CrmCustomer::where('phone', 'like', '038760%')->orderBy('phone')->get();
        $this->assertCount(5, $customers);
        $studentIds = $customers->pluck('converted_student_id')->filter();
        $this->assertCount(5, $studentIds);

        // Báo đóng học phí: chờ / đã xác nhận / bị từ chối, kèm thư báo kết quả ở cổng học viên.
        $reports = AcademicRecord::where('screen_key', PaymentReportService::SCREEN_KEY)
            ->whereIn('data->student_id', $studentIds->map(fn ($id) => (string) $id)->all())->get();
        foreach ([PaymentReportService::STATUS_PENDING, PaymentReportService::STATUS_CONFIRMED, PaymentReportService::STATUS_REJECTED] as $status) {
            $this->assertTrue($reports->contains('status', $status), "Thiếu báo đóng học phí {$status}.");
        }
        $this->assertNotEmpty(data_get($reports->firstWhere('status', PaymentReportService::STATUS_REJECTED)->data, 'rejection_reason'));
        $this->assertSame(2, AcademicRecord::where('record_code', 'like', 'YCHOCPHI-KQ-%')->whereIn('record_code', $reports->map(fn ($r) => 'YCHOCPHI-KQ-'.$r->id))->count());

        // Mỗi loại hồ sơ hoàn / chuyển nhượng / khất nợ / bảo lưu có 1 hồ sơ bị từ chối kèm lý do, xử lý trong hạn.
        $refunds = TuitionRefundRequest::whereIn('student_id', $studentIds)->get();
        foreach (array_keys(TuitionRefundRequest::TYPES) as $type) {
            $rejected = $refunds->where('type', $type)->firstWhere('status', 'rejected');
            $this->assertNotNull($rejected, "Thiếu hồ sơ {$type} bị từ chối.");
            $this->assertNotEmpty($rejected->rejection_reason);
            $this->assertFalse($rejected->isProcessingOverdue());
        }
        $this->assertSame(0, $refunds->where('status', '!=', 'rejected')->count());

        // Yêu cầu hủy hóa đơn bị từ chối: phiếu vẫn còn hiệu lực.
        $cancellation = InvoiceCancellation::whereIn('student_id', $studentIds)->sole();
        $this->assertSame('rejected', $cancellation->status);
        $this->assertSame(TuitionReceipt::STATUS_APPROVED, $cancellation->receipt->status);

        // Phiếu thu đã duyệt (không do người lập tự duyệt); tiền mặt: nộp đúng hạn, nộp trễ, chưa nộp (đã có nhắc).
        $receipts = TuitionReceipt::whereIn('student_id', $studentIds)->get();
        $this->assertCount(4, $receipts);
        $this->assertTrue($receipts->every(fn (TuitionReceipt $r) => $r->status === TuitionReceipt::STATUS_APPROVED && $r->invoice_number && $r->creator_id !== $r->approver_id));
        $states = $receipts->map(fn (TuitionReceipt $r) => $r->depositState())->filter()->sort()->values()->all();
        $this->assertSame(['late', 'on_time', 'pending'], $states);
        $pending = $receipts->first(fn (TuitionReceipt $r) => $r->depositState() === 'pending');
        $this->assertTrue($pending->isDepositLate());
        $this->assertTrue(AdminNotification::where('type', TuitionSlaService::TYPE_CASH_DEPOSIT)->where('data->receipt_id', $pending->id)->exists());

        // Công nợ: 2 học viên đóng đủ, 2 đóng 1 phần, 1 chưa đóng (quá hạn).
        $tuitions = StudentTuition::whereIn('student_id', $studentIds)->get();
        $this->assertEqualsCanonicalizing(['paid', 'paid', 'partial', 'partial', 'unpaid'], $tuitions->pluck('status')->all());

        // Không có mốc nào sau "bây giờ".
        $this->assertSame(0, TuitionReceipt::whereIn('student_id', $studentIds)->where('created_at', '>', now())->count());
        $this->assertSame(0, $reports->filter(fn ($r) => $r->created_at->gt(now()))->count());

        // Chạy lại: không nhân bản.
        $counts = fn () => [
            CrmCustomer::count(), AcademicRecord::count(), TuitionRefundRequest::count(), InvoiceCancellation::count(),
            TuitionReceipt::count(), AdminNotification::count(),
        ];
        $before = $counts();
        $this->seed(DemoCoverageFinanceSeeder::class);
        $this->assertSame($before, $counts());
    }
}
