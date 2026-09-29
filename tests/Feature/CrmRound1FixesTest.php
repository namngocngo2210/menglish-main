<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kiểm thử đợt 1 (CRM: pipeline, danh sách, hồ sơ khách, nhập Excel, báo cáo) — các lỗi tìm thấy khi rà soát.
 */
class CrmRound1FixesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    private User $manager;

    private User $sales;

    private User $otherSales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Cơ sở B', 'code' => 'CSB', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin', $this->branch);
        $this->manager = $this->userWithRole('manager', $this->branch);
        $this->sales = $this->userWithRole('sales_consultant', $this->branch, 'Sale Một');
        $this->otherSales = $this->userWithRole('sales_consultant', $this->otherBranch, 'Sale Hai');
    }

    // ── Nhập Excel ─────────────────────────────────────────────────────────

    public function test_import_reports_real_excel_line_numbers_and_strict_dates(): void
    {
        $csv = implode("\n", [
            'Họ tên,Số điện thoại,Ngày sinh,Giới tính,SĐT phụ huynh',
            ',,,,',                                           // dòng 2 trống
            'Thiếu Tên Không,,15/03/2012,,',                  // dòng 3: thiếu SĐT
            ',0912000001,,,',                                 // dòng 4: thiếu tên — không được "giữ chỗ" SĐT
            'An Hợp Lệ,0912000001,31/12/2012,Nam,',           // dòng 5: hợp lệ
            'Ngày Sai,0912000002,31/02/2012,,',               // dòng 6: ngày không tồn tại
            'Chỉ Năm,0912000003,2015,,',                      // dòng 7: chỉ có năm
            'Máy Bàn,2439998888,2012-05-01,Nữ,',              // dòng 8: máy bàn mất số 0 đầu
            'Giới Tính Dài,0912000004,,Nam (chưa xác nhận lại lần nữa),',
        ]);

        $this->actingAs($this->manager)->post(route('crm.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('khach.csv', "\xEF\xBB\xBF".$csv), 'branch_id' => $this->branch->id,
        ])->assertSessionHasNoErrors();

        $rows = collect(session('crm_customer_import')['rows'])->keyBy('line');
        $this->assertSame([3, 4, 5, 6, 7, 8, 9], $rows->keys()->all());
        $this->assertSame(['Thiếu số điện thoại'], $rows[3]['errors']);
        $this->assertSame(['Thiếu họ tên'], $rows[4]['errors']);
        $this->assertSame([], $rows[5]['errors']);
        $this->assertSame('2012-12-31', $rows[5]['data']['dob']);
        $this->assertStringContainsString('Ngày sinh không hợp lệ (31/02/2012)', implode(' ', $rows[6]['errors']));
        $this->assertStringContainsString('Ngày sinh không hợp lệ (2015)', implode(' ', $rows[7]['errors']));
        $this->assertSame([], $rows[8]['errors']);
        $this->assertSame('02439998888', $rows[8]['data']['phone']);
        $this->assertStringContainsString('Giới tính quá dài', implode(' ', $rows[9]['errors']));

        $this->actingAs($this->manager)->post(route('crm.import.store'))->assertRedirect(route('crm.customers.index'));
        $this->assertEqualsCanonicalizing(['An Hợp Lệ', 'Máy Bàn'], CrmCustomer::pluck('name')->all());
    }

    public function test_import_store_rechecks_that_assigned_sales_is_still_active(): void
    {
        $csv = "Họ tên,Số điện thoại\nNguyễn Nhập,0912 345 111";
        $this->actingAs($this->manager)->post(route('crm.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('khach.csv', $csv), 'branch_id' => $this->branch->id, 'assigned_user_id' => $this->sales->id,
        ])->assertSessionHasNoErrors();

        $this->sales->update(['is_active' => false]);
        $this->actingAs($this->manager)->post(route('crm.import.store'))->assertSessionHasErrors('assigned_user_id');
        $this->assertSame(0, CrmCustomer::count());
    }

    // ── Hồ sơ khách / danh sách ────────────────────────────────────────────

    public function test_clearing_deal_value_on_edit_saves_zero_instead_of_crashing(): void
    {
        $lead = $this->lead('consulting', ['deal_value' => 5000000]);

        $this->actingAs($this->manager)->put(route('crm.customers.update', $lead), [
            'name' => $lead->name, 'phone' => $lead->phone, 'source' => 'Facebook Ads', 'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->sales->id, 'deal_value' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame('0.00', (string) $lead->fresh()->deal_value);
    }

    public function test_empty_assignee_on_edit_keeps_current_sales(): void
    {
        $lead = $this->lead('consulting');

        $this->actingAs($this->manager)->put(route('crm.customers.update', $lead), [
            'name' => $lead->name, 'phone' => $lead->phone, 'source' => 'Facebook Ads', 'branch_id' => $this->branch->id, 'assigned_user_id' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->sales->id, $lead->fresh()->assigned_user_id);
    }

    public function test_branch_manager_cannot_hand_customer_to_sales_of_another_branch(): void
    {
        $lead = $this->lead('consulting');

        $this->actingAs($this->manager)->post(route('crm.customers.reassign', $lead), [
            'assigned_user_id' => $this->otherSales->id, 'reason' => 'Chuyển',
        ])->assertSessionHasErrors('assigned_user_id');
        $this->actingAs($this->manager)->put(route('crm.customers.update', $lead), [
            'name' => $lead->name, 'phone' => $lead->phone, 'source' => 'Facebook Ads', 'branch_id' => $this->branch->id, 'assigned_user_id' => $this->otherSales->id,
        ])->assertSessionHasErrors('assigned_user_id');
        $this->actingAs($this->manager)->post(route('crm.customers.store'), [
            'name' => 'Khách mới', 'phone' => '0912 777 888', 'source' => 'Facebook Ads', 'branch_id' => $this->branch->id, 'assigned_user_id' => $this->otherSales->id,
        ])->assertSessionHasErrors('assigned_user_id');

        $this->assertSame($this->sales->id, $lead->fresh()->assigned_user_id);
        $this->actingAs($this->otherSales)->get(route('crm.customers.show', $lead))->assertNotFound();
    }

    public function test_reassign_to_deleted_user_is_a_validation_error(): void
    {
        $lead = $this->lead('consulting');
        $gone = $this->userWithRole('sales_consultant', $this->branch);
        $gone->delete();

        $this->actingAs($this->manager)->post(route('crm.customers.reassign', $lead), [
            'assigned_user_id' => $gone->id, 'reason' => 'Chuyển',
        ])->assertSessionHasErrors('assigned_user_id');
    }

    public function test_lost_reason_and_address_are_limited_to_column_length(): void
    {
        $lead = $this->lead('consulting');

        $this->actingAs($this->sales)->post(route('crm.customers.stage', $lead), ['stage' => 'lost', 'lost_reason' => str_repeat('a', 256)])
            ->assertSessionHasErrors('lost_reason');
        $this->actingAs($this->sales)->post(route('crm.customers.store'), [
            'name' => 'Địa chỉ dài', 'phone' => '0912 777 999', 'source' => 'Facebook Ads', 'branch_id' => $this->branch->id, 'address' => str_repeat('a', 256),
        ])->assertSessionHasErrors('address');
    }

    public function test_search_finds_phone_typed_without_spaces_including_parent_phone_and_seeded_rows(): void
    {
        // Khách tạo ngoài form (seeder / script) vẫn có SĐT chuẩn hoá nhờ model.
        $seeded = CrmCustomer::create([
            'code' => CrmCustomer::generateCode(), 'name' => 'Khách Seed', 'phone' => '0987 111 222',
            'branch_id' => $this->branch->id, 'assigned_user_id' => $this->sales->id, 'source' => 'Facebook Ads', 'stage' => 'new',
        ]);
        $this->assertSame('0987111222', $seeded->phone_normalized);
        $withParent = $this->lead('new', ['name' => 'Có Phụ Huynh', 'parent_phone' => '0903 456 789']);

        $this->actingAs($this->admin)->get(route('crm.customers.index', ['search' => '0987111222']))->assertOk()->assertSee('Khách Seed');
        $this->actingAs($this->admin)->get(route('crm.customers.index', ['search' => '0903456789']))->assertOk()->assertSee($withParent->name);
    }

    public function test_landline_with_country_code_is_accepted(): void
    {
        $this->assertSame('02439998888', CrmCustomer::normalizePhone('+84 24 3999 8888'));
        $this->assertTrue(CrmCustomer::isValidVietnamesePhone('+84 24 3999 8888'));
    }

    public function test_sales_can_open_mark_lost_from_pipeline(): void
    {
        $this->lead('consulting');

        $response = $this->actingAs($this->sales)->get(route('crm.pipeline'))->assertOk();
        $this->assertTrue($response->viewData('stagePermissions')['canMarkLost']);
        $response->assertSee('Sửa giai đoạn')->assertSee('Chuyển sang Thất bại');
    }

    // ── Báo cáo ───────────────────────────────────────────────────────────

    public function test_report_periods_do_not_overlap_and_last_7_days_is_seven_days(): void
    {
        Carbon::setTestNow('2026-09-29 10:00:00');
        $response = $this->actingAs($this->admin)->get(route('crm.reports', ['preset' => 'last_7_days']))->assertOk();
        $this->assertSame('2026-09-23 00:00:00', $response->viewData('startDate')->format('Y-m-d H:i:s'));

        $custom = $this->actingAs($this->admin)->get(route('crm.reports', ['preset' => 'custom', 'start_date' => '2026-09-20', 'end_date' => '2026-09-10']))->assertOk();
        $this->assertSame('2026-09-10', $custom->viewData('startDate')->toDateString());
        $this->assertSame('2026-09-20', $custom->viewData('endDate')->toDateString());
        Carbon::setTestNow();
    }

    public function test_report_credits_closed_deals_to_commission_owner_after_reassign(): void
    {
        $lead = $this->lead('won', ['commission_user_id' => $this->sales->id, 'converted_at' => now()]);
        $colleague = $this->userWithRole('sales_consultant', $this->branch, 'Sale Ba');
        $lead->update(['assigned_user_id' => $colleague->id]);

        $this->sales->givePermissionTo('report.view');
        $response = $this->actingAs($this->sales)->get(route('crm.reports'))->assertOk();
        $mine = collect($response->viewData('repsData'))->firstWhere('name', 'Sale Một');
        $this->assertSame(1, $mine['won']);
    }

    public function test_enrollment_report_is_admin_only_and_hidden_from_other_menus(): void
    {
        $accountant = $this->userWithRole('accountant', $this->branch);
        foreach ([$this->manager, $this->sales, $accountant] as $user) {
            $this->actingAs($user)->get(route('crm.reports'))->assertForbidden();
            $this->actingAs($user)->get(route('dashboard'))->assertDontSee(route('crm.reports'), false);
        }
        $this->actingAs($this->admin)->get(route('crm.reports'))->assertOk();
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('crm.reports'), false);
    }

    public function test_migration_revokes_report_view_from_non_admin_roles(): void
    {
        $role = Role::findByName('manager', 'web');
        $role->givePermissionTo('report.view');

        (require database_path('migrations/2026_10_18_090000_restrict_crm_report_to_admin.php'))->up();

        $this->assertFalse($role->fresh()->hasPermissionTo('report.view'));
        $this->actingAs($this->admin)->get(route('crm.reports'))->assertOk();
    }

    private function lead(string $stage, array $attributes = []): CrmCustomer
    {
        $phone = '09'.random_int(10000000, 99999999);

        return CrmCustomer::create(array_merge([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Lead '.$stage.' '.random_int(100, 999),
            'phone' => $phone,
            'phone_normalized' => $phone,
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->sales->id,
            'source' => 'Facebook Ads',
            'stage' => $stage,
        ], $attributes));
    }

    private function userWithRole(string $role, Branch $branch, ?string $name = null): User
    {
        $user = User::factory()->create(array_filter(['branch_id' => $branch->id, 'is_active' => true, 'name' => $name]));
        $user->assignRole($role);

        return $user;
    }
}
