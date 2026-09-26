<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ClassReport;
use App\Models\CrmCustomer;
use App\Models\InvoiceCancellation;
use App\Models\OperatingExpense;
use App\Models\SepayTransaction;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\TuitionContactLog;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\WorkTask;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoPhase4Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Dữ liệu demo Phase 4: đủ trạng thái học phí / SePay / hoàn – chuyển – bảo lưu – khất nợ / quá hạn / nền tảng, chạy lại không nhân bản. */
class DemoPhase4SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_builds_a_coherent_phase4_dataset_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);

        $customers = CrmCustomer::where('phone', 'like', '0388%')->orderBy('phone')->get();
        $this->assertCount(9, $customers);
        $tuitionOf = fn (int $n) => StudentTuition::where('student_id', $customers[$n]->converted_student_id)->firstOrFail();

        // Tài khoản NH + dải số HĐ theo chi nhánh; phiếu thu đủ trạng thái; HĐ cấp theo dải chi nhánh.
        foreach (['CG', 'BD'] as $code) {
            $branchId = DB::table('branches')->where('code', $code)->value('id');
            $this->assertTrue(BankAccount::where('branch_id', $branchId)->where('is_active', true)->exists(), "Thiếu TK ngân hàng {$code}.");
            $this->assertTrue(TuitionReceipt::where('invoice_number', 'like', "C26M{$code}%")->where('status', 'approved')->exists(), "Thiếu HĐ dải {$code}.");
        }
        foreach (['draft', 'pending', 'approved', 'rejected', 'cancelled'] as $status) {
            $this->assertTrue(TuitionReceipt::where('status', $status)->where('notes', 'like', '%[demo-p4]%')->exists(), "Thiếu phiếu thu {$status}.");
        }
        $this->assertSame(0, TuitionReceipt::where('status', 'approved')->where('created_at', '>', now()->subDays(60))->whereIn('student_tuition_id', StudentTuition::whereIn('student_id', $customers->pluck('converted_student_id'))->select('id'))->whereNull('invoice_number')->count());
        $this->assertSame(0, TuitionReceipt::where('status', 'approved')->where('notes', 'like', '%[demo-p4]%')->whereColumn('creator_id', 'approver_id')->count(),
            'Phiếu tay đã duyệt không do chính người lập duyệt.');

        // Khách A: 2 đợt (phiếu tay bị trả về rồi duyệt + SePay) → công nợ 0.
        $a = $tuitionOf(0);
        $this->assertSame('paid', $a->status);
        $this->assertEquals(0, (float) $a->debt_amount);
        $this->assertSame(1, SepayTransaction::where('status', 'matched')->where('matched_tuition_id', $a->id)->count());
        $this->assertTrue(TuitionReceipt::where('student_tuition_id', $a->id)->where('status', 'approved')->whereNotNull('rejection_reason')->doesntExist());

        // SePay: đủ các nhánh đối soát, không có giao dịch nào gạch nợ 2 lần.
        foreach (['matched', 'duplicate_manual', 'rejected_account', 'unmatched'] as $status) {
            $this->assertTrue(SepayTransaction::where('status', $status)->exists(), "Thiếu giao dịch SePay {$status}.");
        }
        $dup = SepayTransaction::where('status', 'duplicate_manual')->firstOrFail();
        $this->assertSame('approved', TuitionReceipt::findOrFail($dup->matched_receipt_id)->status, 'Phiếu tay trùng mã SePay vẫn được duyệt.');
        $this->assertSame(1, TuitionReceipt::where('transaction_code', $dup->sepay_id)->count());

        // Hủy HĐ khôi phục nợ; hoàn / chuyển nhượng / bảo lưu / khất nợ; hồ sơ hoàn phí chờ duyệt quá 1 tuần.
        $this->assertTrue(InvoiceCancellation::where('status', 'approved')->exists());
        $this->assertTrue(InvoiceCancellation::where('status', 'pending')->exists());
        $j = $tuitionOf(8);
        $this->assertEquals((float) $j->final_amount, (float) $j->debt_amount);
        foreach (['transfer', 'extension', 'deferral'] as $type) {
            $this->assertTrue(TuitionRefundRequest::where('type', $type)->where('status', 'approved')->exists(), "Thiếu hồ sơ {$type} đã duyệt.");
        }
        $this->assertTrue(TuitionRefundRequest::where('type', 'refund')->where('status', 'pending')->where('created_at', '<', now()->subWeek())->exists());
        $this->assertSame('deferred', Student::findOrFail($customers[6]->converted_student_id)->status);
        $this->assertTrue($tuitionOf(3)->remindersPausedOn());

        // Quá hạn ≥ 7 ngày và 1–6 ngày, nhật ký liên hệ / báo Admin, mốc nhắc nợ T+7.
        $overdue = StudentTuition::whereIn('student_id', $customers->pluck('converted_student_id'))->where('debt_amount', '>', 0)->get()
            ->reject->remindersPausedOn();
        $this->assertTrue($overdue->contains(fn ($t) => $t->daysOverdue() >= 7));
        $this->assertTrue($overdue->contains(fn ($t) => $t->daysOverdue() >= 1 && $t->daysOverdue() < 7));
        $this->assertTrue(TuitionContactLog::where('action', TuitionContactLog::ACTION_CONTACTED)->exists());
        $this->assertTrue(TuitionContactLog::where('action', TuitionContactLog::ACTION_REPORTED)->exists());
        $this->assertDatabaseHas('debt_reminder_rules', ['milestone_key' => 'T+7']);

        // Thu chi, nền tảng.
        $this->assertGreaterThanOrEqual(2, OperatingExpense::where('notes', 'like', '%[demo-p4]%')->distinct()->count('branch_id'));
        $this->assertTrue(WorkTask::where('title', 'like', '%[demo-p4]%')->where('status', 'completed')->exists());
        $this->assertSame(3, WorkTask::where('title', 'like', '%[demo-p4]%')->whereIn('time_slot_category', ['before', 'during', 'after'])
            ->whereNotNull('class_id')->distinct()->count('time_slot_category'));
        $this->assertTrue(ClassReport::where('session_name', 'like', '%[demo-p4]%')->where('has_image', true)->where('status', 'approved')->exists());
        $this->assertTrue(ClassReport::where('session_name', 'like', '%[demo-p4]%')->where('has_image', false)->where('status', 'pending_approval')->exists());
        $this->assertSame(2, SupportTicket::where('title', 'like', '%[demo-p4]%')->count());
        $this->assertTrue(TicketMessage::where('is_internal_note', true)->exists());
        $this->assertTrue(UserPermissionOverride::where('scope_type', UserPermissionOverride::SCOPE_BRANCH)->exists());
        $this->assertTrue(UserPermissionOverride::where('scope_type', UserPermissionOverride::SCOPE_CLASS)->exists());
        $this->assertTrue(User::where('email', 'ketoan.moi@menglish.edu.vn')->value('must_change_password'));
        $teacher = User::where('email', 'gv.cohuu1@menglish.edu.vn')->firstOrFail();
        $this->assertTrue($teacher->contract_end_date->between(now(), now()->addDays(30)));
        $log = Activity::where('subject_type', User::class)->where('subject_id', $teacher->id)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertArrayHasKey('contract_end_date', $log->properties['attributes']);
        $this->assertArrayHasKey('contract_end_date', $log->properties['old']);
        $this->assertDatabaseHas('admin_notifications', ['type' => 'contract_expiring']);

        // Chạy lại không tạo trùng.
        $tables = ['crm_customers', 'students', 'student_tuitions', 'tuition_receipts', 'sepay_transactions', 'invoice_cancellations', 'tuition_refund_requests',
            'tuition_contact_logs', 'bank_accounts', 'invoice_configurations', 'operating_expenses', 'work_tasks', 'class_reports', 'support_tickets',
            'ticket_messages', 'user_permission_overrides', 'users'];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]);
        $this->seed(DemoPhase4Seeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before->all(), collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all());

        // Màn hình Phase 4 mở được bằng tài khoản demo.
        $accountant = User::where('email', 'ketoan2@menglish.edu.vn')->firstOrFail();
        $admin = User::where('email', 'admin@menglish.edu.vn')->firstOrFail();
        $managerBd = User::where('email', 'manager.bd@menglish.edu.vn')->firstOrFail();
        foreach (['tuition.students', 'tuition.receipts.approve', 'tuition.history', 'tuition.invoices.cancellations', 'tuition.refunds',
            'tuition.overdue', 'tuition.config', 'finance.reports.revenue', 'finance.expenses.index', 'system-config.bank-accounts',
            'system-config.debt-reminders'] as $route) {
            $this->actingAs($accountant)->get(route($route))->assertOk();
        }
        $this->actingAs($managerBd)->get(route('tuition.overdue'))->assertOk()
            ->assertViewHas('overdueTuitions', fn ($rows) => $rows->contains(fn ($t) => str_contains($t->student->name, 'Quách Thu Trang'))
                && ! $rows->contains(fn ($t) => str_contains($t->student->name, 'Hồ Minh Châu')));
        $this->actingAs($admin)->get(route('activity-logs.index'))->assertOk();
        $this->actingAs($admin)->get(route('tasks.manual-approvals'))->assertOk();
        $this->actingAs($admin)->get(route('tickets.index'))->assertOk();
        $this->actingAs($admin)->get(route('users.index', ['search' => 'gv.cohuu1']))->assertOk()->assertSee('HĐ sắp hết hạn');
    }
}
