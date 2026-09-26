<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\MerchandiseItem;
use App\Models\Student;
use App\Models\SystemCategory;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sprint IX-2 — 14 luồng "Modal nhỏ" (docs/frontend-interaction-redesign.md §3):
 * cùng route trả trang đầy đủ (request thường) hoặc fragment modal (HX-Request); lỗi validate 422 ngay trong modal;
 * lưu xong 204 + HX-Trigger (close-modal, toast, sự kiện làm mới danh sách); request thường giữ redirect như cũ.
 */
class ModalFlowsTest extends TestCase
{
    use RefreshDatabase;

    private const HX = ['HX-Request' => 'true'];

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-MF', 'is_active' => true]);
        $this->admin = User::factory()->create(['name' => 'Quản trị viên', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->admin->assignRole('admin');
    }

    // ── GET: trang đầy đủ ↔ fragment ─────────────────────────────────────────────────────────

    /** @return array<string, array{0: callable(self): string, 1: string, 2: string}> [url, id form trong modal, chữ có trong modal] */
    public static function formPages(): array
    {
        return [
            'danh mục – thêm' => [fn (self $t) => route('system-categories.create', ['type' => 'lead_source']), 'modal-category-form', 'Thêm danh mục mới'],
            'quyền – sửa' => [fn (self $t) => route('permissions.edit', Permission::firstOrCreate(['name' => 'report.export', 'guard_name' => 'web'])), 'modal-permission-form', 'Sửa permission'],
            'vai trò – thêm' => [fn (self $t) => route('roles.create'), 'modal-role-form', 'Thêm vai trò mới'],
            'vai trò – sửa' => [fn (self $t) => route('roles.edit', Role::findByName('teacher', 'web')), 'modal-role-form', 'Đổi tên vai trò'],
            'gán vai trò' => [fn (self $t) => route('users.roles.edit', $t->staff()), 'modal-user-roles-form', 'Gán vai trò'],
            'vật phẩm – thêm' => [fn (self $t) => route('merchandise.create'), 'modal-merchandise-form', 'Thêm mới Hàng hóa'],
            'vật phẩm – sửa' => [fn (self $t) => route('merchandise.edit', $t->item()), 'modal-merchandise-form', 'Cập nhật Hàng hóa'],
            'nhập khách Excel' => [fn (self $t) => route('crm.import'), 'modal-crm-import-form', 'Nhập khách hàng loạt từ Excel'],
            'nhập học phí Excel' => [fn (self $t) => route('tuition.import'), 'modal-tuition-import-form', 'Nhập học phí hàng loạt'],
        ];
    }

    #[DataProvider('formPages')]
    public function test_form_route_returns_full_page_normally_and_fragment_for_htmx(callable $url, string $formId, string $title): void
    {
        $url = $url($this);

        $this->actingAs($this->admin)->get($url)->assertOk()
            ->assertSee('data-sidebar', false)
            ->assertDontSee('id="'.$formId.'"', false);

        $this->actingAs($this->admin)->get($url, self::HX)->assertOk()
            ->assertHeader('Vary', 'HX-Request')
            ->assertDontSee('data-sidebar', false)
            ->assertDontSee('<html', false)
            ->assertSee($title)
            ->assertSee('id="'.$formId.'"', false)
            ->assertSee('form="'.$formId.'"', false);
    }

    /** @return array<string, array{0: string, 1: string, 2: callable(self): string, 3: string}> [route danh sách, sự kiện làm mới, URL mở modal, cỡ] */
    public static function listPages(): array
    {
        return [
            'danh mục' => ['system-categories.index', 'system-categories-changed', fn (self $t) => route('system-categories.create', ['type' => 'lead_source']), 'md'],
            'quyền' => ['permissions.index', 'permissions-changed', fn (self $t) => route('permissions.edit', Permission::findByName('aaa.export', 'web')), 'sm'],
            'vai trò' => ['roles.index', 'roles-changed', fn (self $t) => route('roles.create'), 'md'],
            'nhân sự' => ['users.index', 'users-changed', fn (self $t) => route('users.roles.edit', $t->staff()), 'md'],
            'vật phẩm' => ['merchandise.index', 'merchandise-changed', fn (self $t) => route('merchandise.create'), 'xl'],
        ];
    }

    #[DataProvider('listPages')]
    public function test_list_page_has_refresh_region_modal_triggers_and_no_native_confirm_for_delete(string $index, string $event, callable $opener, string $size): void
    {
        $this->item();
        $this->staff();
        Permission::firstOrCreate(['name' => 'aaa.export', 'guard_name' => 'web']); // đứng đầu trang 1 (sắp theo tên)

        $this->actingAs($this->admin)->get(route($index))->assertOk()
            ->assertSee('hx-trigger="'.$event.' from:body"', false)
            ->assertSee('hx-target="#remote-modal-body"', false)
            ->assertSee('data-modal-size="'.$size.'"', false)
            ->assertSee('hx-get="'.$opener($this).'"', false)
            ->assertDontSee('onsubmit="return confirm(\'Bạn có chắc', false)
            ->assertDontSee('onsubmit="return confirm(\'Xóa', false)
            ->assertDontSee('onsubmit="return confirm(\'Ngừng', false);
    }

    // ── Danh mục hệ thống ────────────────────────────────────────────────────────────────────

    public function test_system_category_modal_flow(): void
    {
        $category = SystemCategory::create(['type' => 'lead_source', 'code' => 'SRC_01', 'name' => 'Facebook Ads', 'sort_order' => 1, 'is_active' => true]);

        // Sửa: htmx → modal; mở thẳng URL → panel trên trang danh sách như cũ.
        $this->actingAs($this->admin)->get(route('system-categories.edit', $category), self::HX)->assertOk()
            ->assertSee('Sửa giá trị danh mục')->assertSee('value="Facebook Ads"', false)
            ->assertSee('action="'.route('system-categories.update', $category).'"', false);
        $this->actingAs($this->admin)->get(route('system-categories.edit', $category))
            ->assertRedirect(route('system-categories.index', ['type' => 'lead_source', 'edit' => $category->id]));

        // Trùng mã → 422 kèm lỗi, không lưu.
        $this->actingAs($this->admin)->post(route('system-categories.store'), ['type' => 'lead_source', 'code' => 'SRC_01', 'name' => 'Trùng'], self::HX)
            ->assertStatus(422)->assertDontSee('data-sidebar', false)
            ->assertSee('id="modal-category-form"', false)->assertSee('value="Trùng"', false)->assertSee('role="alert"', false);
        $this->assertSame(1, SystemCategory::count());

        $response = $this->actingAs($this->admin)->post(route('system-categories.store'), ['type' => 'lead_source', 'code' => 'SRC_02', 'name' => 'Google', 'is_active' => 1], self::HX);
        $this->assertSaved($response, 'system-categories-changed', 'Đã thêm danh mục "Google".');

        $response = $this->actingAs($this->admin)->put(route('system-categories.update', $category), ['type' => 'lead_source', 'code' => 'SRC_01', 'name' => 'Facebook', 'is_active' => 1], self::HX);
        $this->assertSaved($response, 'system-categories-changed', 'Đã cập nhật danh mục.');
        $this->assertSame('Facebook', $category->fresh()->name);

        $response = $this->actingAs($this->admin)->delete(route('system-categories.destroy', $category), [], self::HX);
        $this->assertSaved($response, 'system-categories-changed', 'Đã ngừng sử dụng "Facebook".');
        $this->assertFalse($category->fresh()->is_active);

        // Request thường giữ nguyên redirect.
        $this->actingAs($this->admin)->post(route('system-categories.store'), ['type' => 'lead_source', 'code' => 'SRC_03', 'name' => 'Zalo', 'is_active' => 1])
            ->assertRedirect(route('system-categories.index', ['type' => 'lead_source']))->assertSessionHas('status');
    }

    // ── Quyền ───────────────────────────────────────────────────────────────────────────────

    public function test_permission_modal_flow(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'report.export', 'guard_name' => 'web']);

        $this->actingAs($this->admin)->put(route('permissions.update', $permission), ['name' => 'Sai Định Dạng'], self::HX)
            ->assertStatus(422)->assertSee('id="modal-permission-form"', false)
            ->assertSee('Tên permission phải theo định dạng');

        $response = $this->actingAs($this->admin)->put(route('permissions.update', $permission), ['name' => 'report.export_all'], self::HX);
        $this->assertSaved($response, 'permissions-changed', 'Đã cập nhật permission.');
        $this->assertSame('report.export_all', $permission->fresh()->name);

        // Đang gán cho vai trò → không xóa, đóng modal + toast lỗi.
        Role::findByName('teacher', 'web')->givePermissionTo($permission->fresh());
        $response = $this->actingAs($this->admin)->delete(route('permissions.destroy', $permission), [], self::HX);
        $response->assertNoContent();
        $triggers = $this->triggers($response);
        $this->assertSame('error', $triggers['toast']['type']);
        $this->assertTrue($triggers['close-modal']);
        $this->assertDatabaseHas('permissions', ['id' => $permission->id]);

        $unused = Permission::firstOrCreate(['name' => 'test.unused_action', 'guard_name' => 'web']);
        $this->assertSaved($this->actingAs($this->admin)->delete(route('permissions.destroy', $unused), [], self::HX), 'permissions-changed', 'Đã xóa permission.');

        // Thêm permission vẫn chỉ dành cho DEV (redirect như cũ).
        $this->actingAs($this->admin)->get(route('permissions.create'), self::HX)->assertRedirect(route('permissions.index'));
    }

    // ── Vai trò ─────────────────────────────────────────────────────────────────────────────

    public function test_role_modal_only_edits_basic_fields_and_matrix_stays_on_full_page(): void
    {
        $this->actingAs($this->admin)->get(route('roles.create'))->assertOk()->assertSee('data-testid="role-matrix"', false);
        $this->actingAs($this->admin)->get(route('roles.create'), self::HX)->assertOk()->assertDontSee('data-testid="role-matrix"', false);

        $this->actingAs($this->admin)->post(route('roles.store'), ['name' => 'teacher'], self::HX)
            ->assertStatus(422)->assertSee('id="modal-role-form"', false)->assertSee('role="alert"', false);

        $response = $this->actingAs($this->admin)->post(route('roles.store'), ['name' => 'thu_ngan', 'label' => 'Thu ngân'], self::HX);
        $this->assertSaved($response, 'roles-changed', 'Đã tạo vai trò thành công.');
        $role = Role::findByName('thu_ngan', 'web');
        $role->givePermissionTo('lead.view');

        // Đổi tên trong modal không gửi ma trận → giữ nguyên quyền.
        $response = $this->actingAs($this->admin)->put(route('roles.update', $role), ['name' => 'thu_ngan', 'label' => 'Thu ngân CS1'], self::HX);
        $this->assertSaved($response, 'roles-changed', 'Đã cập nhật vai trò.');
        $this->assertSame('Thu ngân CS1', $role->fresh()->label);
        $this->assertTrue($role->fresh()->hasPermissionTo('lead.view'));

        // Vai trò đang gán cho nhân sự → không xóa (toast lỗi); chưa gán → xóa.
        $this->staff();
        $response = $this->actingAs($this->admin)->delete(route('roles.destroy', Role::findByName('teacher', 'web')), [], self::HX);
        $this->assertSame('error', $this->triggers($response)['toast']['type']);
        $this->assertTrue(Role::where('name', 'teacher')->exists());
        $this->assertSaved($this->actingAs($this->admin)->delete(route('roles.destroy', $role), [], self::HX), 'roles-changed', 'Đã xóa vai trò.');

        // Request thường giữ redirect + lỗi session.
        $this->actingAs($this->admin)->delete(route('roles.destroy', Role::findByName('admin', 'web')))->assertSessionHasErrors('role');
    }

    // ── Gán vai trò nhân sự ─────────────────────────────────────────────────────────────────

    public function test_user_roles_modal_flow(): void
    {
        $staff = $this->staff();

        $this->actingAs($this->admin)->put(route('users.roles.update', $staff), ['roles' => []], self::HX)
            ->assertStatus(422)->assertSee('id="modal-user-roles-form"', false)->assertSee('role="alert"', false);
        $this->assertTrue($staff->fresh()->hasRole('teacher'));

        $response = $this->actingAs($this->admin)->put(route('users.roles.update', $staff), ['roles' => ['teacher', 'assistant']], self::HX);
        $this->assertSaved($response, 'users-changed', 'Đã cập nhật vai trò.');
        $this->assertTrue($staff->fresh()->hasRole('assistant'));

        $this->actingAs($this->admin)->put(route('users.roles.update', $staff), ['roles' => ['teacher']])
            ->assertRedirect(route('users.index'))->assertSessionHas('status', 'Đã cập nhật vai trò.');
    }

    // ── Vật phẩm ────────────────────────────────────────────────────────────────────────────

    public function test_merchandise_modal_flow(): void
    {
        $item = $this->item();

        $this->actingAs($this->admin)->post(route('merchandise.store'), ['code' => 'BOOK-MF-01', 'name' => ''], self::HX)
            ->assertStatus(422)->assertSee('id="modal-merchandise-form"', false)
            ->assertSee('Mã hàng hóa này đã tồn tại trong hệ thống.')->assertSee('Tên hàng hóa không được để trống.');

        $payload = ['code' => 'UNI-MF-XL', 'name' => 'Áo Polo XL', 'category' => 'uniform', 'unit' => 'Chiếc', 'price' => 220000, 'stock_quantity' => 30, 'is_active' => 1];
        $this->assertSaved($this->actingAs($this->admin)->post(route('merchandise.store'), $payload, self::HX), 'merchandise-changed', 'Đã thêm thành công mặt hàng [UNI-MF-XL] Áo Polo XL!');

        $response = $this->actingAs($this->admin)->put(route('merchandise.update', $item), [...$payload, 'code' => 'BOOK-MF-01', 'name' => 'Sách mới'], self::HX);
        $this->assertSaved($response, 'merchandise-changed', 'Đã cập nhật thông tin mặt hàng [BOOK-MF-01] Sách mới!');

        $this->assertSaved($this->actingAs($this->admin)->delete(route('merchandise.destroy', $item), [], self::HX), 'merchandise-changed', 'Đã xóa mặt hàng Sách mới vào thùng rác.');
        $this->assertSoftDeleted($item);
    }

    // ── Nhập khách từ Excel (2 bước trong modal) ─────────────────────────────────────────────

    public function test_crm_import_runs_both_steps_inside_modal(): void
    {
        $this->actingAs($this->admin)->get(route('crm.import'), self::HX)->assertOk()
            ->assertSee('href="'.route('crm.import.template').'"', false)->assertSee('hx-boost="false"', false);

        // Thiếu file / chi nhánh → 422, form bước 1 kèm lỗi (route crm.import.preview → màn cha crm.import).
        $this->actingAs($this->admin)->post(route('crm.import.preview'), ['default_source' => 'Hội thảo'], self::HX)
            ->assertStatus(422)->assertDontSee('data-sidebar', false)
            ->assertSee('id="modal-crm-import-form"', false)->assertSee('Vui lòng chọn file Excel / CSV.')->assertSee('value="Hội thảo"', false);

        $file = UploadedFile::fake()->createWithContent('khach.csv', "\xEF\xBB\xBFHọ tên,Số điện thoại\nNguyễn An,0912345678\nTrần Bình,12345\n");
        $this->actingAs($this->admin)->post(route('crm.import.preview'), ['file' => $file, 'branch_id' => $this->branch->id], self::HX)
            ->assertRedirect(route('crm.import'));

        // Trình duyệt đi theo redirect (vẫn gửi HX-Request) → bước 2 trong modal, nới rộng 4xl.
        $this->actingAs($this->admin)->get(route('crm.import'), self::HX)->assertOk()
            ->assertDontSee('data-sidebar', false)
            ->assertSee('Xem trước dữ liệu nhập')->assertSee('SĐT sai định dạng')
            ->assertSee("size = '4xl'", false)
            ->assertSee('form="modal-crm-import-confirm"', false)->assertSee('Nhập 1 khách hợp lệ');

        $response = $this->actingAs($this->admin)->post(route('crm.import.store'), [], self::HX);
        $response->assertNoContent()->assertHeader('HX-Redirect', route('crm.customers.index'));
        $this->assertSame(1, CrmCustomer::count());
        $this->assertStringStartsWith('Đã nhập 1 khách hàng mới', session('status'));
    }

    // ── Nhập học phí từ Excel (3 bước trong modal) ───────────────────────────────────────────

    public function test_tuition_import_runs_steps_inside_modal(): void
    {
        $this->actingAs($this->admin)->post(route('tuition.import.store'), ['branch_id' => $this->branch->id], self::HX)
            ->assertStatus(422)->assertDontSee('data-sidebar', false)
            ->assertSee('id="modal-tuition-import-form"', false)->assertSee('Vui lòng chọn file Excel (.xlsx) hoặc CSV để nhập.');

        $file = UploadedFile::fake()->createWithContent('hoc-phi.csv', "Mã học viên,Học phí niêm yết,Hạn đóng\nHV-KHONG-CO,3000000,15/10/2026\n");
        $response = $this->actingAs($this->admin)->post(route('tuition.import.store'), ['branch_id' => $this->branch->id, 'excel_file' => $file], self::HX);
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('token=', $location);

        $this->actingAs($this->admin)->get($location, self::HX)->assertOk()
            ->assertDontSee('data-sidebar', false)
            ->assertSee('Không tìm thấy học viên mã HV-KHONG-CO')
            ->assertSee('form="modal-tuition-import-confirm"', false)
            ->assertSee("size = '4xl'", false);

        // Hết hạn phiên xem trước → redirect về bước 1 kèm lỗi (trình duyệt đi theo, lỗi hiện trong modal).
        $this->actingAs($this->admin)->post(route('tuition.import.confirm'), ['token' => 'khong-ton-tai'], self::HX)
            ->assertRedirect(route('tuition.import'));
        $this->actingAs($this->admin)->get(route('tuition.import'), self::HX)->assertOk()
            ->assertSee('Phiên xem trước đã hết hạn');
    }

    // ── Ảnh bằng chứng hoàn tiền (lightbox) ─────────────────────────────────────────────────

    public function test_refund_proof_opens_as_lightbox_for_htmx_and_file_otherwise(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('tuition/refund-proofs/unc.png', UploadedFile::fake()->image('unc.png')->getContent());
        $student = Student::create(['code' => 'HV-MF-1', 'name' => 'Lê Hoàn', 'phone' => '0912000111', 'branch_id' => $this->branch->id, 'status' => 'studying']);
        $refund = TuitionRefundRequest::create([
            'student_id' => $student->id, 'type' => 'refund', 'total_paid' => 3000000, 'refund_amount' => 1000000,
            'reason' => 'Chuyển nhà', 'requester_id' => $this->admin->id, 'status' => 'approved', 'proof_path' => 'tuition/refund-proofs/unc.png',
        ]);

        $this->actingAs($this->admin)->get(route('tuition.refunds'))->assertOk()
            ->assertSee('hx-get="'.route('tuition.refunds.proof', $refund->id).'"', false)->assertSee('data-modal-size="xl"', false);

        $this->actingAs($this->admin)->get(route('tuition.refunds.proof', $refund->id), self::HX)->assertOk()
            ->assertDontSee('data-sidebar', false)
            ->assertSee('Ảnh bằng chứng — Lê Hoàn')
            ->assertSee('<img src="'.route('tuition.refunds.proof', $refund->id).'"', false);

        $file = $this->actingAs($this->admin)->get(route('tuition.refunds.proof', $refund->id))->assertOk();
        $this->assertStringStartsWith('image/', $file->headers->get('Content-Type'));
    }

    // ── Phân quyền ──────────────────────────────────────────────────────────────────────────

    public function test_htmx_requests_still_respect_permissions(): void
    {
        $teacher = $this->staff();
        $item = $this->item();

        $this->actingAs($teacher)->get(route('system-categories.create'), self::HX)->assertForbidden();
        $this->actingAs($teacher)->post(route('system-categories.store'), ['name' => ''], self::HX)->assertForbidden();
        $this->actingAs($teacher)->get(route('merchandise.edit', $item), self::HX)->assertForbidden();
        $this->actingAs($teacher)->get(route('roles.create'), self::HX)->assertForbidden();
        $this->actingAs($teacher)->put(route('users.roles.update', $this->admin), ['roles' => []], self::HX)->assertForbidden();
        $this->actingAs($teacher)->post(route('crm.import.preview'), [], self::HX)->assertForbidden();
        $this->actingAs($teacher)->post(route('tuition.import.store'), [], self::HX)->assertForbidden();
    }

    public function test_validation_render_requires_submit_route_to_cover_form_route_middleware(): void
    {
        // tuition.import (GET, chỉ tuition.view) ⊂ tuition.import.store (thêm tuition.create) → render form 422 được.
        // Người chỉ có tuition.view bị chặn ngay ở route submit (403), không bao giờ tới bước render form.
        $viewer = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $viewer->givePermissionTo('tuition.view');

        $this->actingAs($viewer)->post(route('tuition.import.store'), [], self::HX)->assertForbidden();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────

    public function staff(): User
    {
        $user = User::query()->where('email', 'gv.modal@example.com')->first()
            ?? User::factory()->create(['name' => 'Giáo viên Modal', 'email' => 'gv.modal@example.com', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $user->syncRoles(['teacher']);

        return $user;
    }

    public function item(): MerchandiseItem
    {
        return MerchandiseItem::query()->firstOrCreate(['code' => 'BOOK-MF-01'], [
            'name' => 'Sách Test', 'category' => MerchandiseItem::CATEGORY_BOOK, 'unit' => 'Cuốn', 'price' => 200000, 'stock_quantity' => 50, 'is_active' => true,
        ]);
    }

    private function assertSaved(TestResponse $response, string $event, string $message): void
    {
        $response->assertNoContent();
        $triggers = $this->triggers($response);
        $this->assertTrue($triggers['close-modal']);
        $this->assertTrue($triggers[$event]);
        $this->assertSame(['message' => $message, 'type' => 'success'], $triggers['toast']);
    }

    private function triggers(TestResponse $response): array
    {
        return json_decode($response->headers->get('HX-Trigger'), true, flags: JSON_THROW_ON_ERROR);
    }
}
