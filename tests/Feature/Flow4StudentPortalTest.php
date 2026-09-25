<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Flow4StudentPortalTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $student;

    protected $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['name' => 'Parent User', 'email' => 'parent@menglish.local']);

        $branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG']);
        $this->class = ClassModel::create([
            'name' => 'IELTS Starter - M01',
            'code' => 'IE-M01',
            'max_capacity' => 16,
            'status' => 'active',
            'branch_id' => $branch->id,
        ]);

        $this->student = Student::create([
            'user_id' => $this->user->id,
            'name' => 'Nguyễn Văn A',
            'code' => 'HV-00109',
            'phone' => '0987654321',
            'status' => 'active',
            'current_class_id' => $this->class->id,
            'branch_id' => $branch->id,
            'address' => '123 Đường ABC, Quận Cầu Giấy, Hà Nội',
            'dob' => '2015-06-15',
        ]);

        $tuition = StudentTuition::create([
            'student_id' => $this->student->id,
            'class_id' => $this->class->id,
            'branch_id' => $branch->id,
            'total_amount' => 17500000,
            'final_amount' => 17500000,
            'paid_amount' => 15000000,
            'debt_amount' => 2500000,
            'status' => 'partial',
        ]);

        TuitionReceipt::create([
            'receipt_number' => 'PT12345',
            'student_tuition_id' => $tuition->id,
            'student_id' => $this->student->id,
            'amount' => 5000000,
            'payment_method' => 'Chuyển khoản',
            'payment_date' => now(),
            'status' => 'approved',
        ]);
    }

    /**
     * Flow 4 - Step 1: Mobile App Shell
     */
    public function test_flow_4_step_1_app_shell_renders_successfully()
    {
        $response = $this->actingAs($this->user)->get(route('portal.app-shell'));
        $response->assertStatus(200);
        $response->assertSee('MENGLISH');
        $response->assertSee('Cổng Học Sinh');
        $response->assertSee('Các chức năng chính');
        $response->assertSee('Trang chủ');
        $response->assertSee('Luyện phát âm AI');
        $response->assertSee('Hộp thư Thông báo');
        $response->assertSee('Khảo sát Đánh giá');
        $response->assertSee('Gửi Feedback chặng');
    }

    /**
     * Flow 4 - Step 2: Trang chủ Phụ huynh / Học sinh
     */
    public function test_flow_4_step_2_student_home_renders_successfully()
    {
        $response = $this->actingAs($this->user)->get(route('portal.student.home', ['studentId' => $this->student->id]));
        $response->assertStatus(200);
        $response->assertSee('Xin chào,');
        $response->assertSee('Nguyễn Văn A');
        $response->assertSee('Thông tin học sinh');
        $response->assertSee('IELTS Starter - M01');
        $response->assertSee('0987654321');
        $response->assertSee('Thông tin học phí');
        $response->assertSee('Tổng đã đóng');
        $response->assertSee('Còn nợ');
        $response->assertSee('Lịch sử thu học phí');
        $response->assertSee('PT12345');
    }

    /**
     * Flow 4 - Step 3: Học tập của tôi - Nộp bài tập
     */
    public function test_flow_4_step_3_student_homework_renders_successfully()
    {
        $response = $this->actingAs($this->user)->get(route('portal.student.homework', ['studentId' => $this->student->id]));
        $response->assertStatus(200);
        $response->assertSee('Học tập của tôi');
        $response->assertSee('Nhận xét buổi học');
        $response->assertSee('Bài tập về nhà');
        $response->assertSee('Quay video bài học');
        $response->assertSee('Viết từ vựng');
    }

    /**
     * Flow 4 - Step 3: Submit homework
     */
    public function test_flow_4_step_3_submit_homework_creates_record()
    {
        $response = $this->actingAs($this->user)->post(route('portal.student.homework.submit'), [
            'student_id' => $this->student->id,
            'homework_type' => 'video',
            'notes' => 'Con đã đọc xong bài Unit 4',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('academic_records', [
            'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap',
            'status' => 'submitted',
        ]);
    }

    /**
     * Flow 4 - Step 4: Luyện phát âm & Thu âm giọng nói AI
     */
    public function test_flow_4_step_4_pronunciation_renders_successfully()
    {
        $response = $this->actingAs($this->user)->get(route('portal.student.pronunciation', ['studentId' => $this->student->id]));
        $response->assertStatus(200);
        $response->assertSee('Luyện phát âm');
        $response->assertSee('Audio Mẫu từ Giáo trình');
        $response->assertSee('Unit 1: Greetings - Bài 2');
        $response->assertSee('Đang luyện tập:');
        $response->assertSee('Nộp bài ghi âm');
        $response->assertSee('Lịch sử của bạn');
        // Phase 4: không còn lịch sử/điểm "AI" giả khi chưa có bài nộp.
        $response->assertDontSee('Điểm AI');
        $response->assertSee('Chưa có bài ghi âm');
    }

    /**
     * Flow 4 - Step 4: Submit pronunciation recording
     */
    public function test_flow_4_step_4_submit_pronunciation_creates_record()
    {
        $response = $this->actingAs($this->user)->post(route('portal.student.pronunciation.store'), [
            'student_id' => $this->student->id,
            'unit_title' => 'Unit 1: Greetings - Bài 2',
            'duration' => '00:45',
        ]);

        $response->assertSessionHasNoErrors();
        // Phase 4: không sinh điểm AI ngẫu nhiên — bài nộp chờ giáo viên chấm.
        $this->assertDatabaseHas('academic_records', [
            'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am',
            'status' => 'pending_review',
        ]);
        $record = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am')->first();
        $this->assertNull($record->data['score']);
        $this->actingAs($this->user)->get(route('portal.student.pronunciation', ['studentId' => $this->student->id]))
            ->assertSee('Chờ giáo viên chấm');
    }

    /**
     * Flow 4 - Step 5: Danh sách thông báo
     */
    public function test_flow_4_step_5_notifications_renders_successfully()
    {
        $this->artisan('students:send-birthday-notifications', ['--date' => '2026-06-15'])->assertExitCode(0);
        $response = $this->actingAs($this->user)->get(route('portal.student.notifications', ['studentId' => $this->student->id]));
        $response->assertStatus(200);
        $response->assertSee('Thông báo');
        $response->assertSee('Chúc mừng sinh nhật!');
        // Phase 4: không còn tự tạo thông báo mẫu (học phí/khảo sát/nghỉ lễ giả) khi mở trang.
        $response->assertDontSee('Nhắc nhở học phí tháng 9');
        $response->assertDontSee('Lịch nghỉ lễ Quốc Khánh 2/9');
        $response->assertSee('Đánh dấu tất cả đã đọc');
    }

    /**
     * Flow 4 - Step 5: Mark all notifications as read
     */
    public function test_flow_4_step_5_mark_notifications_read()
    {
        $response = $this->actingAs($this->user)->post(route('portal.student.notifications.read'), [
            'student_id' => $this->student->id,
        ]);
        $response->assertSessionHas('success');
    }

    /**
     * Flow 4 - Step 6: Khảo sát & Đánh giá chất lượng đào tạo
     */
    public function test_flow_4_step_6_survey_renders_successfully()
    {
        $response = $this->actingAs($this->user)->get(route('portal.student.survey', ['studentId' => $this->student->id]));
        $response->assertStatus(200);
        $response->assertSee('Khảo sát & Đánh giá');
        $response->assertSee('Khảo sát đang mở');
        $response->assertSee('Đánh giá chất lượng cơ sở vật chất tháng 10');
        $response->assertSee('Nội dung phản hồi');
        $response->assertSee('Gửi phản hồi');
    }

    /**
     * Flow 4 - Step 6: Submit survey
     */
    public function test_flow_4_step_6_submit_survey_creates_record()
    {
        $response = $this->actingAs($this->user)->post(route('portal.student.survey.store'), [
            'student_id' => $this->student->id,
            'survey_title' => 'Đánh giá chất lượng cơ sở vật chất tháng 10',
            'feedback' => 'Phòng học sạch sẽ, điều hòa mát mẻ.',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('academic_records', [
            'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/06_khao_sat',
            'status' => 'completed',
        ]);
    }

    /**
     * Flow 4 - Step 7: Phụ huynh gửi Feedback chặng học (MH6)
     */
    public function test_flow_4_step_7_feedback_renders_successfully()
    {
        $response = $this->actingAs($this->user)->get(route('portal.student.feedback', ['studentId' => $this->student->id]));
        $response->assertStatus(200);
        $response->assertSee('Đánh giá chặng học');
        $response->assertSee('Chặng học đang mở thu thập');
        $response->assertSee('1. Mức độ hài lòng chung');
        $response->assertSee('2. Lĩnh vực cần góp ý');
        $response->assertSee('3. Nội dung feedback chi tiết');
        $response->assertSee('Gửi feedback');
        $response->assertSee('Lần đầu');
        $response->assertSee('Đợt đóng');
        $response->assertSee('Chưa mở');
    }

    /**
     * Flow 4 - Step 7: Validation fails if all 3 fields are empty (R-04)
     */
    public function test_flow_4_step_7_validation_fails_when_all_empty()
    {
        $response = $this->actingAs($this->user)->post(route('portal.student.feedback.store'), [
            'student_id' => $this->student->id,
            'stage_name' => 'Chặng 2: Giao tiếp Phản xạ & Ngữ âm',
            'muc_do_hai_long' => 0,
            'fb_hoc_thuat' => '',
            'fb_giao_vien' => '',
            'fb_khac' => '',
            'noi_dung_feedback' => '',
        ]);

        $response->assertSessionHasErrors(['validation']);
    }

    /**
     * Flow 4 - Step 7: Submit feedback successfully
     */
    public function test_flow_4_step_7_submit_feedback_creates_record()
    {
        $response = $this->actingAs($this->user)->post(route('portal.student.feedback.store'), [
            'student_id' => $this->student->id,
            'stage_name' => 'Chặng 2: Giao tiếp Phản xạ & Ngữ âm',
            'muc_do_hai_long' => 5,
            'fb_hoc_thuat' => '1',
            'fb_giao_vien' => '1',
            'noi_dung_feedback' => 'Giáo viên dạy rất hay, học sinh tiếp thu tốt.',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('academic_records', [
            'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback',
            'status' => 'submitted',
        ]);
    }

    /**
     * Flow 4 - Step 2: Update student profile
     */
    public function test_flow_4_step_2_update_student_profile()
    {
        $response = $this->actingAs($this->user)->post(route('portal.student.profile.update', ['id' => $this->student->id]), [
            'student_id' => $this->student->id,
            'phone' => '0912345678',
            'address' => '456 Cầu Giấy, Hà Nội',
            'notes' => 'Cập nhật từ phụ huynh',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('students', [
            'id' => $this->student->id,
            'phone' => '0912345678',
            'address' => '456 Cầu Giấy, Hà Nội',
        ]);
    }

    /**
     * Flow 4 - Step 2: Submit tuition payment report request
     */
    public function test_flow_4_step_2_submit_tuition_request()
    {
        $response = $this->actingAs($this->user)->post(route('portal.student.tuition.request'), [
            'student_id' => $this->student->id,
            'amount' => 2500000,
            'content' => 'CK HP HK2 - Đã chuyển khoản qua Vietcombank',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('academic_records', [
            'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/02_trang_chu_phu_huynh_hoc_sinh',
            'status' => 'pending',
        ]);
    }

    /**
     * Flow 4 - Step 3: Update and delete homework submission
     */
    public function test_flow_4_step_3_update_and_delete_homework()
    {
        // 1. Submit homework
        $this->actingAs($this->user)->post(route('portal.student.homework.submit'), [
            'student_id' => $this->student->id,
            'homework_type' => 'vocabulary',
            'notes' => 'Từ vựng bài 1',
        ]);

        $record = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap')->first();
        $this->assertNotNull($record);

        // 2. Update homework
        $updateResp = $this->actingAs($this->user)->post(route('portal.student.homework.update', ['id' => $record->id]), [
            'student_id' => $this->student->id,
            'notes' => 'Đã sửa bổ sung từ vựng bài 1',
        ]);
        $updateResp->assertSessionHas('success');

        // 3. Delete homework
        $delResp = $this->actingAs($this->user)->delete(route('portal.student.homework.destroy', ['id' => $record->id]), [
            'student_id' => $this->student->id,
        ]);
        $delResp->assertSessionHas('success');
        $this->assertNull(AcademicRecord::find($record->id));
    }

    /**
     * Flow 4 - Step 4: Delete pronunciation record
     */
    public function test_flow_4_step_4_delete_pronunciation()
    {
        $this->actingAs($this->user)->post(route('portal.student.pronunciation.store'), [
            'student_id' => $this->student->id,
            'unit_title' => 'Unit 2: Pronunciation Test',
            'duration' => '00:30',
        ]);

        $record = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am')->first();
        $this->assertNotNull($record);

        $response = $this->actingAs($this->user)->delete(route('portal.student.pronunciation.destroy', ['id' => $record->id]), [
            'student_id' => $this->student->id,
        ]);

        $response->assertSessionHas('success');
        $this->assertNull(AcademicRecord::find($record->id));
    }

    /**
     * Flow 4 - Step 5: Mark single notification read and delete notification
     */
    public function test_flow_4_step_5_mark_single_notification_read_and_delete()
    {
        // Phase 4: mở trang không tự tạo thông báo mẫu nữa; dùng thông báo thật (sinh nhật).
        $this->actingAs($this->user)->get(route('portal.student.notifications', ['studentId' => $this->student->id]));
        $this->assertSame(0, AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao')->count());

        $this->artisan('students:send-birthday-notifications', ['--date' => '2026-06-15'])->assertExitCode(0);
        $notif = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao')->first();
        $this->assertNotNull($notif);

        // Mark single read
        $readResp = $this->actingAs($this->user)->post(route('portal.student.notifications.read-single', ['id' => $notif->id]), [
            'student_id' => $this->student->id,
        ]);
        $readResp->assertSessionHas('success');
        $notif->refresh();
        $this->assertFalse($notif->data['unread']);

        // Delete notification
        $delResp = $this->actingAs($this->user)->delete(route('portal.student.notifications.destroy', ['id' => $notif->id]), [
            'student_id' => $this->student->id,
        ]);
        $delResp->assertSessionHas('success');
        $this->assertNull(AcademicRecord::find($notif->id));
    }

    /**
     * Flow 4 - Step 6: Delete survey record
     */
    public function test_flow_4_step_6_delete_survey()
    {
        $this->actingAs($this->user)->post(route('portal.student.survey.store'), [
            'student_id' => $this->student->id,
            'survey_title' => 'Khảo sát thử nghiệm',
            'rating' => 5,
            'feedback' => 'Rất hài lòng',
        ]);

        $survey = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/06_khao_sat')->first();
        $this->assertNotNull($survey);

        $response = $this->actingAs($this->user)->delete(route('portal.student.survey.destroy', ['id' => $survey->id]), [
            'student_id' => $this->student->id,
        ]);

        $response->assertSessionHas('success');
        $this->assertNull(AcademicRecord::find($survey->id));
    }

    /**
     * Flow 4 - Step 7: Delete feedback record
     */
    public function test_flow_4_step_7_delete_feedback()
    {
        $this->actingAs($this->user)->post(route('portal.student.feedback.store'), [
            'student_id' => $this->student->id,
            'stage_name' => 'Chặng 2: Giao tiếp',
            'muc_do_hai_long' => 4,
            'noi_dung_feedback' => 'Tốt',
        ]);

        $fb = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback')->first();
        $this->assertNotNull($fb);

        $response = $this->actingAs($this->user)->delete(route('portal.student.feedback.destroy', ['id' => $fb->id]), [
            'student_id' => $this->student->id,
        ]);

        $response->assertSessionHas('success');
        $this->assertNull(AcademicRecord::find($fb->id));
    }

    /**
     * Test Academic System Show route native redirection / link for all 7 screens of Flow 4
     */
    public function test_academic_system_show_native_redirect_for_flow_4()
    {
        $screens = [
            '01_app_shell_phu_huynh_hoc_sinh' => route('portal.app-shell'),
            '02_trang_chu_phu_huynh_hoc_sinh' => route('portal.student.home'),
            '03_hoc_tap_cua_toi_nop_bai_tap' => route('portal.student.homework'),
            '04_luyen_phat_am' => route('portal.student.pronunciation'),
            '05_danh_sach_thong_bao' => route('portal.student.notifications'),
            '06_khao_sat' => route('portal.student.survey'),
            '07_phu_huynh_gui_feedback' => route('portal.student.feedback'),
        ];

        foreach ($screens as $screen => $nativeUrl) {
            $response = $this->actingAs($this->user)->get(route('academic-system.show', [
                'category' => '04_Cong_Phu_Huynh_Hoc_Sinh',
                'screen' => $screen,
                'native' => 1,
            ]));
            $response->assertRedirect($nativeUrl);
        }
    }
}
