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

    // ── 6. Báo cáo doanh số ──────────────────────────────────────────────

    public function test_sales_report_shows_funnel_lost_reasons_and_data_note(): void
    {
        $this->lead('new');
        $this->lead('lost', ['name' => 'Khách Ở Xa', 'lost_reason' => 'Vị trí xa nhà, không có người đưa đón', 'lost_at' => now()->subDays(2)]);

        $this->actingAs($this->manager)->get(route('crm.reports'))->assertOk()
            ->assertSee('Khoảng thời gian')->assertSee('Chi nhánh')->assertSee('Lọc dữ liệu')
            ->assertSee('Giai đoạn chuyển đổi')->assertSee('Hẹn test')->assertSee('Chờ xếp lớp')
            ->assertSee('Lý do khách không chốt')->assertSee('hồ sơ thất bại trong kỳ')
            ->assertSee('Nội dung lý do (Log chi tiết)')->assertSee('Vị trí xa nhà, không có người đưa đón')->assertSee('Khách Ở Xa')
            ->assertSee('Ghi chú về nguồn dữ liệu')
            ->assertDontSee('[Plugin: crm_sales_report_tab]');
    }

    // ── 7. Chốt & Xếp lớp ────────────────────────────────────────────────

    public function test_closing_wizard_matches_mockup_steps_and_class_cards(): void
    {
        $course = Course::create(['name' => 'Starters', 'code' => 'STR', 'tuition_fee' => 6000000, 'is_active' => true]);
        $teacher = $this->userWithRole('teacher', 'Cô Tuyết Mai');
        ClassModel::create([
            'code' => 'STR-01', 'name' => 'Starters FAM 1', 'course_id' => $course->id, 'branch_id' => $this->branch->id,
            'teacher_id' => $teacher->id, 'status' => 'upcoming', 'start_date' => now()->addWeek(), 'max_capacity' => 10, 'min_students' => 6,
            'schedule_text' => 'Thứ 2 - Thứ 4 - Thứ 6',
        ]);
        $lead = $this->lead('tested', ['name' => 'Nguyễn Minh Hoàng', 'course_interest' => 'Starters']);

        $this->actingAs($this->manager)->get(route('crm.closing-wizard', ['customer_id' => $lead->id]))->assertOk()
            ->assertSee('Quy trình Chốt &amp; Xếp lớp', false)->assertDontSee('Closing Wizard')
            ->assertSee('Xác nhận Chốt')->assertSee('Danh sách lớp')
            ->assertSee('Đã đóng học phí đăng ký')->assertSee('Chưa hoàn thành phí đăng ký')
            ->assertSee('Hệ thống sẽ tự động tạo nhắc việc thu phí sau khi Chốt.')
            ->assertSee('Khi Chốt, hồ sơ khách sẽ được nâng cấp thành tài khoản học viên chính thức.')
            ->assertSee('Lớp học phù hợp đề xuất')->assertSee('Xếp lớp sau')->assertSee('Khách sẽ xuất hiện trong mục')
            ->assertSee('Lịch học: Thứ 2 - Thứ 4 - Thứ 6')->assertSee('Giáo viên: Cô Tuyết Mai')
            ->assertSee('Số học viên hiện có:')->assertSee('ngưỡng khai giảng 6')->assertSee('Cần thêm 6 học viên để khai giảng')
            ->assertSee('Chọn lớp này')
            // A6: không có "cọc", không dữ liệu học phí giả
            ->assertDontSee('Cọc')->assertDontSee('12500000');
    }

    // ── 8. Khách chốt thành công (Chờ xếp lớp + Gán lớp) ─────────────────

    public function test_won_screen_has_waiting_section_class_filter_and_report_download(): void
    {
        $course = Course::create(['name' => 'Movers', 'code' => 'MOV', 'tuition_fee' => 5000000, 'is_active' => true]);
        $classA = ClassModel::create(['code' => 'MOV-A', 'name' => 'Movers A', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'status' => 'active', 'max_capacity' => 10]);
        $classB = ClassModel::create(['code' => 'MOV-B', 'name' => 'Movers B', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'status' => 'active', 'max_capacity' => 10]);
        $studentA = Student::create(['code' => 'HV-A1', 'name' => 'HV A', 'phone' => '0900000001', 'branch_id' => $this->branch->id, 'current_class_id' => $classA->id, 'status' => 'studying']);
        $studentB = Student::create(['code' => 'HV-B1', 'name' => 'HV B', 'phone' => '0900000002', 'branch_id' => $this->branch->id, 'current_class_id' => $classB->id, 'status' => 'studying']);
        $this->lead('won', ['name' => 'Phạm Minh Quân', 'converted_student_id' => $studentA->id, 'converted_at' => now()]);
        $this->lead('won', ['name' => 'Ngô Bảo Ngọc', 'converted_student_id' => $studentB->id, 'converted_at' => now()]);
        $waitingStudent = Student::create(['code' => 'HV-W1', 'name' => 'HV W', 'phone' => '0900000003', 'branch_id' => $this->branch->id, 'status' => Student::INITIAL_STATUS]);
        $this->lead('waiting_class', ['name' => 'Nguyễn Văn An', 'converted_student_id' => $waitingStudent->id, 'waiting_course_id' => $course->id, 'converted_at' => now()->subDays(8)]);

        $this->actingAs($this->academic)->get(route('crm.customers.won'))->assertOk()
            ->assertSee('Chờ xếp lớp (Cần xử lý gấp)')->assertSee('Ưu tiên xử lý')->assertSee('Nguyễn Văn An')->assertSee('Gán lớp')
            ->assertSee('Chờ 8 ngày')
            ->assertSee('Lớp học:')->assertSee('Nhập tên hoặc số điện thoại...')
            ->assertSee('Khách đã có lớp')->assertSee('Tải báo cáo chi tiết')->assertSee('Thời điểm chốt')
            ->assertSee('Movers A')->assertDontSee('Hủy chốt');

        $this->actingAs($this->academic)->get(route('crm.customers.won', ['class_id' => $classB->id]))
            ->assertSee('Ngô Bảo Ngọc')->assertDontSee('Phạm Minh Quân');

        $this->actingAs($this->academic)->get(route('crm.waiting-list'))->assertOk()->assertSee('Nguyễn Văn An')->assertSee('Gán lớp');
    }

    // ── 9. Xác nhận chính thức ───────────────────────────────────────────

    public function test_confirmation_screen_matches_mockup_sections(): void
    {
        $course = Course::create(['name' => 'IELTS Foundation', 'code' => 'IF', 'tuition_fee' => 5000000, 'is_active' => true]);
        $class = ClassModel::create(['code' => 'IF-202310', 'name' => 'IELTS Foundation K1', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'status' => 'active', 'start_date' => now()->subWeek(), 'max_capacity' => 10]);
        $student = Student::create(['code' => 'HV-C1', 'name' => 'Phan Văn Trị', 'phone' => '0944555666', 'branch_id' => $this->branch->id, 'current_class_id' => $class->id, 'status' => Student::INITIAL_STATUS]);
        $lead = $this->lead('won', ['name' => 'Phan Văn Trị', 'converted_student_id' => $student->id, 'converted_at' => now()]);
        ClassEnrollment::create(['class_id' => $class->id, 'student_id' => $student->id, 'customer_id' => $lead->id, 'status' => 'pending', 'enrolled_at' => now()]);
        $waitingStudent = Student::create(['code' => 'HV-C2', 'name' => 'HV chờ', 'phone' => '0944555667', 'branch_id' => $this->branch->id, 'status' => Student::INITIAL_STATUS]);
        $this->lead('waiting_class', ['name' => 'Nguyễn Hoàng Anh', 'converted_student_id' => $waitingStudent->id, 'waiting_course_id' => $course->id, 'converted_at' => now()]);

        $this->actingAs($this->academic)->get(route('crm.confirmations'))->assertOk()
            ->assertSee('Khách hàng đã chốt thành công')->assertSee('học viên')
            ->assertSee('Chờ xếp lớp (Cần xử lý gấp)')->assertSee('Nguyễn Hoàng Anh')->assertSee('Gán lớp')
            ->assertSee('Chi nhánh:')->assertSee('Lớp học:')->assertSee('Tìm kiếm học viên...')
            ->assertSee('Khách đã có lớp')->assertSee('Lớp ID: IF-202310')->assertSee('Ngày chốt')->assertSee('Trạng thái')
            ->assertSee('Chờ khai giảng')->assertSee('Xác nhận chính thức')->assertSee('Xác nhận học viên')
            // A6 Q5: không có trạng thái Học thử
            ->assertDontSee('>Học thử<', false);

        $this->actingAs($this->academic)->get(route('crm.confirmations', ['class_id' => $class->id + 99]))->assertDontSee('Lớp ID: IF-202310');
    }

    // ── 10. Quản lý đề test ──────────────────────────────────────────────

    public function test_placement_test_list_matches_mockup_filters_status_and_toggle(): void
    {
        $active = PlacementTest::create(['code' => 'TEST-G1-G2-01', 'title' => 'Đề khảo sát đầu vào số 1', 'duration_minutes' => 35, 'is_active' => true]);
        $hidden = PlacementTest::create(['code' => 'TEST-G3-G4-02', 'title' => 'Đề khảo sát đầu vào số 2', 'duration_minutes' => 45, 'is_active' => false]);
        $academicLead = $this->userWithRole('academic_lead', 'Học Thuật');

        $this->actingAs($academicLead)->get(route('placement-tests.index'))->assertOk()
            ->assertSee('Quản lý đề test đầu vào')->assertSee('Tạo đề mới')
            ->assertSee('Cấp độ')->assertSee('Trạng thái')->assertSee('Tìm kiếm tên đề')->assertSee('Làm mới')
            ->assertSee('Loại đề')->assertSee('placement_test')->assertSee('Thời gian')->assertSee('35 phút')
            ->assertSee('Hoạt động')->assertSee('Ẩn')->assertSee('ID: TEST-G1-G2-01')
            ->assertSee('trong tổng số', false)
            // A6 Q2: bỏ CEFR / Band
            ->assertDontSee('CEFR &amp; Cambridge', false)->assertDontSee('khung CEFR')->assertDontSee('Overall (Band)');

        $this->actingAs($academicLead)->get(route('placement-tests.index', ['status' => 'hidden']))
            ->assertSee('Đề khảo sát đầu vào số 2')->assertDontSee('Đề khảo sát đầu vào số 1');
        $this->actingAs($academicLead)->get(route('placement-tests.index', ['grade_group' => 'khoi_1_2']))
            ->assertSee('Đề khảo sát đầu vào số 1')->assertDontSee('Đề khảo sát đầu vào số 2');
        $this->actingAs($academicLead)->get(route('placement-tests.index', ['search' => 'số 2']))
            ->assertSee('Đề khảo sát đầu vào số 2')->assertDontSee('Đề khảo sát đầu vào số 1');

        // Ẩn / Kích hoạt ngay trên danh sách.
        $this->actingAs($academicLead)->post(route('placement-tests.toggle-active', $active->id))->assertRedirect();
        $this->assertFalse($active->fresh()->is_active);
        $this->actingAs($academicLead)->post(route('placement-tests.toggle-active', $hidden->id))->assertRedirect();
        $this->assertTrue($hidden->fresh()->is_active);
        $this->actingAs($this->academic)->post(route('placement-tests.toggle-active', $hidden->id))->assertForbidden();
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
