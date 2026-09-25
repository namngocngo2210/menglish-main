<?php

namespace Tests\Feature;

use App\Models\BigTest;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SyllabusModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_can_upload_document_and_build_unit(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $course = Course::create(['code' => 'TEST-CRS', 'name' => 'Khóa học Test']);

        // Giáo trình được tạo ở màn Soạn syllabus; màn Tài liệu chỉ nhận file thật (xem Phase2SyllabusTest).
        $response = $this->actingAs($user)->post('/syllabus/curriculums', [
            'code' => 'CUR-TEST',
            'title' => 'Giáo trình Thử nghiệm',
            'course_id' => $course->id,
            'version' => 'v1.0',
        ]);

        $cur = SyllabusCurriculum::where('code', 'CUR-TEST')->first();
        $response->assertRedirect(route('syllabus.builder', ['curriculum' => $cur->id]));
        $this->assertDatabaseHas('syllabus_curriculums', ['code' => 'CUR-TEST']);

        $unitResponse = $this->actingAs($user)->post('/syllabus/units', [
            'curriculum_id' => $cur->id,
            'unit_number' => 1,
            'title' => 'Unit 01: Introduction',
            'objectives' => 'Làm quen bảng chữ cái IPA',
        ]);

        $this->assertDatabaseHas('syllabus_units', [
            'curriculum_id' => $cur->id,
            'title' => 'Unit 01: Introduction',
        ]);
    }

    public function test_can_distribute_big_test(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $class = ClassModel::create(['code' => 'CL-99', 'name' => 'Lớp Thi Test']);

        $response = $this->actingAs($user)->post('/syllabus/big-tests/distribution', [
            'title' => 'Kỳ thi Giữa Kỳ Đợt 99',
            'class_id' => $class->id,
            'test_type' => 'midterm',
            'scheduled_at' => '2026-08-28 19:30:00',
            'room' => 'Phòng 101',
        ]);

        $response->assertRedirect(route('syllabus.big-tests.distribution'));
        $this->assertDatabaseHas('big_tests', [
            'title' => 'Kỳ thi Giữa Kỳ Đợt 99',
            'class_id' => $class->id,
            'is_distributed' => false,
            'status' => 'draft',
        ]);

        $testId = BigTest::where('class_id', $class->id)->value('id');
        $this->actingAs($user)->post(route('syllabus.big-tests.approve', $testId))->assertRedirect();
        $this->assertDatabaseHas('big_tests', ['id' => $testId, 'is_distributed' => true, 'status' => 'distributed']);
    }

    public function test_can_view_big_test_results_and_send_zalo_notifications(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $class = ClassModel::create(['code' => 'CL-IE01', 'name' => 'Lớp IELTS Khảo Thí']);
        $student = \App\Models\Student::create([
            'code' => 'HV-999',
            'name' => 'Lê Bảo Nam',
            'phone' => '0988 999 888',
            'parent_phone' => '0988 111 222',
            'current_class_id' => $class->id,
            'status' => 'studying',
        ]);

        $bigTest = BigTest::create([
            'code' => 'BT-TEST-01',
            'title' => 'Kỳ Thi Test Zalo ZNS',
            'class_id' => $class->id,
            'test_type' => 'final',
            'scheduled_at' => '2026-08-30 19:30:00',
            'room' => 'Lab 1',
            'is_distributed' => true,
            'status' => 'distributed',
        ]);

        $result = \App\Models\BigTestResult::create([
            'big_test_id' => $bigTest->id,
            'student_id' => $student->id,
            'listening_score' => 7.0,
            'reading_score' => 6.5,
            'writing_score' => 6.0,
            'speaking_score' => 6.5,
            'overall_score' => 6.5,
            'progress_note' => 'Đạt chuẩn đầu ra',
            'parent_notified' => false,
            'status' => 'approved',
        ]);

        // 1. View results page
        $viewResp = $this->actingAs($user)->get(route('syllabus.big-tests.results', $bigTest->id));
        $viewResp->assertOk();
        $viewResp->assertSee('Lê Bảo Nam');
        $viewResp->assertSee('Duyệt kết quả Big Test &amp; gửi phụ huynh', false);

        // 2. Send single Zalo notification
        $singleSendResp = $this->actingAs($user)->post(route('syllabus.big-tests.send-single-zalo', $result->id));
        $singleSendResp->assertRedirect();
        $result->refresh();
        $this->assertTrue($result->parent_notified);
        $this->assertNotNull($result->notified_at);

        // 3. Batch send all Zalo notifications
        $batchSendResp = $this->actingAs($user)->post(route('syllabus.big-tests.send-zalo', $bigTest->id));
        $batchSendResp->assertRedirect();
    }
}
