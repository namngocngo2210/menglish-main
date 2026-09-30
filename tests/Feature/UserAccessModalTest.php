<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SystemCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * Modal của nhóm Người dùng, phân quyền và cấu hình (Inertia) — thay phần htmx của ModalFlowsTest / LargeModalFlowsTest:
 * cùng route trả trang đầy đủ (mở thẳng URL) hoặc nội dung modal (header X-Remote-Modal → prop asModal);
 * lưu từ modal → quay lại trang đang mở kèm thông báo; lỗi validate / nghiệp vụ → quay lại kèm lỗi, không lưu.
 */
class UserAccessModalTest extends TestCase
{
    use InteractsWithInertia;
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-UA', 'is_active' => true]);
        $this->admin = User::factory()->create(['name' => 'Quản trị viên', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->admin->assignRole('admin');
    }

    // ── GET: trang đầy đủ ↔ modal ────────────────────────────────────────────────────────────

    /** @return array<string, array{0: callable(self): string, 1: string}> [url, component trang] */
    public static function formPages(): array
    {
        return [
            'danh mục – thêm' => [fn (self $t) => route('system-categories.create', ['type' => 'lead_source']), 'SystemCategories/Form'],
            'quyền – sửa' => [fn (self $t) => route('permissions.edit', Permission::firstOrCreate(['name' => 'report.export', 'guard_name' => 'web'])), 'Permissions/Form'],
            'vai trò – thêm' => [fn (self $t) => route('roles.create'), 'Roles/Form'],
            'vai trò – sửa' => [fn (self $t) => route('roles.edit', Role::findByName('teacher', 'web')), 'Roles/Form'],
            'gán vai trò' => [fn (self $t) => route('users.roles.edit', $t->staff()), 'Users/Roles'],
            'nhân sự – thêm' => [fn (self $t) => route('users.create'), 'Users/Form'],
            'nhân sự – sửa' => [fn (self $t) => route('users.edit', $t->staff()), 'Users/Form'],
            'phân quyền cá nhân' => [fn (self $t) => route('users.permissions.edit', $t->staff()), 'Users/Permissions'],
        ];
    }

    #[DataProvider('formPages')]
    public function test_form_route_returns_full_page_normally_and_modal_content_from_modal(callable $url, string $component): void
    {
        $url = $url($this);

        $this->actingAs($this->admin)->get($url)->assertOk()
            ->assertSee('data-sidebar', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->component($component)->where('asModal', false));

        $this->actingAs($this->admin)->get($url, self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component($component)->where('asModal', true));
    }

    public function test_list_pages_open_forms_in_modal_and_confirm_deletes_without_native_dialog(): void
    {
        $staff = $this->staff();
        $permission = Permission::firstOrCreate(['name' => 'aaa.export', 'guard_name' => 'web']); // đứng đầu trang 1 (sắp theo tên)
        $category = SystemCategory::create(['type' => 'lead_source', 'code' => 'SRC_01', 'name' => 'Facebook Ads', 'sort_order' => 1, 'is_active' => true]);

        $pages = [
            route('system-categories.index', ['type' => 'lead_source']) => [
                route('system-categories.create', ['type' => 'lead_source'], false), route('system-categories.edit', $category, false),
            ],
            route('permissions.index') => [route('permissions.edit', $permission, false)],
            route('roles.index') => [route('roles.create', absolute: false), route('roles.edit', Role::findByName('teacher', 'web'), false)],
            route('users.index') => [
                route('users.create', absolute: false), route('users.edit', $staff, false),
                route('users.roles.edit', $staff, false), route('users.permissions.edit', $staff, false),
            ],
        ];

        foreach ($pages as $page => $openers) {
            $response = $this->actingAs($this->admin)->get($page)->assertOk()
                ->assertDontSee('onsubmit="return confirm(', false)
                ->assertDontSee('hx-get=', false);
            foreach ($openers as $opener) {
                $response->assertSee('href="'.$opener.'"', false);
            }
        }
    }

    // ── Danh mục hệ thống ────────────────────────────────────────────────────────────────────

    public function test_system_category_modal_flow(): void
    {
        $category = SystemCategory::create(['type' => 'lead_source', 'code' => 'SRC_01', 'name' => 'Facebook Ads', 'sort_order' => 1, 'is_active' => true]);
        $list = route('system-categories.index', ['type' => 'lead_source']);

        // Sửa: từ modal → nội dung modal; mở thẳng URL → danh sách mở sẵn modal sửa (?edit=).
        $this->actingAs($this->admin)->get(route('system-categories.edit', $category), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('SystemCategories/Form')
                ->where('asModal', true)->where('category.id', $category->id)->where('category.name', 'Facebook Ads'));
        $this->actingAs($this->admin)->get(route('system-categories.edit', $category))
            ->assertRedirect(route('system-categories.index', ['type' => 'lead_source', 'edit' => $category->id]));
        $this->actingAs($this->admin)->get(route('system-categories.index', ['type' => 'lead_source', 'edit' => $category->id]))->assertOk()
            ->assertSee('Sửa giá trị danh mục')->assertSee('id="category-form"', false)->assertSee('value="Facebook Ads"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('editing.id', $category->id));
        $this->actingAs($this->admin)->get($list)->assertOk()
            ->assertDontSee('id="category-form"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('editing', null));

        // Trùng mã → quay lại kèm lỗi, không lưu.
        $this->actingAs($this->admin)->from($list)
            ->post(route('system-categories.store'), ['type' => 'lead_source', 'code' => 'SRC_01', 'name' => 'Trùng'], self::MODAL)
            ->assertRedirect($list)->assertSessionHasErrors('code');
        $this->assertSame(1, SystemCategory::count());

        $this->actingAs($this->admin)->from($list)
            ->post(route('system-categories.store'), ['type' => 'lead_source', 'code' => 'SRC_02', 'name' => 'Google', 'is_active' => 1], self::MODAL)
            ->assertRedirect($list)->assertSessionHas('status', 'Đã thêm danh mục "Google".');

        $this->actingAs($this->admin)->from($list)
            ->put(route('system-categories.update', $category), ['type' => 'lead_source', 'code' => 'SRC_01', 'name' => 'Facebook', 'is_active' => 1], self::MODAL)
            ->assertRedirect($list)->assertSessionHas('status', 'Đã cập nhật danh mục.');
        $this->assertSame('Facebook', $category->fresh()->name);

        $this->actingAs($this->admin)->from($list)
            ->delete(route('system-categories.destroy', $category), [], self::MODAL)
            ->assertRedirect($list)->assertSessionHas('status', 'Đã ngừng sử dụng "Facebook".');
        $this->assertFalse($category->fresh()->is_active);

        // Trang đầy đủ giữ nguyên redirect về danh sách.
        $this->actingAs($this->admin)->post(route('system-categories.store'), ['type' => 'lead_source', 'code' => 'SRC_03', 'name' => 'Zalo', 'is_active' => 1])
            ->assertRedirect($list)->assertSessionHas('status');
    }

    // ── Quyền ───────────────────────────────────────────────────────────────────────────────

    public function test_permission_modal_flow(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'report.export', 'guard_name' => 'web']);
        $list = route('permissions.index');

        $this->actingAs($this->admin)->from($list)->put(route('permissions.update', $permission), ['name' => 'Sai Định Dạng'], self::MODAL)
            ->assertRedirect($list)
            ->assertSessionHasErrors(['name' => 'Tên permission phải theo định dạng "module.action" (chữ thường, gạch dưới).']);
        $this->assertSame('report.export', $permission->fresh()->name);

        $this->actingAs($this->admin)->from($list)->put(route('permissions.update', $permission), ['name' => 'report.export_all'], self::MODAL)
            ->assertRedirect($list)->assertSessionHas('status', 'Đã cập nhật permission.');
        $this->assertSame('report.export_all', $permission->fresh()->name);

        // Đang gán cho vai trò → không xóa, quay lại kèm thông báo lỗi.
        Role::findByName('teacher', 'web')->givePermissionTo($permission->fresh());
        $this->actingAs($this->admin)->from($list)->delete(route('permissions.destroy', $permission), [], self::MODAL)
            ->assertRedirect($list)->assertSessionHas('error', 'Không thể xóa permission đang được gán cho vai trò.');
        $this->assertDatabaseHas('permissions', ['id' => $permission->id]);

        $unused = Permission::firstOrCreate(['name' => 'test.unused_action', 'guard_name' => 'web']);
        $this->actingAs($this->admin)->from($list)->delete(route('permissions.destroy', $unused), [], self::MODAL)
            ->assertRedirect($list)->assertSessionHas('status', 'Đã xóa permission.');
        $this->assertDatabaseMissing('permissions', ['id' => $unused->id]);

        // Thêm permission vẫn chỉ dành cho DEV (redirect như cũ).
        $this->actingAs($this->admin)->get(route('permissions.create'), self::MODAL)->assertRedirect(route('permissions.index'));
    }

    // ── Vai trò ─────────────────────────────────────────────────────────────────────────────

    public function test_role_modal_only_edits_basic_fields_and_matrix_stays_on_full_page(): void
    {
        $list = route('roles.index');

        $this->actingAs($this->admin)->get(route('roles.create'))->assertOk()->assertSee('data-testid="role-matrix"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Roles/Form')->has('matrix'));
        $this->actingAs($this->admin)->get(route('roles.create'), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Roles/Form')->where('asModal', true)->missing('matrix')->missing('selected'));

        $this->actingAs($this->admin)->from($list)->post(route('roles.store'), ['name' => 'teacher'], self::MODAL)
            ->assertRedirect($list)->assertSessionHasErrors('name');

        $this->actingAs($this->admin)->from($list)->post(route('roles.store'), ['name' => 'thu_ngan', 'label' => 'Thu ngân'], self::MODAL)
            ->assertRedirect($list)->assertSessionHas('status', 'Đã tạo vai trò thành công.');
        $role = Role::findByName('thu_ngan', 'web');
        $role->givePermissionTo('lead.view');

        // Đổi tên trong modal không gửi ma trận → giữ nguyên quyền.
        $this->actingAs($this->admin)->from($list)->put(route('roles.update', $role), ['name' => 'thu_ngan', 'label' => 'Thu ngân CS1'], self::MODAL)
            ->assertRedirect($list)->assertSessionHas('status', 'Đã cập nhật vai trò.');
        $this->assertSame('Thu ngân CS1', $role->fresh()->label);
        $this->assertTrue($role->fresh()->hasPermissionTo('lead.view'));

        // Vai trò đang gán cho nhân sự → không xóa (thông báo lỗi); chưa gán → xóa.
        $this->staff();
        $this->actingAs($this->admin)->from($list)->delete(route('roles.destroy', Role::findByName('teacher', 'web')), [], self::MODAL)
            ->assertRedirect($list)->assertSessionHas('error', 'Không thể xóa vai trò đang được gán cho nhân viên.');
        $this->assertTrue(Role::where('name', 'teacher')->exists());
        $this->actingAs($this->admin)->from($list)->delete(route('roles.destroy', $role), [], self::MODAL)
            ->assertRedirect($list)->assertSessionHas('status', 'Đã xóa vai trò.');
        $this->assertFalse(Role::where('name', 'thu_ngan')->exists());

        // Trang đầy đủ giữ redirect + lỗi session.
        $this->actingAs($this->admin)->delete(route('roles.destroy', Role::findByName('admin', 'web')))->assertSessionHasErrors('role');
    }

    // ── Gán vai trò nhân sự ─────────────────────────────────────────────────────────────────

    public function test_user_roles_modal_flow(): void
    {
        $staff = $this->staff();
        $list = route('users.index');

        $this->actingAs($this->admin)->from($list)->put(route('users.roles.update', $staff), ['roles' => []], self::MODAL)
            ->assertRedirect($list)->assertSessionHasErrors('roles');
        $this->assertTrue($staff->fresh()->hasRole('teacher'));

        $this->actingAs($this->admin)->from($list)->put(route('users.roles.update', $staff), ['roles' => ['teacher', 'assistant']], self::MODAL)
            ->assertRedirect($list)->assertSessionHas('status', 'Đã cập nhật vai trò.');
        $this->assertTrue($staff->fresh()->hasRole('assistant'));

        $this->actingAs($this->admin)->put(route('users.roles.update', $staff), ['roles' => ['teacher']])
            ->assertRedirect(route('users.index'))->assertSessionHas('status', 'Đã cập nhật vai trò.');
    }

    // ── Nhân sự ──────────────────────────────────────────────────────────────────────────────

    public function test_user_form_has_three_tabs_and_error_tab_is_selected(): void
    {
        $list = route('users.index');
        $this->actingAs($this->admin)->get(route('users.create'))->assertOk()
            ->assertSee('data-tab="account" aria-selected="true"', false)
            ->assertSee('data-tab="profile" aria-selected="false"', false)
            ->assertSee('data-tab="salary" aria-selected="false"', false)
            ->assertSee('type="file"', false)->assertSee('name="contract_file"', false);

        $valid = ['name' => 'Lê Thu Hà', 'email' => 'ha.le@menglish.test', 'branch_id' => $this->branch->id, 'role' => 'teacher', 'password' => 'Secret@123'];

        // Lỗi ở tab Tài khoản.
        $this->actingAs($this->admin)->from(route('users.create'))->post(route('users.store'), [...$valid, 'email' => 'khong-hop-le'], self::MODAL)
            ->assertRedirect(route('users.create'))->assertSessionHasErrors('email');
        $this->actingAs($this->admin)->get(route('users.create'))->assertOk()
            ->assertSee('data-tab="account" aria-selected="true"', false);

        // Chỉ lỗi ở tab Lương → tab Lương được chọn sẵn.
        $this->actingAs($this->admin)->from(route('users.create'))->post(route('users.store'), [...$valid, 'base_salary' => -5], self::MODAL)
            ->assertRedirect(route('users.create'))->assertSessionHasErrors('base_salary')->assertSessionDoesntHaveErrors('email');
        $this->actingAs($this->admin)->get(route('users.create'))->assertOk()
            ->assertSee('data-tab="salary" aria-selected="true"', false)
            ->assertSee('data-tab="account" aria-selected="false"', false);
        $this->assertFalse(User::where('email', 'ha.le@menglish.test')->exists());

        $this->actingAs($this->admin)->from($list)->post(route('users.store'), $valid, self::MODAL)
            ->assertRedirect($list)->assertSessionHas('status', 'Đã tạo tài khoản thành công.');
        $created = User::where('email', 'ha.le@menglish.test')->firstOrFail();

        $this->actingAs($this->admin)->from(route('users.show', $created))->put(route('users.update', $created), [...$valid, 'password' => '', 'hometown' => 'Nam Định'], self::MODAL)
            ->assertRedirect(route('users.show', $created))->assertSessionHas('status', 'Đã cập nhật tài khoản thành công.');
        $this->assertSame('Nam Định', $created->fresh()->hometown);

        $this->actingAs($this->admin)->put(route('users.update', $created), [...$valid, 'password' => ''])
            ->assertRedirect(route('users.index'))->assertSessionHas('status', 'Đã cập nhật tài khoản thành công.');
    }

    public function test_user_permissions_modal_flow_and_role_links(): void
    {
        $staff = $this->staff();
        $list = route('users.index');

        $this->actingAs($this->admin)->from($list)->put(route('users.permissions.update', $staff), ['overrides' => ['lead' => ['view' => 'allow']]], self::MODAL)
            ->assertRedirect($list)->assertSessionHas('status', 'Đã cập nhật phân quyền chi tiết của Giáo viên Modal.');
        $this->assertTrue($staff->fresh()->can('lead.view'));

        $this->actingAs($this->admin)->put(route('users.permissions.update', $staff), ['overrides' => []])
            ->assertRedirect(route('users.index'))->assertSessionHas('status');

        // Không tự phân quyền cho chính mình (403 cả khi gọi từ modal).
        $manager = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $manager->givePermissionTo('permission.override');
        $this->actingAs($manager)->get(route('users.permissions.edit', $manager), self::MODAL)->assertForbidden();

        // Link gán vai trò ở trang chi tiết / trang phân quyền mở modal.
        $this->actingAs($this->admin)->get(route('users.show', $staff))->assertOk()
            ->assertSee('href="'.route('users.roles.edit', $staff, false).'"', false);
        $this->actingAs($this->admin)->get(route('users.permissions.edit', $staff))->assertOk()
            ->assertSee('href="'.route('users.roles.edit', $staff, false).'"', false)
            ->assertSee('id="permissionOverrideForm"', false);
    }

    public function test_user_detail_page_opens_edit_forms_in_modal(): void
    {
        $staff = $this->staff();

        // Trang chi tiết: Sửa thông tin / Tải HĐ / Phân quyền mở modal (không còn trang riêng).
        $this->actingAs($this->admin)->get(route('users.show', $staff))->assertOk()
            ->assertSee('href="'.route('users.edit', $staff, false).'"', false)
            ->assertSee('href="'.route('users.edit', ['user' => $staff, 'tab' => 'salary'], false).'"', false)
            ->assertSee('href="'.route('users.permissions.edit', $staff, false).'"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Users/Show')->where('user.id', $staff->id));

        // ?tab=salary mở sẵn tab Hợp đồng & Lương; tab lạ → tab Tài khoản.
        $this->actingAs($this->admin)->get(route('users.edit', ['user' => $staff, 'tab' => 'salary']), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Users/Form')->where('asModal', true)->where('initialTab', 'salary'));
        $this->actingAs($this->admin)->get(route('users.edit', ['user' => $staff, 'tab' => 'salary']))->assertOk()
            ->assertSee('data-tab="salary" aria-selected="true"', false);
        $this->actingAs($this->admin)->get(route('users.edit', ['user' => $staff, 'tab' => 'bogus']))->assertOk()
            ->assertSee('data-tab="account" aria-selected="true"', false);
    }

    // ── Nhắc nợ: form thêm mốc nằm trong modal đóng sẵn ──────────────────────────────────────

    public function test_debt_reminder_create_form_lives_in_a_closed_modal(): void
    {
        $page = route('system-config.debt-reminders');

        $html = $this->actingAs($this->admin)->get($page)->assertOk()
            ->assertSee('Thêm mốc nhắc')
            ->assertSee('id="new-reminder-form"', false)
            ->getContent();
        // Modal (role="dialog" chứa form) đóng sẵn: ẩn bằng display:none.
        $form = strpos($html, 'id="new-reminder-form"');
        $dialog = substr($html, strrpos(substr($html, 0, $form), 'role="dialog"'), 300);
        $this->assertStringContainsString('display:none;', $dialog);

        // Lỗi validate → quay lại trang kèm lỗi (modal giữ nguyên dữ liệu đã nhập phía trình duyệt).
        $this->actingAs($this->admin)->from($page)
            ->post(route('system-config.debt-reminders.store'), ['title' => '', 'template_content' => 'Nội dung'])
            ->assertRedirect($page)->assertSessionHasErrors('title');
    }

    // ── Phân quyền ──────────────────────────────────────────────────────────────────────────

    public function test_modal_requests_still_respect_permissions(): void
    {
        $teacher = $this->staff();

        $this->actingAs($teacher)->get(route('system-categories.create'), self::MODAL)->assertForbidden();
        $this->actingAs($teacher)->post(route('system-categories.store'), ['name' => ''], self::MODAL)->assertForbidden();
        $this->actingAs($teacher)->get(route('roles.create'), self::MODAL)->assertForbidden();
        $this->actingAs($teacher)->put(route('users.roles.update', $this->admin), ['roles' => []], self::MODAL)->assertForbidden();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────

    public function staff(): User
    {
        $user = User::query()->where('email', 'gv.modal@example.com')->first()
            ?? User::factory()->create(['name' => 'Giáo viên Modal', 'email' => 'gv.modal@example.com', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $user->syncRoles(['teacher']);

        return $user;
    }
}
