<?php

namespace Tests\Feature;

use App\Models\CandidateCv;
use App\Models\InvoiceCancellation;
use App\Models\JobPosting;
use App\Models\MaterialOrder;
use App\Models\MerchandiseItem;
use App\Models\MerchandiseStock;
use App\Models\MerchandiseStockMovement;
use App\Models\TimesheetSyncLog;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCoverageOpsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Dữ liệu demo Vận hành & nhân sự: đủ trạng thái tuyển dụng / tài khoản / order học liệu / đồng bộ chấm công / kho, chạy lại không nhân bản. */
class DemoCoverageOpsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_every_ops_case_and_is_idempotent(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoCoverageOpsSeeder::class);

        // Tuyển dụng: tin đang mở, mở nhưng quá hạn nộp, đã đóng; CV đủ các bước của pipeline.
        $this->assertTrue(JobPosting::where('title', DemoCoverageOpsSeeder::MARKER)->where('is_active', true)->whereDate('deadline', '>=', today())->exists());
        $this->assertTrue(JobPosting::where('title', 'like', '# %')->where('is_active', true)->whereDate('deadline', '<', today())->exists(), 'Thiếu tin còn mở đã quá hạn nộp.');
        $this->assertTrue(JobPosting::where('title', 'like', '# %')->where('is_active', false)->exists(), 'Thiếu tin đã đóng.');
        foreach (['pending', 'reviewing', 'interviewed', 'accepted', 'rejected'] as $status) {
            $this->assertTrue(CandidateCv::where('status', $status)->exists(), "Thiếu CV {$status}.");
        }
        $this->assertTrue(CandidateCv::whereNull('job_posting_id')->exists());
        $this->assertSame(0, CandidateCv::where('status', '!=', 'pending')->whereNull('reviewed_by')->count());

        // Tài khoản: bị khóa, ngừng hoạt động (nghỉ việc), GV dạy nhiều cơ sở (user_branches).
        $locked = User::where('email', 'sale.minhhieu@menglish.edu.vn')->firstOrFail();
        $this->assertNotNull($locked->locked_at);
        $this->assertTrue($locked->isLocked());
        $resigned = User::where('email', 'gv.lananh@menglish.edu.vn')->firstOrFail();
        $this->assertFalse($resigned->is_active);
        $this->assertSame('expired', $resigned->contractExpiryStatus());
        $multi = User::where('email', 'gv.quocbao@menglish.edu.vn')->firstOrFail();
        $this->assertCount(3, $multi->branchIds());
        $this->assertFalse($multi->isLocked());

        // Order học liệu ngoài học liệu học thuật: đủ loại và trạng thái, có tạo trễ / xử lý trễ.
        $ops = MaterialOrder::where('category', '!=', MaterialOrder::CATEGORY_ACADEMIC);
        foreach ([MaterialOrder::CATEGORY_PROPS, MaterialOrder::CATEGORY_PRINTING, MaterialOrder::CATEGORY_FOREIGN_TEACHER] as $category) {
            $this->assertTrue((clone $ops)->where('category', $category)->exists(), "Thiếu order {$category}.");
        }
        foreach (array_keys(MaterialOrder::STATUSES) as $status) {
            $this->assertTrue((clone $ops)->where('status', $status)->exists(), "Thiếu order {$status}.");
        }
        $this->assertNotNull((clone $ops)->where('status', MaterialOrder::STATUS_REJECTED)->value('reject_reason'));
        $overdue = (clone $ops)->where('status', MaterialOrder::STATUS_OVERDUE)->firstOrFail();
        $this->assertTrue($overdue->due_at->lt(now()));
        $this->assertTrue(DB::table('admin_notifications')->where('type', 'material_order_overdue')->exists());
        $this->assertTrue((clone $ops)->where('created_late', true)->exists());
        $this->assertTrue((clone $ops)->where('status', MaterialOrder::STATUS_DONE)->where('processed_late', true)->exists());
        $this->assertSame(0, (clone $ops)->where('created_at', '>', now())->count());

        // Đồng bộ chấm công: thành công, lỗi một phần (có dòng lỗi), lỗi toàn bộ (mã lỗi).
        foreach (['success', 'partial', 'failed'] as $status) {
            $this->assertTrue(TimesheetSyncLog::where('status', $status)->exists(), "Thiếu đợt đồng bộ {$status}.");
        }
        $this->assertNotEmpty(TimesheetSyncLog::where('status', 'partial')->firstOrFail()->error_rows);
        $this->assertNotNull(TimesheetSyncLog::where('status', 'failed')->firstOrFail()->error_code);

        // Kho: đủ loại xuất nhập, tồn theo chi nhánh có Sắp hết / Hết / Âm, việc nhập bù tự giao + tự hoàn thành.
        foreach (['opening', 'import', 'sale', 'return', 'count'] as $type) {
            $this->assertTrue(MerchandiseStockMovement::where('type', $type)->exists(), "Thiếu dòng kho {$type}.");
        }
        $this->assertTrue(MerchandiseStockMovement::where('type', 'count')->where('quantity_change', '<', 0)->exists());
        $this->assertTrue(MerchandiseStockMovement::where('type', 'count')->where('quantity_change', '>', 0)->exists());
        $this->assertSame(3, MerchandiseStock::distinct()->count('branch_id'));
        foreach (['negative', 'out', 'low'] as $level) {
            $this->assertTrue(MerchandiseStock::all()->contains(fn (MerchandiseStock $s) => MerchandiseStock::level($s->quantity) === $level), "Thiếu tồn {$level}.");
        }
        foreach (MerchandiseStock::all() as $stock) {
            $this->assertSame($stock->quantity, (int) MerchandiseStockMovement::where('merchandise_item_id', $stock->merchandise_item_id)->where('branch_id', $stock->branch_id)->sum('quantity_change'));
        }
        $restock = WorkTask::where('kind', WorkTask::KIND_MERCHANDISE_RESTOCK);
        $this->assertTrue((clone $restock)->where('status', 'completed')->exists(), 'Thiếu việc nhập bù đã tự hoàn thành.');
        $this->assertSame(2, (clone $restock)->whereIn('status', WorkTask::OPEN_STATUSES)->count());
        $this->assertTrue(MerchandiseItem::where('is_active', false)->exists());
        $this->assertTrue(MerchandiseItem::where('stock_quantity', '>', 0)->exists(), 'Thiếu tồn cũ chưa phân chi nhánh.');
        $receipts = TuitionReceipt::where('notes', 'like', '%'.DemoCoverageOpsSeeder::TAG.'%');
        $this->assertTrue((clone $receipts)->where('status', 'pending')->exists());
        $this->assertTrue((clone $receipts)->where('status', 'cancelled')->exists());
        $this->assertTrue(InvoiceCancellation::where('status', 'approved')->whereIn('tuition_receipt_id', (clone $receipts)->select('id'))->exists());

        // Chạy lại: bỏ qua, không nhân bản.
        $counts = fn () => [JobPosting::count(), CandidateCv::count(), User::count(), DB::table('user_branches')->count(), MaterialOrder::count(),
            TimesheetSyncLog::count(), MerchandiseItem::count(), MerchandiseStockMovement::count(), WorkTask::count(), TuitionReceipt::count()];
        $before = $counts();
        $this->seed(DemoCoverageOpsSeeder::class);
        $this->assertSame($before, $counts());
    }
}
