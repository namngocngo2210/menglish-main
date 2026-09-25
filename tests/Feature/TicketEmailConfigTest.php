<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\CrmCustomer;
use App\Models\PlacementTest;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SupportTicket;
use App\Models\SystemSetting;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TicketEmailConfigTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $teacher;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin System',
            'email' => 'admin.test@meducation.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->admin->syncRoles(['admin']);

        $this->teacher = User::create([
            'name' => 'Teacher One',
            'email' => 'teacher.test@meducation.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->teacher->syncRoles(['teacher']);
    }

    public function test_admin_can_view_ticket_email_config_page(): void
    {
        $response = $this->actingAs($this->admin)->get(route('system-config.ticket-emails'));

        $response->assertStatus(200);
        $response->assertSee('Cấu hình Email nhận Ticket');
        $response->assertSee('Danh sách Email nhận thông báo Ticket');
        $response->assertSee('Gửi Thử Nghiệm (Test Email)');
    }

    public function test_admin_can_save_multiple_recipient_emails_and_event_triggers(): void
    {
        $emails = [
            'dev1@meducation.vn',
            'manager@meducation.vn',
            'support@meducation.vn',
        ];

        $response = $this->actingAs($this->admin)->post(route('system-config.ticket-emails.update'), [
            'emails' => $emails,
            'notify_created' => '1',
            'notify_comment' => '1',
            'notify_status_changed' => '0',
        ]);

        $response->assertRedirect(route('system-config.ticket-emails'));
        $response->assertSessionHas('status');

        $configuredEmails = SystemSetting::getTicketEmails();
        $this->assertEquals($emails, $configuredEmails);
        $this->assertTrue(SystemSetting::isTicketEventEnabled('created'));
        $this->assertTrue(SystemSetting::isTicketEventEnabled('comment'));
        $this->assertFalse(SystemSetting::isTicketEventEnabled('status_changed'));
    }

    public function test_admin_can_save_smtp_configuration_in_database(): void
    {
        $payload = [
            'emails' => ['support@meducation.vn'],
            'mail_host' => 'smtp.gmail.com',
            'mail_port' => '587',
            'mail_encryption' => 'tls',
            'mail_username' => 'mycenter@gmail.com',
            'mail_password' => 'abcd efgh ijkl mnop',
            'mail_from_address' => 'mycenter@gmail.com',
            'mail_from_name' => 'MEnglish Test Center',
        ];

        $response = $this->actingAs($this->admin)->post(route('system-config.ticket-emails.update'), $payload);
        $response->assertRedirect(route('system-config.ticket-emails'));

        $this->assertEquals('smtp.gmail.com', SystemSetting::get('mail_host'));
        $this->assertEquals('587', SystemSetting::get('mail_port'));
        $this->assertEquals('tls', SystemSetting::get('mail_encryption'));
        $this->assertEquals('mycenter@gmail.com', SystemSetting::get('mail_username'));
        // Verify spaces stripped from app password
        $this->assertEquals('abcdefghijklmnop', SystemSetting::get('mail_password'));
        $this->assertEquals('mycenter@gmail.com', SystemSetting::get('mail_from_address'));
        $this->assertEquals('MEnglish Test Center', SystemSetting::get('mail_from_name'));

        $smtp = SystemSetting::getSmtpConfig();
        $this->assertEquals('smtp.gmail.com', $smtp['host']);
        $this->assertEquals(587, $smtp['port']);
        $this->assertTrue($smtp['has_password']);
    }

    public function test_invalid_email_formats_and_duplicates_are_sanitized(): void
    {
        $payload = [
            'emails' => [
                'clean@meducation.vn',
                'clean@meducation.vn', // duplicate
                'INVALID_EMAIL_STRING',
                'another@meducation.vn',
            ],
            'notify_created' => '1',
        ];

        $response = $this->actingAs($this->admin)->post(route('system-config.ticket-emails.update'), $payload);
        $response->assertRedirect(route('system-config.ticket-emails'));

        $configuredEmails = SystemSetting::getTicketEmails();
        $this->assertEquals(['clean@meducation.vn', 'another@meducation.vn'], $configuredEmails);
    }

    public function test_admin_can_remove_email_from_list(): void
    {
        // 1. Initial configuration with 3 emails
        SystemSetting::set('ticket_notification_emails', [
            'keep1@meducation.vn',
            'delete_me@meducation.vn',
            'keep2@meducation.vn',
        ]);

        $this->assertCount(3, SystemSetting::getTicketEmails());

        // 2. Submit form after removing 'delete_me@meducation.vn'
        $response = $this->actingAs($this->admin)->post(route('system-config.ticket-emails.update'), [
            'emails' => ['keep1@meducation.vn', 'keep2@meducation.vn'],
        ]);

        $response->assertRedirect(route('system-config.ticket-emails'));

        $updatedEmails = SystemSetting::getTicketEmails();
        $this->assertEquals(['keep1@meducation.vn', 'keep2@meducation.vn'], $updatedEmails);
        $this->assertNotContains('delete_me@meducation.vn', $updatedEmails);
    }

    public function test_admin_can_send_test_email(): void
    {
        Mail::spy();

        $response = $this->actingAs($this->admin)->post(route('system-config.ticket-emails.test'), [
            'test_email' => 'tech.diagnostics@meducation.vn',
        ]);

        $response->assertRedirect(route('system-config.ticket-emails'));
        $response->assertSessionHas('status');

        Mail::shouldHaveReceived('html')->once();
    }

    public function test_ticket_creation_dispatches_mail_to_configured_emails(): void
    {
        Mail::spy();

        SystemSetting::set('ticket_notification_emails', [
            'alert1@meducation.vn',
            'alert2@meducation.vn',
        ]);
        SystemSetting::set('ticket_notify_created', true);

        $response = $this->actingAs($this->teacher)->post(route('tickets.store'), [
            'title' => 'Lỗi kết nối máy in phòng giáo viên',
            'category' => 'technical_issue',
            'priority' => 'high',
            'description' => 'Không thể kết nối máy in Brother qua wifi nội bộ',
        ]);

        $response->assertRedirect();

        // Check that HTML emails were sent twice (once per recipient)
        Mail::shouldHaveReceived('html')->twice();
    }

    public function test_ticket_creation_respects_disabled_trigger(): void
    {
        Mail::spy();

        SystemSetting::set('ticket_notification_emails', ['alert@meducation.vn']);
        SystemSetting::set('ticket_notify_created', false);

        $response = $this->actingAs($this->teacher)->post(route('tickets.store'), [
            'title' => 'Lỗi điều hòa phòng 102',
            'category' => 'facility',
            'priority' => 'medium',
            'description' => 'Điều hòa chảy nước',
        ]);

        $response->assertRedirect();

        // Since trigger is disabled, no email should be sent
        Mail::shouldNotHaveReceived('html');
    }

    public function test_ticket_email_formats_are_concise_and_direct(): void
    {
        Mail::spy();

        SystemSetting::set('ticket_notification_emails', ['direct@meducation.vn']);
        SystemSetting::set('ticket_notify_created', true);
        SystemSetting::set('ticket_notify_comment', true);
        SystemSetting::set('ticket_notify_status_changed', true);

        // 1. Create ticket
        $this->actingAs($this->teacher)->post(route('tickets.store'), [
            'title' => 'Máy chiếu phòng 201 chập chờn',
            'category' => 'technical_issue',
            'priority' => 'urgent',
            'description' => 'Màn hình bị nháy liên tục khi cắm dây HDMI',
        ]);

        $ticket = SupportTicket::where('title', 'Máy chiếu phòng 201 chập chờn')->firstOrFail();
        // Assert 4-digit numeric code
        $this->assertMatchesRegularExpression('/^[0-9]{4}$/', $ticket->code);

        // Verify creation email format: directly in title, no "Kính gửi", has colorful template badges
        Mail::shouldHaveReceived('html')->with(
            \Mockery::on(function ($body) use ($ticket) {
                return str_contains($body, 'Màn hình bị nháy liên tục khi cắm dây HDMI')
                    && ! str_contains($body, 'Kính gửi')
                    && str_contains($body, '#'.$ticket->code);
            }),
            \Mockery::any()
        );

        // 2. Reply message
        $replyRes = $this->actingAs($this->admin)->post(route('tickets.messages.store', $ticket->id), [
            'message' => 'Đã thay dây cáp HDMI mới, thầy kiểm tra lại nhé.',
        ]);
        $replyRes->assertRedirect();

        // Verify reply email format: Re: [#1001] Title
        Mail::shouldHaveReceived('html')->with(
            \Mockery::on(function ($body) {
                return str_contains($body, 'Đã thay dây cáp HDMI mới')
                    && ! str_contains($body, 'Kính gửi')
                    && str_contains($body, 'Admin System:');
            }),
            \Mockery::any()
        );
    }

    public function test_admin_can_toggle_all_operational_event_triggers(): void
    {
        $response = $this->actingAs($this->admin)->post(route('system-config.ticket-emails.update'), [
            'emails' => ['ops@meducation.vn'],
            'notify_created' => '1',
            'notify_comment' => '1',
            'notify_status_changed' => '1',
            'notify_stale_lead' => '1',
            'notify_transaction' => '1',
            'notify_overdue_debt' => '0', // Unchecked
            'notify_homework' => '1',
        ]);

        $response->assertRedirect(route('system-config.ticket-emails'));

        $this->assertTrue(SystemSetting::isTicketEventEnabled('stale_lead'));
        $this->assertTrue(SystemSetting::isTicketEventEnabled('transaction'));
        $this->assertFalse(SystemSetting::isTicketEventEnabled('overdue_debt'));
        $this->assertTrue(SystemSetting::isTicketEventEnabled('homework'));
    }

    public function test_stale_lead_scan_dispatches_operational_email_when_enabled(): void
    {
        Mail::spy();

        SystemSetting::set('ticket_notification_emails', ['crm.lead@meducation.vn']);
        SystemSetting::set('ticket_notify_stale_lead', true);

        // Tạo 1 lead 'new' từ 30 giờ trước
        Carbon::setTestNow(Carbon::now()->subHours(30));
        $lead = CrmCustomer::create([
            'code' => 'CRM-LEAD-99',
            'name' => 'Nguyễn Thu Hà',
            'phone' => '0988111222',
            'stage' => 'new',
            'source' => 'facebook',
        ]);
        Carbon::setTestNow(); // Reset về thời điểm hiện tại

        $service = app(NotificationService::class);
        $scannedCount = $service->scanAndSyncStaleLeads();

        $this->assertEquals(1, $scannedCount);

        Mail::shouldHaveReceived('html')->once()->with(
            \Mockery::on(function ($body) use ($lead) {
                return str_contains($body, $lead->name)
                    && str_contains($body, 'Cảnh báo')
                    && str_contains($body, $lead->code);
            }),
            \Mockery::any()
        );
    }

    public function test_tuition_receipt_creation_dispatches_transaction_alert_email(): void
    {
        Mail::spy();

        SystemSetting::set('ticket_notification_emails', ['finance@meducation.vn']);
        SystemSetting::set('ticket_notify_transaction', true);

        $class = ClassModel::create([
            'name' => 'Lớp Giao Tiếp Cơ Bản',
            'code' => 'GT-01',
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $student = Student::create([
            'code' => 'HV-101',
            'name' => 'Trần Văn Khang',
            'phone' => '0912345678',
            'branch_id' => $this->branch->id,
            'status' => 'studying',
        ]);

        $tuition = StudentTuition::create([
            'student_id' => $student->id,
            'class_id' => $class->id,
            'branch_id' => $this->branch->id,
            'total_amount' => 5000000,
            'debt_amount' => 5000000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        // Tạo phiếu thu đã duyệt
        $receipt = TuitionReceipt::create([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(),
            'student_tuition_id' => $tuition->id,
            'amount' => 2500000,
            'payment_method' => 'transfer',
            'status' => 'approved',
            'creator_id' => $this->admin->id,
        ]);

        Mail::shouldHaveReceived('html')->once()->with(
            \Mockery::on(function ($body) use ($student, $receipt) {
                return str_contains($body, $receipt->receipt_number)
                    && str_contains($body, $student->name)
                    && (str_contains($body, '2.500.000') || str_contains($body, '2,500,000'));
            }),
            \Mockery::any()
        );
    }

    public function test_overdue_debt_reminder_dispatches_alert_email(): void
    {
        Mail::spy();

        SystemSetting::set('ticket_notification_emails', ['debt@meducation.vn']);
        SystemSetting::set('ticket_notify_overdue_debt', true);

        $student = Student::create([
            'code' => 'HV-102',
            'name' => 'Lê Hoàng Nam',
            'phone' => '0933445566',
            'branch_id' => $this->branch->id,
            'status' => 'studying',
        ]);

        $tuition = StudentTuition::create([
            'student_id' => $student->id,
            'branch_id' => $this->branch->id,
            'total_amount' => 6000000,
            'debt_amount' => 6000000,
            'paid_amount' => 0,
            'due_date' => Carbon::now()->subDays(5),
            'status' => 'overdue',
        ]);

        $response = $this->actingAs($this->admin)->post(route('tuition.overdue.remind', $tuition->id));
        $response->assertRedirect();

        // Engine nhắc nợ theo mốc DebtReminderRule (fallback template mặc định khi chưa cấu hình)
        Mail::shouldHaveReceived('html')->once()->with(
            \Mockery::on(function ($body) use ($student) {
                return str_contains($body, 'Nhắc nợ')
                    && str_contains($body, $student->name)
                    && (str_contains($body, '6.000.000') || str_contains($body, '6,000,000'));
            }),
            \Mockery::any()
        );
    }

    public function test_placement_test_submission_dispatches_homework_alert_email(): void
    {
        Mail::spy();

        SystemSetting::set('ticket_notification_emails', ['academic@meducation.vn']);
        SystemSetting::set('ticket_notify_homework', true);

        $test = PlacementTest::create([
            'code' => 'TEST-PORTAL-01',
            'title' => 'Bài Test Đánh Giá Đầu Vào Tổng Hợp',
            'target_level' => 'IELTS 6.0',
            'duration_minutes' => 45,
            'is_active' => true,
        ]);

        $response = $this->post("/portal/placement-test/{$test->code}/submit", [
            'candidate_name' => 'Phạm Minh Đức',
            'candidate_phone' => '0977889900',
            'candidate_email' => 'duc.pm@gmail.com',
            'answers' => [
                '1' => 'B',
                '2' => 'A',
            ],
            'writing_content' => 'This is my English writing task essay for testing.',
        ]);

        $response->assertRedirect();

        Mail::shouldHaveReceived('html')->once()->with(
            \Mockery::on(function ($body) use ($test) {
                return str_contains($body, 'Phạm Minh Đức')
                    && str_contains($body, $test->title)
                    && str_contains($body, 'Học viên nộp bài');
            }),
            \Mockery::any()
        );
    }

    public function test_operational_alerts_respect_disabled_triggers(): void
    {
        Mail::spy();

        SystemSetting::set('ticket_notification_emails', ['ops@meducation.vn']);
        SystemSetting::set('ticket_notify_stale_lead', false);
        SystemSetting::set('ticket_notify_transaction', false);
        SystemSetting::set('ticket_notify_overdue_debt', false);
        SystemSetting::set('ticket_notify_homework', false);

        // 1. Stale lead
        CrmCustomer::create([
            'code' => 'CRM-LEAD-DIS',
            'name' => 'Khách Vô Danh',
            'phone' => '0999000111',
            'stage' => 'new',
            'created_at' => Carbon::now()->subHours(35),
        ]);
        app(NotificationService::class)->scanAndSyncStaleLeads();

        // 2. Receipt
        $student = Student::create([
            'code' => 'HV-DIS',
            'name' => 'Học Viên Dis',
            'phone' => '0999000222',
            'branch_id' => $this->branch->id,
            'status' => 'studying',
        ]);
        $tuition = StudentTuition::create([
            'student_id' => $student->id,
            'branch_id' => $this->branch->id,
            'total_amount' => 1000000,
            'debt_amount' => 1000000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);
        TuitionReceipt::create([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(),
            'student_tuition_id' => $tuition->id,
            'amount' => 1000000,
            'payment_method' => 'cash',
            'status' => 'approved',
        ]);

        // 3. Overdue reminder
        $this->actingAs($this->admin)->post(route('tuition.overdue.remind', $tuition->id));

        // Since all operational triggers are disabled, no emails should be sent
        Mail::shouldNotHaveReceived('html');
    }
}
