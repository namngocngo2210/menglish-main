<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestOrder;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\ClassReportStudentSupport;
use App\Models\ClassSession;
use App\Models\CourseLevel;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SupportSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\SupportListService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoPhase2Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Dữ liệu demo Phase 2: đủ trạng thái vận hành lớp, đúng quy tắc nghiệp vụ, chạy lại không nhân bản, không gửi Zalo thật. */
class DemoPhase2SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_builds_a_coherent_phase2_dataset_and_is_idempotent(): void
    {
        // Kể cả khi môi trường có cấu hình Zalo live, seeder demo chỉ dùng sandbox.
        config(['services.zalo.mode' => 'live', 'services.zalo.access_token' => 'real-token']);
        Http::fake();
        $this->seed(DatabaseSeeder::class);
        Http::assertNothingSent();
        $this->assertSame('live', config('services.zalo.mode'));

        // Giáo trình → Chặng → Unit → Buổi gắn trình độ.
        $starters = SyllabusCurriculum::where('code', 'DEMO-SYL-STARTERS')->firstOrFail();
        $this->assertSame(3, $starters->stages()->count());
        $this->assertSame(18, $starters->lessons()->count());
        $this->assertSame($starters->id, CourseLevel::where('code', 'DEMO-STARTERS')->value('syllabus_curriculum_id'));

        $classes = ClassModel::where('code', 'like', 'DEMO-%')->get()->keyBy('code');
        foreach (['CG', 'BD'] as $code) {
            foreach (['FAM1', 'FAM0'] as $key) {
                $class = $classes["DEMO-{$code}-{$key}"];
                $this->assertSame(1, SyllabusAssignment::open()->where('class_id', $class->id)->count(), "{$class->code} có đúng 1 chặng mở.");
                $this->assertTrue(StudentAttendance::where('class_id', $class->id)->where('status', 'absent')->exists());
                $this->assertTrue(StudentAttendance::where('class_id', $class->id)->whereColumn('recorded_by', '!=', 'user_id')->exists(), 'Có buổi Học vụ điểm danh thay.');
                $this->assertTrue(SupportSession::where('class_id', $class->id)->where('status', 'completed')->exists());
                $this->assertTrue(BigTest::where('class_id', $class->id)->whereNotNull('syllabus_stage_id')->where('is_distributed', true)->exists());
            }
            $this->assertSame(0, SyllabusAssignment::where('class_id', $classes["DEMO-{$code}-FAM2"]->id)->count(), 'Lớp sắp khai giảng chưa giao chặng.');
            // Ngày nghỉ thêm sau: buổi bị hủy có buổi học bù.
            $cancelled = ClassSession::where('class_id', $classes["DEMO-{$code}-FAM1"]->id)->whereNotNull('holiday_id')->firstOrFail();
            $this->assertSame('cancelled', $cancelled->status);
            $this->assertTrue(ClassSession::where('rescheduled_from_id', $cancelled->id)->where('type', ClassSession::TYPE_MAKEUP)->exists());
        }

        // CG FAM 1: chặng 1 đóng do Big Test đã gửi PH (có HV vắng thi), chặng 2 tự mở; order chặng 2 chờ duyệt.
        $cg1 = $classes['DEMO-CG-FAM1'];
        $closed = SyllabusAssignment::where('class_id', $cg1->id)->where('status', SyllabusAssignment::STATUS_CLOSED)->firstOrFail();
        $this->assertNotNull($closed->closed_by_big_test_id);
        $this->assertSame($closed->stage->next()->id, SyllabusAssignment::open()->where('class_id', $cg1->id)->value('stage_id'));
        $sentTest = BigTest::findOrFail($closed->closed_by_big_test_id);
        $this->assertTrue($sentTest->results()->where('status', 'sent')->exists());
        $this->assertTrue($sentTest->results()->where('is_absent', true)->whereNull('overall_score')->exists());
        $this->assertTrue(BigTestOrder::where('class_id', $cg1->id)->where('status', 'pending')->exists());
        $this->assertNotNull($cg1->foreign_teacher_id);
        // Trạng thái kết quả Big Test: chờ duyệt / đã duyệt / đã gửi.
        foreach (['draft', 'pending_review', 'approved', 'sent'] as $status) {
            $this->assertTrue(BigTestResult::where('status', $status)->exists(), "Thiếu kết quả {$status}.");
        }
        $this->assertTrue(BigTestOrder::where('status', 'rejected')->whereNotNull('rejection_reason')->exists());
        // Duyệt order tự tạo đợt thi (BD FAM 0) kèm link phần Speaking; ngày dự kiến Big Test chặng 2 (CG FAM 1) được nhắc.
        $autoTest = BigTest::where('class_id', $classes['DEMO-BD-FAM0']->id)->firstOrFail();
        $this->assertTrue($autoTest->is_distributed);
        $this->assertNotNull($autoTest->speaking_url);
        $this->assertSame($autoTest->id, (int) BigTestOrder::where('class_id', $classes['DEMO-BD-FAM0']->id)->where('status', 'approved')->value('big_test_id'));
        $this->assertNotNull(SyllabusAssignment::open()->where('class_id', $cg1->id)->value('big_test_reminded_for'));
        $this->assertSame(0, Student::where('code', 'like', 'HV-DEMO-%')->where('status', 'studying')->whereNull('parent_phone')->count(), 'HV demo có SĐT phụ huynh.');
        $this->assertTrue(AdminNotification::where('type', 'big_test_upcoming')->exists(), 'Nhắc lịch Big Test 7 ngày.');
        foreach ([SupportListService::SOURCE_ATTENDANCE, SupportListService::SOURCE_MINI_TEST, SupportListService::SOURCE_BIG_TEST] as $source) {
            $this->assertTrue(ClassReportStudentSupport::where('source', $source)->exists(), "Danh sách bổ trợ thiếu nguồn {$source}.");
        }
        $this->assertTrue(WorkTask::whereNotNull('care_milestone')->exists(), 'Có việc chăm sóc tháng đầu.');
        $this->assertTrue(WorkTask::where('description', 'like', '%Demo Phase 2%')->where('status', 'completed')->exists());
        $this->assertTrue(WorkTask::where('description', 'like', '%Demo Phase 2%')->where('status', 'pending_confirmation')->exists());
        $this->assertTrue(AdminNotification::where('type', 'student_birthday')->exists());

        // Chạy lại không tạo trùng.
        $tables = ['syllabus_curriculums', 'syllabus_stages', 'syllabus_units', 'syllabus_lessons', 'syllabus_assignments', 'student_attendances',
            'academic_records', 'mini_test_scores', 'homeworks', 'class_report_student_supports', 'support_sessions', 'big_test_orders', 'big_tests',
            'big_test_results', 'class_sessions', 'holidays', 'work_tasks', 'admin_notifications', 'teacher_timesheets', 'students', 'users'];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]);
        $this->seed(DemoPhase2Seeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before->all(), collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all());

        // Màn hình Phase 2 mở được bằng tài khoản demo.
        $teacher = User::where('email', 'nguyenvanan@menglish.edu.vn')->firstOrFail();
        $academic = User::where('email', 'nva@menglish.edu.vn')->firstOrFail();
        $lead = User::where('email', 'academiclead@menglish.edu.vn')->firstOrFail();
        $assistant = User::where('email', 'ta.tuan@menglish.edu.vn')->firstOrFail();
        $studentUser = User::where('email', 'hocvien1@menglish.edu.vn')->firstOrFail();
        $this->actingAs($teacher)->get(route('teacher.home'))->assertOk();
        $this->actingAs($teacher)->get(route('teacher.attendance', $cg1->id))->assertOk();
        $this->actingAs($teacher)->get(route('syllabus.teacher-view', ['class' => $cg1->id]))->assertOk();
        $this->actingAs($teacher)->get(route('syllabus.big-tests.results', $sentTest->id))->assertOk();
        $this->actingAs($teacher)->get(route('syllabus.big-tests.results', BigTest::where('class_id', $classes['DEMO-BD-FAM1']->id)->value('id')))->assertNotFound();
        $this->actingAs($academic)->get(route('tasks.support-sessions'))->assertOk();
        $this->actingAs($academic)->get(route('tasks.classes-dashboard'))->assertOk();
        $this->actingAs($lead)->get(route('syllabus.assignments'))->assertOk();
        $this->actingAs($lead)->get(route('syllabus.big-tests.distribution'))->assertOk();
        $this->actingAs($lead)->get(route('syllabus.builder', ['curriculum' => $starters->id]))->assertOk();
        $this->actingAs($assistant)->get(route('portal.ta-tasks'))->assertOk();
        $this->actingAs($studentUser)->get(route('portal.student.home'))->assertOk()
            ->assertViewHas('student', fn (Student $s) => $s->code === 'HV-DEMO-CG-01')
            ->assertViewHas('bigTestResults', fn ($results) => $results->isNotEmpty())
            ->assertViewHas('attendanceHistory', fn ($rows) => $rows->isNotEmpty());
    }
}
