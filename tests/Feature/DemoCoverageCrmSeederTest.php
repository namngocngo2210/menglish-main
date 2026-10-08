<?php

namespace Tests\Feature;

use App\Models\ClassEnrollment;
use App\Models\CrmBranchTransfer;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\CrmTrialBooking;
use App\Models\Penalty;
use App\Models\Promotion;
use App\Models\SlaEvent;
use App\Models\SlaSetting;
use App\Models\StudentTuition;
use App\Models\WorkTask;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCoverageCrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Dữ liệu demo CRM bổ sung: chuyển cơ sở, ưu đãi, học thử, xác nhận chính thức, SLA, thùng rác, nhập Excel — chạy lại không nhân bản. */
class DemoCoverageCrmSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_every_crm_case_and_is_idempotent(): void
    {
        Storage::fake('local');
        $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoCoverageCrmSeeder::class);

        $leads = CrmCustomer::withTrashed()->where(fn ($q) => $q->where('phone_normalized', 'like', '0868200%')->orWhere('phone', 'like', '0868200%'))->get()->keyBy('name');
        $this->assertCount(18, $leads);
        $ids = $leads->pluck('id');

        // Chuyển cơ sở: đủ 3 trạng thái; duyệt → khách sang cơ sở mới, từ chối → giữ nguyên.
        foreach (array_keys(CrmBranchTransfer::STATUSES) as $status) {
            $this->assertTrue(CrmBranchTransfer::whereIn('customer_id', $ids)->where('status', $status)->exists(), "Thiếu yêu cầu chuyển cơ sở {$status}.");
        }
        $approved = CrmBranchTransfer::whereIn('customer_id', $ids)->where('status', 'approved')->first();
        $this->assertSame($approved->to_branch_id, $approved->customer->branch_id);
        $rejected = CrmBranchTransfer::whereIn('customer_id', $ids)->where('status', 'rejected')->first();
        $this->assertSame($rejected->from_branch_id, $rejected->customer->branch_id);
        $this->assertNotNull($rejected->decision_note);

        // Ưu đãi: %, tiền cố định, mặc định đang chạy, hết hạn, hết lượt, ngừng áp dụng, chưa bắt đầu, ưu đãi riêng.
        $promo = fn (string $name) => Promotion::where('name', $name)->firstOrFail();
        $default = $promo(DemoCoverageCrmSeeder::MARKER);
        $this->assertTrue($default->is_default && $default->type === 'percent' && $default->isApplicable(null, null));
        $this->assertSame('fixed', $promo('# Giảm 500.000đ — học viên cũ giới thiệu')->type);
        $this->assertTrue($promo('# Ưu đãi hè 2026')->ends_at->isPast());
        $limited = $promo('# Early bird Starters — 2 suất');
        $this->assertSame([2, 2], [$limited->used_count, $limited->usage_limit]);
        $this->assertFalse($limited->isApplicable(null, $limited->course_id));
        $this->assertFalse($promo('# Ưu đãi anh chị em ruột (Cầu Giấy)')->is_active);
        $this->assertTrue($promo('# Ưu đãi Tết 2027')->starts_at->isFuture());
        $special = Promotion::where('is_special', true)->where('name', 'like', '# Ưu đãi riêng%')->firstOrFail();
        $this->assertSame(1, $special->used_count);
        $this->assertNotNull($special->reason);
        $applied = StudentTuition::whereIn('promotion_id', Promotion::where('name', 'like', '# %')->select('id'))->where('discount_amount', '>', 0)->count();
        $this->assertGreaterThanOrEqual(5, $applied);

        // Xác nhận chính thức: đã xác nhận, đang lưu tiến độ (thiếu giáo trình), chưa làm gì.
        $enrollments = ClassEnrollment::whereIn('customer_id', $ids)->get();
        $this->assertTrue($enrollments->contains(fn ($e) => $e->confirmed_at && $e->status === 'completed'));
        $this->assertTrue($enrollments->contains(fn ($e) => ! $e->confirmed_at && $e->account_sent && ! $e->curriculum_delivered));
        $this->assertTrue($enrollments->contains(fn ($e) => ! $e->confirmed_at && ! $e->account_sent));

        // Học thử: vắng mặt, đã hủy, khách đã dùng hết 2 lượt.
        $bookings = CrmTrialBooking::with('session')->whereIn('customer_id', $ids)->get();
        foreach (['no_show', 'cancelled', 'attended', 'scheduled'] as $status) {
            $this->assertTrue($bookings->contains('status', $status), "Thiếu buổi học thử {$status}.");
        }
        $twice = $leads['# Ngô Khánh Linh'];
        $this->assertTrue(CrmTrialBooking::stateFor($bookings->where('customer_id', $twice->id))['exhausted']);

        // SLA: cấu hình + sổ đủ trạng thái.
        $this->assertSame(5, SlaSetting::where('rule_key', 'like', 'crm.%')->count());
        $events = SlaEvent::all();
        $this->assertTrue($events->contains(fn ($e) => $e->resolved_at && ! $e->breached_at), 'Thiếu mốc SLA xong đúng hạn.');
        $this->assertTrue($events->contains(fn ($e) => $e->breached_at && $e->penalty_id && ! $e->resolved_at), 'Thiếu mốc quá hạn có biên bản.');
        $this->assertTrue($events->contains(fn ($e) => $e->breached_at && $e->resolved_at && $e->penalty_id), 'Thiếu mốc xong trễ.');
        $this->assertTrue($events->contains(fn ($e) => $e->breached_at && ! $e->penalty_id && $e->rule_key === 'crm.tuition_followup'), 'Thiếu cảnh báo không phạt.');
        $open = $events->filter(fn ($e) => ! $e->breached_at && ! $e->resolved_at);
        $this->assertTrue($open->contains(fn ($e) => $e->due_at->isFuture() && now()->diffInHours($e->due_at) < 42), 'Thiếu mốc sắp hết hạn.');
        $this->assertTrue($open->contains(fn ($e) => now()->diffInHours($e->due_at) > 42), 'Thiếu mốc còn hạn.');
        $this->assertTrue($events->contains('rule_key', 'crm.failed_contacts'));
        $penalty = Penalty::whereIn('id', $events->pluck('penalty_id')->filter())->first();
        $this->assertSame('pending', $penalty->status);
        Storage::disk('local')->assertExists($penalty->evidence_path);
        $this->assertSame('overdue', WorkTask::find($events->firstWhere('rule_key', 'crm.tuition_followup')->work_task_id)->status);

        // Thùng rác + nhập Excel (2 dòng hợp lệ, 2 dòng lỗi bỏ qua).
        $this->assertTrue($leads['# Đinh Văn Tùng']->trashed());
        $imported = $leads->where('source', 'Hội thảo phụ huynh');
        $this->assertCount(2, $imported);
        $this->assertTrue(CrmCustomerHistory::whereIn('customer_id', $imported->pluck('id'))->where('content', 'like', '%nhập Excel%')->exists());

        // Không có mốc nào sau "bây giờ".
        $this->assertSame(0, CrmCustomerHistory::whereIn('customer_id', $ids)->where('created_at', '>', now())->count());
        $this->assertSame(0, SlaEvent::where('created_at', '>', now())->count());

        $counts = fn () => [CrmCustomer::withTrashed()->count(), Promotion::count(), CrmBranchTransfer::count(), CrmTrialBooking::count(),
            ClassEnrollment::count(), SlaEvent::count(), SlaSetting::count(), Penalty::count(), CrmCustomerHistory::count()];
        $before = $counts();
        $this->seed(DemoCoverageCrmSeeder::class);
        $this->assertSame($before, $counts());
    }
}
