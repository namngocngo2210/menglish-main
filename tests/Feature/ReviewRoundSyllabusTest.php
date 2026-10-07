<?php

namespace Tests\Feature;

use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use App\Services\SyllabusProgressionService;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rà soát đợt 3-4 (Syllabus): chặng chỉ đóng khi cả lớp có kết quả Big Test, "Lưu nháp" không kéo dòng chờ duyệt
 * về nháp, duyệt giãn tiến độ cộng đúng chặng của yêu cầu.
 */
class ReviewRoundSyllabusTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $teacher;

    private ClassModel $class;

    private Student $s1;

    private Student $s2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'Cơ sở S', 'code' => 'CSS', 'is_active' => true]);
        $this->lead = User::factory()->create(['is_active' => true]);
        $this->lead->assignRole('academic_lead');
        $this->teacher = User::factory()->create(['is_active' => true]);
        $this->teacher->assignRole('teacher');
        $this->class = ClassModel::create(['code' => 'RS-1', 'name' => 'Lớp RS', 'branch_id' => $branch->id, 'teacher_id' => $this->teacher->id, 'status' => 'active']);
        $this->s1 = $this->student('HV-RS1');
        $this->s2 = $this->student('HV-RS2');
    }

    public function test_stage_is_not_complete_until_whole_class_has_results(): void
    {
        $test = $this->bigTest();
        BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $this->s1->id, 'status' => 'sent', 'overall_score' => 8]);

        $progression = app(SyllabusProgressionService::class);
        $this->assertFalse($progression->bigTestCompleted($test));

        BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $this->s2->id, 'status' => 'approved', 'is_absent' => true]);
        $this->assertTrue($progression->bigTestCompleted($test->fresh()));
    }

    public function test_saving_draft_keeps_rows_already_sent_for_review(): void
    {
        $test = $this->bigTest();
        $scores = ['listening_score' => 7, 'reading_score' => 7, 'writing_score' => 7, 'speaking_score' => 7];
        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.results.store', $test->id), [
            'action' => 'submit', 'results' => [['student_id' => $this->s1->id] + $scores],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.results.store', $test->id), [
            'action' => 'draft', 'results' => [['student_id' => $this->s1->id] + $scores, ['student_id' => $this->s2->id, 'listening_score' => 6]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('pending_review', BigTestResult::where('student_id', $this->s1->id)->value('status'));
        $this->assertSame('draft', BigTestResult::where('student_id', $this->s2->id)->value('status'));
    }

    public function test_adjustment_for_closed_stage_is_not_credited_to_next_stage(): void
    {
        $curriculum = SyllabusCurriculum::create(['code' => 'CUR-RS', 'title' => 'GT RS', 'version' => 'v1', 'stage_name' => 'Chặng A']);
        $progression = app(SyllabusProgressionService::class);
        $first = $progression->open($this->class, $curriculum->stages()->firstOrFail(), $this->teacher->id, $this->lead);
        $req = SyllabusAdjustmentRequest::create([
            'class_id' => $this->class->id, 'syllabus_assignment_id' => $first->id, 'user_id' => $this->teacher->id,
            'request_type' => 'Giãn', 'reason' => 'Chậm', 'extra_sessions' => 2, 'status' => 'pending',
        ]);
        $progression->close($first, $this->lead, 'Đóng thử', null, false);
        // Chỉ Admin duyệt giãn tiến độ (06/10/2026).
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);

        $this->actingAs($admin)->post(route('syllabus.adjustment-requests.approve', $req->id), ['extra_sessions' => 2])
            ->assertSessionHasErrors('extra_sessions');
        $this->assertSame('pending', $req->fresh()->status);

        $this->actingAs($admin)->post(route('syllabus.adjustment-requests.approve', $req->id), ['extra_sessions' => 0])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, (int) $req->fresh()->extra_sessions);
    }

    private function bigTest(): BigTest
    {
        return BigTest::create([
            'code' => 'BT-RS', 'title' => 'Big Test RS', 'class_id' => $this->class->id, 'test_type' => 'midterm',
            'scheduled_at' => now()->subDay(), 'room' => 'Lab', 'passcode' => 'P-RS', 'is_distributed' => true, 'status' => 'distributed',
        ]);
    }

    private function student(string $code): Student
    {
        $student = Student::create(['code' => $code, 'name' => 'HV '.$code, 'phone' => '0900000000', 'branch_id' => $this->class->branch_id, 'status' => 'studying', 'current_class_id' => $this->class->id]);
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $this->class->id, 'status' => 'completed']);

        return $student;
    }
}
