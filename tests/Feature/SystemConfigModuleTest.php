<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\DebtReminderRule;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemConfigModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_can_create_bank_account(): void
    {
        $response = $this->actingAs($this->admin)->post('/system-config/bank-accounts', [
            'bank_code' => 'MBB',
            'bank_name' => 'MB Bank (Quân Đội)',
            'account_number' => '8888 9999 1111',
            'account_holder' => 'CONG TY MENGLISH',
        ]);

        $response->assertRedirect(route('system-config.bank-accounts'));
        $this->assertDatabaseHas('bank_accounts', [
            'bank_code' => 'MBB',
            'account_number' => '8888 9999 1111',
        ]);
    }

    public function test_can_save_debt_reminder_rule(): void
    {
        $response = $this->actingAs($this->admin)->post('/system-config/debt-reminders', [
            'milestone_key' => 'T-5',
            'title' => 'Nhắc trước hạn 5 ngày',
            'template_content' => 'Chào {TEN_HOC_VIEN}, học phí lớp {TEN_LOP} sẽ đến hạn vào {HAN_NOP}.',
        ]);

        $response->assertRedirect(route('system-config.debt-reminders'));
        $this->assertDatabaseHas('debt_reminder_rules', [
            'milestone_key' => 'T-5',
        ]);
    }

    public function test_can_view_hosting_info_diagnostics(): void
    {
        $response = $this->actingAs($this->admin)->get(route('system-config.hosting'));
        $response->assertOk();
        $response->assertSee('Thông Số Hosting & Máy Chủ');
        $response->assertSee('PHP');
        $response->assertSee('Dung Lượng Lưu Trữ Website Đang Sử Dụng');
        $response->assertSee('Đã sử dụng:');
        $response->assertSee('Media Uploads');
        $response->assertDontSee('Tổng dung lượng:');
    }

    public function test_users_without_permission_cannot_access_system_config(): void
    {
        // Các route system-config từng bị bỏ trống middleware: user thường
        // (kể cả học viên) vào được trang tài khoản ngân hàng. Test này khoá
        // lại ràng buộc 403 cho người không đủ quyền.
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get(route('system-config.bank-accounts'))->assertForbidden();
        $this->actingAs($user)->post('/system-config/bank-accounts', [])->assertForbidden();
        $this->actingAs($user)->get(route('system-config.debt-reminders'))->assertForbidden();
        $this->actingAs($user)->post('/system-config/debt-reminders', [])->assertForbidden();
        $this->actingAs($user)->get(route('system-config.ticket-emails'))->assertForbidden();
        $this->actingAs($user)->get(route('system-config.hosting'))->assertForbidden();
    }
}
