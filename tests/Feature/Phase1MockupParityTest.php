<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 — đối chiếu 12 màn mockup (ui-full-tinh-nang-menglish/crm-ui-mockup, quan-ly-de-dau-vao-crm, epic-6):
 * mỗi test kiểm tra các phần tử chính của mockup có trên màn thật (bộ lọc, cột, nút, khối thông tin),
 * và các phần tử mockup trái quyết định A6 (Hủy chốt, mở lại khách thất bại, CEFR trung bình) không xuất hiện.
 */
class Phase1MockupParityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $manager;

    private User $academic;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở Đội Cấn', 'code' => 'DC', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin', 'Admin Tổng');
        $this->manager = $this->userWithRole('manager', 'Quản Lý Cơ Sở');
        $this->academic = $this->userWithRole('academic_staff', 'Học Vụ Một');
        $this->sales = $this->userWithRole('sales_consultant', 'Sale Một');
    }

    // ── 1. Pipeline ──────────────────────────────────────────────────────

    public function test_pipeline_matches_mockup_cards_and_filters(): void
    {
        $this->lead('new', ['name' => 'Khách Quá Hạn', 'parent_name' => 'Anh Bình', 'source' => 'Facebook', 'next_follow_up_at' => now()->subHour()]);
        $this->lead('consulting', ['name' => 'Khách Sắp Hạn', 'next_follow_up_at' => now()->addHours(3)]);
        $this->lead('test_scheduled', ['name' => 'Khách Còn Hạn', 'next_follow_up_at' => now()->addDays(3)]);
        $this->lead('won', ['name' => 'Khách Đã Chốt']);

        $this->actingAs($this->manager)->get(route('crm.pipeline'))->assertOk()
            // Bộ lọc: tìm kiếm + Nguồn / Người phụ trách
            ->assertSee('Tìm họ tên, số điện thoại...')
            ->assertSee('Nguồn:')->assertSee('Người phụ trách:')
            // Thẻ khách: phụ huynh, phụ trách, trạng thái hạn, nút chuyển bước
            ->assertSee('Phụ huynh: Anh Bình')->assertSee('Phụ trách:')
            ->assertSee('Quá hạn')->assertSee('Sắp hết hạn')->assertSee('Còn hạn')
            ->assertSee('Hạn liên hệ')->assertSee('Hạn chăm sóc tiếp theo')
            ->assertSee('Sang bước tiếp theo')->assertSee('Sửa giai đoạn')
            ->assertSee('Đã chốt — chờ xác nhận chính thức')
            // 8 cột theo A6
            ->assertSee('Hẹn test')->assertSee('Gửi kết quả')->assertSee('Chờ xếp lớp')
            // A6: không có Hủy chốt
            ->assertDontSee('Hủy chốt');

        // Admin thấy thêm lọc chi nhánh
        $this->actingAs($this->admin)->get(route('crm.pipeline'))->assertOk()->assertSee('Chi nhánh:');
    }

    // ── 2. Danh sách khách ───────────────────────────────────────────────

    public function test_customer_list_matches_mockup_columns_and_filters(): void
    {
        $this->lead('consulting', ['name' => 'Nguyễn Minh Anh', 'parent_name' => 'Trần Thu Hà', 'source' => 'Tiktok']);
        $this->lead('new', ['name' => 'Phạm Hoàng Nam', 'source' => 'Facebook']);

        $this->actingAs($this->manager)->get(route('crm.customers.index'))->assertOk()
            ->assertSee('Từ khóa (Tên/SĐT)')->assertSee('Nguồn')->assertSee('Người phụ trách')
            ->assertSee('Giai đoạn')->assertSee('Chi nhánh')->assertSee('Lọc dữ liệu')
            ->assertSee('Tên phụ huynh')->assertSee('Cập nhật gần nhất')
            ->assertSee('Trần Thu Hà')->assertSee('trong tổng số', false);

        // Lọc nguồn / người phụ trách chạy phía server.
        $this->actingAs($this->manager)->get(route('crm.customers.index', ['source' => 'Tiktok']))
            ->assertSee('Nguyễn Minh Anh')->assertDontSee('Phạm Hoàng Nam');
        $this->actingAs($this->manager)->get(route('crm.customers.index', ['search' => 'Thu Hà']))
            ->assertSee('Nguyễn Minh Anh')->assertDontSee('Phạm Hoàng Nam');
        $this->actingAs($this->manager)->get(route('crm.customers.index', ['assigned_user_id' => $this->admin->id]))
            ->assertDontSee('Nguyễn Minh Anh');
    }

    // ── 3. Thêm / Sửa khách ──────────────────────────────────────────────

    public function test_create_and_edit_customer_forms_match_mockup(): void
    {
        $this->actingAs($this->sales)->get(route('crm.customers.create'))->assertOk()
            ->assertSee('Thêm khách mới')->assertSee('Họ và tên')->assertSee('Số điện thoại')
            ->assertSee('Tên phụ huynh (tùy chọn)')->assertSee('Nguồn khách')->assertSee('Chọn nguồn khách')
            ->assertSee('Chi nhánh')->assertSee('Chọn cơ sở học tập')->assertSee('Chị Liên')
            ->assertSee('Lưu thông tin')->assertSee('Hủy');

        $lead = $this->lead('consulting', ['name' => 'Khách Sửa', 'parent_name' => 'Trần Thị Lan']);
        $this->actingAs($this->manager)->get(route('crm.customers.edit', $lead))->assertOk()
            ->assertSee('Sửa thông tin khách')->assertSee('Trần Thị Lan')
            ->assertSee('Không thể thay đổi nếu học viên đã có lớp')->assertSee('Lưu thay đổi')
            ->assertDontSee('Closing Wizard');
    }

    // ── 4. Chi tiết khách ────────────────────────────────────────────────

    public function test_customer_detail_matches_mockup_sections(): void
    {
        $lead = $this->lead('tested', ['name' => 'Nguyễn Lam Anh', 'parent_name' => 'Trần Thị Minh', 'parent_phone' => '0909 888 999',
            'test_score' => '30/45 · Luyện MOVERS', 'next_follow_up_at' => now()->addHours(2)->addMinutes(20)]);

        $this->actingAs($this->manager)->get(route('crm.customers.show', $lead))->assertOk()
            ->assertSee('Chi tiết Khách hàng')->assertSee('Thất bại')->assertSee('In hồ sơ')->assertSee('Phân công lại')
            ->assertSee('Số điện thoại')->assertSee('Tên phụ huynh')->assertSee('SĐT phụ huynh')->assertSee('0909 888 999')
            ->assertSee('Người phụ trách')->assertSee('Chi nhánh')->assertSee('Cơ sở Đội Cấn')
            ->assertSee('Trạng thái &amp; Hạn xử lý', false)->assertSee('Giai đoạn hiện tại')->assertSee('Còn 2 giờ')
            ->assertSee('Đặt lịch &amp; Kết quả', false)->assertSee('Thông tin mở rộng')
            ->assertSee('Lịch hẹn Test')->assertSee('Gửi kết quả &amp; Phản hồi', false)->assertSee('Kết quả &amp; Đánh giá', false)
            ->assertSee('Nhận xét học thử')->assertSee('Chưa có nhận xét từ buổi học thử.')
            ->assertSee('Lịch sử hoạt động')->assertSee('Tất cả hoạt động')->assertSee('Hình thức:')->assertSee('Zalo/SMS')
            ->assertSee('Lưu ghi chú')->assertSee('Bắt đầu tạo hồ sơ')
            // A6: CEFR bị bỏ, không Hủy chốt
            ->assertDontSee('Beginner (A1)')->assertDontSee('Hủy chốt');

        // "Gửi kết quả & Phản hồi" ghi vào lịch sử khách.
        $this->actingAs($this->manager)->post(route('crm.customers.notes.store', $lead), [
            'type' => 'result', 'sent_at' => now()->subHour()->format('Y-m-d H:i'), 'content' => 'Phụ huynh đồng ý lịch học tối',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('crm_customer_histories', ['customer_id' => $lead->id, 'type' => 'result']);
        $this->actingAs($this->manager)->get(route('crm.customers.show', $lead))->assertSee('Phụ huynh đồng ý lịch học tối');

        $this->actingAs($this->manager)->post(route('crm.customers.notes.store', $lead), ['type' => 'result'])
            ->assertSessionHasErrors('sent_at');
    }

    // ── 5. Khách không chốt ──────────────────────────────────────────────

    public function test_lost_deals_match_mockup_and_search_by_reason(): void
    {
        $this->lead('lost', ['name' => 'Khách Học Phí', 'course_interest' => 'Giao tiếp', 'lost_reason' => 'Học phí cao so với ngân sách', 'lost_at' => now()->subDay()]);
        $this->lead('lost', ['name' => 'Khách Không Nghe Máy', 'lost_reason' => 'Gọi 5 lần không nghe máy', 'lost_at' => now()->subDays(2)]);

        $this->actingAs($this->manager)->get(route('crm.lost-deals'))->assertOk()
            ->assertSee('Tổng số khách không chốt')->assertSee('Tìm theo lý do không chốt')->assertSee('Xuất báo cáo')
            ->assertSee('Lý do không chốt')->assertSee('Người phụ trách trước khi fail')->assertSee('Thời điểm dừng')
            ->assertSee('Nhu cầu: Giao tiếp')
            // A6: không mở lại khách Thất bại
            ->assertDontSee('Mở lại');

        $this->actingAs($this->manager)->get(route('crm.lost-deals', ['search' => 'ngân sách']))
            ->assertSee('Khách Học Phí')->assertDontSee('Khách Không Nghe Máy');
    }

    // ── helpers ──────────────────────────────────────────────────────────

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
            'source' => 'Facebook',
            'stage' => $stage,
        ], $attributes));
    }

    private function userWithRole(string $role, string $name): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'name' => $name]);
        $user->assignRole($role);

        return $user;
    }
}
