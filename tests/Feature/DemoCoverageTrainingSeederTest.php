<?php

namespace Tests\Feature;

use App\Models\AcademicProject;
use App\Models\AcademicProjectUpdate;
use App\Models\AcademicRecord;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SupportSession;
use App\Models\Survey;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusDocument;
use App\Models\SyllabusDocumentView;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCoverageTrainingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Dữ liệu demo Đào tạo "toàn case": đủ trạng thái lớp / học viên / giáo trình / khảo sát / dự án, chạy lại không nhân bản. */
class DemoCoverageTrainingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_every_training_case_and_is_idempotent(): void
    {
        Storage::fake('local');
        $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoCoverageTrainingSeeder::class);

        // Lớp: đã kết thúc (đủ buổi đã dạy + điểm danh), đã hủy, chờ xếp lịch.
        $k24 = ClassModel::where('code', DemoCoverageTrainingSeeder::MARKER)->firstOrFail();
        $this->assertSame('completed', $k24->status);
        $this->assertTrue($k24->end_date->lt(today()));
        $regular = $k24->sessions()->where('type', ClassSession::TYPE_REGULAR);
        $this->assertGreaterThanOrEqual(20, (clone $regular)->count());
        $this->assertSame(0, (clone $regular)->where('status', '!=', 'completed')->count(), 'Mọi buổi chính khóa đã dạy.');
        $this->assertSame(0, (clone $regular)->whereDoesntHave('attendances')->count(), 'Mọi buổi đều có điểm danh.');
        $this->assertSame(2, (clone $regular)->where('notes', 'like', 'Giãn tiến độ%')->count());
        foreach (['present', 'late', 'absent', 'excused'] as $status) {
            $this->assertTrue(StudentAttendance::where('class_id', $k24->id)->where('status', $status)->exists(), "Thiếu điểm danh {$status}.");
        }
        $this->assertSame(0, StudentAttendance::where('class_id', $k24->id)->where('review_status', '!=', 'approved')->count());
        $this->assertSame(3, SyllabusAssignment::where('class_id', $k24->id)->where('status', SyllabusAssignment::STATUS_CLOSED)->count());
        $this->assertTrue(SyllabusAssignment::where('class_id', $k24->id)->whereNotNull('curriculum_completed_at')->exists());
        $this->assertSame('cancelled', ClassModel::where('code', DemoCoverageTrainingSeeder::CANCELLED_CLASS)->value('status'));
        $pending = ClassModel::where('code', DemoCoverageTrainingSeeder::PENDING_CLASS)->firstOrFail();
        $this->assertSame('pending_schedule', $pending->status);
        $this->assertSame(0, $pending->sessions()->count());

        // Học viên: đủ trạng thái; thôi học → lượt xếp lớp dropped; chuyển lớp → lượt cũ có ngày rời lớp + lượt ở lớp mới.
        $students = Student::where('code', 'like', 'HV-DEMO-K2%')->get()->keyBy('code');
        $this->assertCount(11, $students);
        foreach (['waiting_start', 'summer_break', 'completed', 'dropped'] as $status) {
            $this->assertTrue($students->contains('status', $status), "Thiếu học viên {$status}.");
        }
        $this->assertSame(4, $students->where('current_class_id', $k24->id)->where('status', 'completed')->count());
        $this->assertSame(2, ClassEnrollment::whereIn('student_id', $students->where('status', 'dropped')->pluck('id'))->where('status', Student::ENROLLMENT_DROPPED)->whereNotNull('left_at')->count());
        $moved = $students['HV-DEMO-K29BD-01'];
        $this->assertSame(ClassModel::where('code', 'DEMO-BD-FAM2')->value('id'), $moved->current_class_id);
        $this->assertSame(2, ClassEnrollment::where('student_id', $moved->id)->count());
        $this->assertNotNull(ClassEnrollment::where('student_id', $moved->id)->where('class_id', ClassModel::where('code', DemoCoverageTrainingSeeder::CANCELLED_CLASS)->value('id'))->value('left_at'));
        $this->assertSame(['completed', 'completed', 'pending'], ClassEnrollment::where('class_id', $pending->id)->orderBy('id')->pluck('status')->all());

        // Dạy thay + bổ trợ bị hủy.
        $this->assertEqualsCanonicalizing(['approved', 'rejected', 'pending'], DB::table('substitute_sessions')->pluck('status')->all());
        $approved = DB::table('substitute_sessions')->where('status', 'approved')->first();
        $this->assertTrue(ClassSession::where('class_id', $k24->id)->whereDate('date', $approved->session_date)->where('teacher_id', $approved->substitute_teacher_id)->exists());
        $support = SupportSession::where('class_id', $k24->id)->firstOrFail();
        $this->assertSame('cancelled', $support->status);
        $this->assertSame('cancelled', $support->classSession->status);

        // Giáo trình: tài liệu + lượt xem, đề xuất và giãn tiến độ đủ trạng thái (có quá hạn SLA đã báo).
        $this->assertSame(6, SyllabusDocument::count());
        $this->assertTrue(SyllabusDocument::where('visible_to_teachers', false)->exists());
        Storage::disk('local')->assertExists(SyllabusDocument::first()->file_path);
        $this->assertGreaterThanOrEqual(5, SyllabusDocumentView::count());
        foreach (['pending', 'approved', 'rejected'] as $status) {
            $this->assertTrue(SyllabusChangeProposal::where('status', $status)->exists(), "Thiếu đề xuất {$status}.");
            $this->assertTrue(SyllabusAdjustmentRequest::where('status', $status)->exists(), "Thiếu giãn tiến độ {$status}.");
        }
        $this->assertTrue(SyllabusChangeProposal::whereNotNull('attachment_path')->exists());
        $overdue = SyllabusAdjustmentRequest::where('status', 'pending')->get()->filter->isSlaOverdue();
        $this->assertCount(1, $overdue);
        $this->assertNotNull($overdue->first()->sla_notified_at);
        $this->assertSame(2, SyllabusAdjustmentRequest::where('class_id', $k24->id)->value('extra_sessions'));

        // Khảo sát: đã đóng / đang mở, phản hồi có điểm thấp.
        $this->assertTrue(Survey::where('is_active', false)->exists());
        $this->assertTrue(Survey::where('is_active', true)->whereDate('deadline', '>', today())->exists());
        $responses = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/06_khao_sat')->get();
        $this->assertGreaterThanOrEqual(10, $responses->count());
        $this->assertTrue($responses->contains(fn ($r) => $r->data['rating'] <= 2));
        $this->assertTrue($responses->contains(fn ($r) => (int) $r->user_id === User::where('email', 'hocvien1@menglish.edu.vn')->value('id')));

        // Dự án học thuật mới: hoàn thành / tạm dừng / đã hủy, có cập nhật + phản hồi.
        foreach (['completed', 'paused', 'cancelled'] as $status) {
            $this->assertTrue(AcademicProject::where('name', 'like', '# %')->where('status', $status)->exists(), "Thiếu dự án {$status}.");
        }
        $this->assertTrue(AcademicProjectUpdate::whereNotNull('difficulties')->whereNotNull('response')->exists());
        $this->assertGreaterThan(0, AcademicProject::where('status', 'paused')->firstOrFail()->milestones()->where('status', 'done')->count());

        // Không có sự kiện nào sau "bây giờ".
        $this->assertFalse(ClassEnrollment::where('created_at', '>', now())->exists());
        $this->assertFalse(AcademicProjectUpdate::where('created_at', '>', now())->exists());

        // Chạy lại: bỏ qua, không nhân bản.
        $counts = fn () => [
            ClassModel::count(), Student::count(), ClassSession::count(), StudentAttendance::count(), DB::table('substitute_sessions')->count(),
            SyllabusDocument::count(), SyllabusChangeProposal::count(), SyllabusAdjustmentRequest::count(), Survey::count(),
            AcademicRecord::count(), AcademicProject::count(), AcademicProjectUpdate::count(),
        ];
        $before = $counts();
        $this->seed(DemoCoverageTrainingSeeder::class);
        $this->assertSame($before, $counts());
    }
}
