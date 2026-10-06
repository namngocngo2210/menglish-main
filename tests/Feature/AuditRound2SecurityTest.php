<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SepayConfiguration;
use App\Models\StaffReport;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Audit lần 2: cấu hình SMTP chỉ Admin, phạm vi sửa tài khoản, khóa SePay, nhật ký sự vụ, quên mật khẩu. */
class AuditRound2SecurityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-AU2', 'is_active' => true]);
        $this->admin = $this->userWithRole(Roles::ADMIN);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_manager_saves_ticket_recipients_but_cannot_change_smtp_server(): void
    {
        SystemSetting::set('mail_host', 'smtp.gmail.com');
        SystemSetting::set('mail_password', 'app-password');
        $manager = $this->userWithRole(Roles::MANAGER);

        $this->actingAs($manager)->get(route('system-config.ticket-emails'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManageMail', false));
        $this->actingAs($manager)->post(route('system-config.ticket-emails.update'), [
            'emails' => ['ops@meducation.vn'],
            'mail_host' => 'smtp.attacker.example', 'mail_username' => 'x@attacker.example',
        ])->assertRedirect();

        $this->assertSame(['ops@meducation.vn'], SystemSetting::getTicketEmails());
        $this->assertSame('smtp.gmail.com', SystemSetting::get('mail_host'));
    }

    public function test_admin_must_reenter_smtp_password_when_changing_server(): void
    {
        SystemSetting::set('mail_host', 'smtp.gmail.com');
        SystemSetting::set('mail_username', 'tech@meducation.vn');
        SystemSetting::set('mail_password', 'app-password');

        $this->actingAs($this->admin)->post(route('system-config.ticket-emails.update'), [
            'mail_host' => 'mail.meducation.vn', 'mail_username' => 'tech@meducation.vn',
        ])->assertSessionHasErrors('mail_password');
        $this->assertSame('smtp.gmail.com', SystemSetting::get('mail_host'));

        $this->actingAs($this->admin)->post(route('system-config.ticket-emails.update'), [
            'mail_host' => 'mail.meducation.vn', 'mail_username' => 'tech@meducation.vn', 'mail_password' => 'new-password',
        ])->assertSessionHasNoErrors();
        $this->assertSame('mail.meducation.vn', SystemSetting::get('mail_host'));
    }

    public function test_sepay_secret_is_never_sent_to_the_browser_and_blank_keeps_it(): void
    {
        config(['services.sepay.webhook_enabled' => true]);
        $config = SepayConfiguration::getActiveConfig();
        $config->update(['secret_key' => 'whsec_saved_secret']);

        $this->actingAs($this->admin)->get(route('system-config.bank-accounts'))->assertOk()
            ->assertDontSee('whsec_saved_secret')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sepay.has_secret', true)->missing('sepay.secret_key'));

        $this->actingAs($this->admin)->post(route('system-config.sepay.update'), [
            'webhook_name' => 'SePay', 'webhook_url' => 'https://portal.meducation.vn/hook/sepay-gateway/v1/add-payment',
            'transaction_type' => 'in', 'data_format' => 'json', 'auth_method' => 'hmac_sha256', 'secret_key' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame('whsec_saved_secret', $config->fresh()->secret_key);
    }

    public function test_branch_scope_staff_only_manage_accounts_in_their_branch_and_cannot_touch_salary(): void
    {
        $academic = $this->userWithRole(Roles::ACADEMIC_STAFF);
        $otherBranch = Branch::create(['name' => 'Đống Đa', 'code' => 'DD-AU2', 'is_active' => true]);
        $foreign = $this->userWithRole(Roles::TEACHER_PARTTIME, ['branch_id' => $otherBranch->id, 'hourly_rate' => 250000, 'id_card_number' => '001201004567']);
        // Giáo viên chi nhánh mình do Admin tạo: Học vụ vẫn quản lý được (phạm vi "Chi nhánh của tôi").
        $mine = $this->userWithRole(Roles::TEACHER_PARTTIME, ['created_by' => $this->admin->id, 'hourly_rate' => 250000]);
        $payload = fn (User $u) => ['name' => $u->name, 'email' => $u->email, 'branch_id' => $this->branch->id, 'role' => Roles::TEACHER_PARTTIME];

        $this->actingAs($academic)->put(route('users.update', $foreign->id), $payload($foreign) + ['password' => 'Taken-over-123'])->assertForbidden();

        $this->actingAs($academic)->get(route('users.edit', $mine->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canEditSensitive', false)->missing('user.hourly_rate'));
        $this->actingAs($academic)->put(route('users.update', $mine->id), $payload($mine) + ['hourly_rate' => 999999])->assertSessionHasNoErrors();
        $this->assertEquals(250000, $mine->fresh()->hourly_rate);
    }

    public function test_staff_cannot_follow_up_or_close_someone_elses_journal_entry(): void
    {
        $teacher = $this->userWithRole(Roles::TEACHER_PARTTIME);
        $other = $this->userWithRole(Roles::TEACHER_PARTTIME);
        $entry = StaffReport::create(['user_id' => $other->id, 'type' => 'journal', 'title' => 'Sự vụ', 'severity' => 'normal', 'report_date' => today(), 'status' => 'open']);

        $this->actingAs($teacher)->post(route('reports.journal.status', $entry->id), ['status' => 'resolved'])->assertNotFound();
        $this->actingAs($teacher)->post(route('reports.journal.followup', $entry->id), ['content' => 'x'])->assertNotFound();
        $this->assertSame('open', $entry->fresh()->status);

        $this->actingAs($other)->post(route('reports.journal.status', $entry->id), ['status' => 'resolved'])->assertRedirect();
        $this->assertSame('resolved', $entry->fresh()->status);
    }

    public function test_forgot_password_does_not_reveal_whether_an_email_has_an_account(): void
    {
        $known = $this->post('/forgot-password', ['email' => $this->admin->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        $unknown->assertSessionHasNoErrors();
        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
    }
}
