<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Flow1AndHomeworkScreensTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['name' => 'Admin Test', 'email' => 'admin.test@menglish.local']);
        $this->admin->assignRole('admin');
        Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG']);
        ClassModel::create([
            'name' => 'Lớp IELTS 6.5 Intensive K24',
            'code' => 'IE-2408',
            'max_capacity' => 15,
            'status' => 'active',
        ]);
        Student::create([
            'name' => 'Nguyễn Minh Anh',
            'code' => 'HV-00109',
            'phone' => '0988123456',
            'status' => 'active',
        ]);
    }

    /**
     * Test Flow 1 - Step 1: Đặt lịch khách học thử
     */
    public function test_flow_1_step_1_trial_booking_renders_successfully()
    {
        $response = $this->actingAs($this->admin)->get(route('classes.trial-booking'));
        $response->assertStatus(200);
        $response->assertSee('Đặt lịch khách học thử vào buổi');
        $response->assertSee('Chọn lớp');
        $response->assertSee('Chọn buổi học');
    }

    /**
     * Trang đặt học thử phải hiển thị buổi học THẬT sắp diễn ra từ ClassSession
     * (trước đây là 3 buổi mockup cứng tháng 10/2023) và ẩn buổi đã hủy/quá khứ.
     */
    public function test_trial_booking_lists_real_upcoming_sessions_only()
    {
        $branchId = Branch::first()->id;
        \App\Models\ClassSession::create([
            'class_id' => ClassModel::first()->id, 'branch_id' => $branchId,
            'date' => now()->addDay()->toDateString(),
            'shift_name' => 'Ca 1', 'start_time' => '08:00', 'end_time' => '09:30', 'status' => 'scheduled',
        ]);
        \App\Models\ClassSession::create([
            'class_id' => ClassModel::first()->id, 'branch_id' => $branchId,
            'date' => now()->addDays(2)->toDateString(),
            'shift_name' => 'Ca 2', 'start_time' => '09:30', 'end_time' => '11:00', 'status' => 'cancelled',
        ]);
        \App\Models\ClassSession::create([
            'class_id' => ClassModel::first()->id, 'branch_id' => $branchId,
            'date' => now()->subDay()->toDateString(),
            'shift_name' => 'Ca 3', 'start_time' => '14:00', 'end_time' => '15:30', 'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->admin)->get(route('classes.trial-booking'));
        $response->assertOk();
        $response->assertSee(now()->addDay()->format('d/m/Y'));
        $response->assertSee('Ca 1');
        $response->assertDontSee('Ca 2');
        $response->assertDontSee(now()->subDay()->format('d/m/Y'));
    }

    /**
     * Test Flow 1 - Step 2: Tạo lớp mới
     */
    public function test_flow_1_step_2_create_class_renders_successfully()
    {
        $response = $this->actingAs($this->admin)->get(route('classes.create'));
        $response->assertStatus(200);
        $response->assertSee('Tạo lớp mới');
        $response->assertSee('Thông tin cơ bản');
        $response->assertSee('Phòng học');
        $response->assertSee('Đội ngũ phụ trách');
        $response->assertSee('Học phí');
        $response->assertSee('Tên lớp học');
        $response->assertSee('Sĩ số tối đa');
    }

    /**
     * Test Flow 1 - Step 2: Store class
     */
    public function test_flow_1_step_2_store_class_creates_record_and_redirects()
    {
        $branch = Branch::first() ?? Branch::create(['name' => 'Chi nhánh Test', 'code' => 'TEST']);

        $response = $this->actingAs($this->admin)->post(route('classes.store'), [
            'ten_lop' => 'IELTS Test K99',
            'ma_lop' => 'IE-TEST-99',
            'chi_nhanh' => $branch->id,
            'chuong_trinh' => 'IELTS',
            'cap_do' => 'B1',
            'si_so_toi_da' => 16,
            'phong_hoc' => 'P101',
            'hoc_phi' => 8500000,
            'ghi_chu' => 'Ghi chú lớp test',
        ]);

        if (session('errors')) {
            dump(session('errors')->all());
        }
        $response->assertSessionHasNoErrors();
        $created = ClassModel::where('code', 'IE-TEST-99')->first();
        $this->assertNotNull($created);
        $this->assertEquals('IELTS Test K99', $created->name);
        $response->assertRedirect(route('classes.profile', ['id' => $created->id]));
    }

    /**
     * Test Flow 1 - Step 3: Hồ sơ lớp học
     */
    public function test_flow_1_step_3_profile_renders_successfully()
    {
        $class = ClassModel::first();
        $response = $this->actingAs($this->admin)->get(route('classes.profile', ['id' => $class?->id]));
        $response->assertStatus(200);
        $response->assertSee('Hồ sơ lớp');
        $response->assertSee('Thông tin chung');
        $response->assertSee('Danh sách học sinh');
    }

    /**
     * Test Flow 1 - Step 4: Sơ đồ khối lớp học thuật
     */
    public function test_flow_1_step_4_academic_overview_renders_successfully()
    {
        $response = $this->actingAs($this->admin)->get(route('classes.academic-overview'));
        $response->assertStatus(200);
        $response->assertSee('Tổng quan Danh sách lớp');
        $response->assertSee('Số lớp theo chương trình');
        $response->assertSee('Số lớp theo trình độ / khối', false);
    }

    /**
     * Test Flow 1 - Step 5: Danh sách lớp chi tiết
     */
    public function test_flow_1_step_5_academic_list_renders_successfully()
    {
        $response = $this->actingAs($this->admin)->get(route('classes.academic-list'));
        $response->assertStatus(200);
        $response->assertSee('Danh sách lớp chi tiết Học thuật');
        $response->assertSee('Big Test');
    }

    /**
     * Test Flow 1 - Step 6: Chi tiết lớp học thuật
     */
    public function test_flow_1_step_6_academic_detail_renders_successfully()
    {
        $class = ClassModel::first();
        $response = $this->actingAs($this->admin)->get(route('classes.academic-detail', ['id' => $class?->id]));
        $response->assertStatus(200);
        $response->assertSee('Chi tiết lớp');
        $response->assertSee('Chương trình &amp; Tiến độ', false);
        $response->assertSee('Lịch Big Test');
    }

    /**
     * Test Cổng Học sinh nộp bài tập (Học tập của tôi)
     */
    public function test_student_homework_portal_renders_successfully()
    {
        $student = Student::first();
        $response = $this->actingAs($this->admin)->get(route('portal.student.homework', ['studentId' => $student?->id]));
        $response->assertStatus(200);
        $response->assertSee('Học tập của tôi');
        $response->assertSee('Nhận xét buổi học');
        $response->assertSee('Bảng điểm');
        $response->assertSee('Bài tập về nhà');
        $response->assertSee('Quay video');
        $response->assertSee('Viết từ vựng');
        $response->assertSee('Làm Workbook');
    }

    /**
     * Test Cổng Giáo viên chấm bài nộp của lớp
     */
    public function test_teacher_submissions_portal_renders_successfully()
    {
        $class = ClassModel::first();
        $response = $this->actingAs($this->admin)->get(route('portal.teacher.submissions', ['classId' => $class?->id]));
        $response->assertStatus(200);
        $response->assertSee('Bài nộp của lớp');
        $response->assertSee('Quay video');
        $response->assertSee('Danh sách đã nộp bài');
    }
}
