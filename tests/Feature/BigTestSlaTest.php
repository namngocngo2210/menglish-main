<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Penalty;
use App\Models\Student;
use App\Models\User;
use App\Services\SyllabusProgressionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SLA Big Test: trả kết quả trong 7 ngày (phạt 50.000đ/ngày trễ), HT duyệt đề trước 3 ngày (nhắc hằng ngày từ 7 ngày,
 * việc duyệt đề tự tạo, biên bản khi GV chưa nhận đề trước 24h).
 */
class BigTestSlaTest extends TestCase
{
    use RefreshDatabase;

    private User $academic;

    private User $teacher;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'Cơ sở SLA', 'code' => 'SLA1', 'is_active' => true]);
        $this->academic = User::factory()->create(['is_active' => true]);
        $this->academic->assignRole('academic_lead');
        $this->teacher = User::factory()->create(['is_active' => true]);
        $this->teacher->assignRole('teacher');
        $this->class = ClassModel::create(['code' => 'SLA-A', 'name' => 'Lớp SLA A', 'branch_id' => $branch->id, 'teacher_id' => $this->teacher->id, 'status' => 'active']);
    }

    private function makeTest($when, bool $distributed = true, array $extra = []): BigTest
    {
        static $n = 0;
        $n++;

        return BigTest::create($extra + [
            'code' => 'BTS-'.$n, 'title' => 'Big Test SLA '.$n, 'class_id' => $this->class->id, 'test_type' => 'stage_end',
            'scheduled_at' => $when, 'room' => 'Lab', 'passcode' => 'P'.$n,
            'is_distributed' => $distributed, 'status' => $distributed ? 'distributed' : 'draft',
        ]);
    }

    private function student(string $code): Student
    {
        return Student::create(['code' => $code, 'name' => 'HV '.$code, 'phone' => '09'.random_int(10000000, 99999999), 'current_class_id' => $this->class->id, 'status' => 'studying']);
    }

    private function makeResult(BigTest $test, Student $student, string $status): BigTestResult
    {
        return BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $student->id, 'overall_score' => 7, 'status' => $status]);
    }

    // ---- 1. Trả kết quả trễ hạn ----

    public function test_results_completed_is_set_even_without_stage(): void
    {
        $test = $this->makeTest(now()->subDays(2));
        $this->assertNull($test->syllabus_stage_id);
        $this->makeResult($test, $this->student('SLA-S1'), 'sent');

        app(SyllabusProgressionService::class)->syncBigTest($test->fresh());

        $this->assertNotNull($test->fresh()->results_completed_at);
    }

    public function test_late_results_create_one_pending_penalty_updated_daily(): void
    {
        $test = $this->makeTest(now()->subDays(10)->setTime(9, 0));
        $this->student('SLA-S2'); // chưa có kết quả → GV chính chịu trách nhiệm

        $this->artisan('bigtests:enforce-sla')->assertSuccessful();
        $this->artisan('bigtests:enforce-sla')->assertSuccessful();

        $penalty = Penalty::where('big_test_id', $test->id)->sole();
        $this->assertSame('pending', $penalty->status);
        $this->assertNull($penalty->reporter_id);
        $this->assertSame('academic', $penalty->error_category);
        $this->assertSame($this->teacher->id, $penalty->user_id);
        $this->assertSame('Trả kết quả Big Test trễ 3 ngày (hạn 7 ngày)', $penalty->violation_type);
        $this->assertEquals(150000, $penalty->amount);
        $this->assertSame(1, AdminNotification::where('user_id', $this->teacher->id)->where('type', 'penalty_created')->count());

        // Hôm sau: cập nhật số ngày + mức gợi ý trên cùng biên bản.
        $this->travel(1)->days();
        $this->artisan('bigtests:enforce-sla')->assertSuccessful();
        $penalty->refresh();
        $this->assertSame(1, Penalty::where('big_test_id', $test->id)->count());
        $this->assertSame('Trả kết quả Big Test trễ 4 ngày (hạn 7 ngày)', $penalty->violation_type);
        $this->assertEquals(200000, $penalty->amount);
    }

    public function test_no_penalty_within_deadline_or_after_completion(): void
    {
        $onTime = $this->makeTest(now()->subDays(7)->setTime(9, 0)); // đúng hạn (hôm nay là hạn chót)
        $done = $this->makeTest(now()->subDays(12), true, ['results_completed_at' => now()->subDays(1)]);

        $this->artisan('bigtests:enforce-sla')->assertSuccessful();

        $this->assertSame(0, Penalty::whereIn('big_test_id', [$onTime->id, $done->id])->count());
    }

    public function test_penalty_stops_updating_once_decided_and_when_results_completed(): void
    {
        $test = $this->makeTest(now()->subDays(9)->setTime(9, 0));
        $this->artisan('bigtests:enforce-sla');
        $penalty = Penalty::where('big_test_id', $test->id)->sole();
        $this->assertEquals(100000, $penalty->amount);

        // Đã trả đủ kết quả → dừng cập nhật.
        $test->update(['results_completed_at' => now()]);
        $this->travel(2)->days();
        $this->artisan('bigtests:enforce-sla');
        $this->assertEquals(100000, $penalty->fresh()->amount);

        // Biên bản đã chốt thì không bị ghi đè dù (giả sử) vẫn trễ.
        $test->update(['results_completed_at' => null]);
        $penalty->update(['status' => 'fined', 'amount' => 80000]);
        $this->artisan('bigtests:enforce-sla');
        $this->assertEquals(80000, $penalty->fresh()->amount);
        $this->assertSame(1, Penalty::where('big_test_id', $test->id)->count());
    }

    public function test_responsibility_moves_to_approver_once_all_results_entered(): void
    {
        $test = $this->makeTest(now()->subDays(9)->setTime(9, 0));
        $s1 = $this->student('SLA-S3');
        $this->makeResult($test, $s1, 'draft');
        $this->artisan('bigtests:enforce-sla');
        $this->assertSame($this->teacher->id, Penalty::where('big_test_id', $test->id)->sole()->user_id);

        // GV nhập đủ (chờ duyệt) → người duyệt Big Test (HT) chịu trách nhiệm; Admin không bị lập biên bản.
        BigTestResult::where('big_test_id', $test->id)->update(['status' => 'pending_review']);
        $this->artisan('bigtests:enforce-sla');
        $this->assertSame($this->academic->id, Penalty::where('big_test_id', $test->id)->sole()->user_id);
    }

    // ---- 4. HT nhắc duyệt đề hằng ngày ----

    public function test_academic_gets_daily_personal_paper_reminders_with_escalation(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $early = $this->makeTest(now()->addDays(6)->setTime(9, 0), false);
        $late = $this->makeTest(now()->addDays(2)->setTime(9, 0), false);
        $this->makeTest(now()->addDays(10)->setTime(9, 0), false); // ngoài 7 ngày
        $this->makeTest(now()->addDays(2)->setTime(9, 0), true); // đã phân phối

        $this->artisan('bigtests:remind-upcoming')->assertSuccessful();
        $this->artisan('bigtests:remind-upcoming')->assertSuccessful();

        $mine = AdminNotification::where('user_id', $this->academic->id)->where('type', 'big_test_paper_due')->get();
        $this->assertCount(2, $mine, 'Idempotent trong ngày.');
        $this->assertStringContainsString('còn 6 ngày', $mine->firstWhere('data.big_test_id', $early->id)->title);
        $this->assertStringContainsString('Quá hạn duyệt đề (trước 3 ngày)', $mine->firstWhere('data.big_test_id', $late->id)->title);
        $this->assertSame(0, AdminNotification::where('user_id', $admin->id)->where('type', 'big_test_paper_due')->count());

        // Hôm sau nhắc lại.
        $this->travel(1)->days();
        $this->artisan('bigtests:remind-upcoming')->assertSuccessful();
        $this->assertSame(4, AdminNotification::where('user_id', $this->academic->id)->where('type', 'big_test_paper_due')->count());
    }

    // ---- 5. Việc duyệt đề cho Học thuật ----

    public function test_approval_task_created_once_and_closed_when_distributed(): void
    {
        $test = $this->makeTest(now()->addDays(7)->setTime(9, 0), false);
        $far = $this->makeTest(now()->addDays(12), false);

        $this->artisan('bigtests:enforce-sla')->assertSuccessful();
        $this->artisan('bigtests:enforce-sla')->assertSuccessful();

        $task = \App\Models\WorkTask::where('big_test_id', $test->id)->sole();
        $this->assertSame($this->academic->id, $task->assignee_id);
        $this->assertNotNull($task->creator_id);
        $this->assertSame($test->scheduled_at->toDateString(), $task->due_date->toDateString());
        $this->assertSame('new', $task->status);
        $this->assertSame(0, \App\Models\WorkTask::where('big_test_id', $far->id)->count());

        $this->actingAs($this->academic)->post(route('syllabus.big-tests.approve', $test->id))->assertRedirect();
        $this->assertSame('completed', $task->fresh()->status);

        $this->artisan('bigtests:enforce-sla')->assertSuccessful();
        $this->assertSame(1, \App\Models\WorkTask::where('big_test_id', $test->id)->count());
    }

    // ---- 6. GV chưa nhận đề trước 24h ----

    public function test_missing_paper_records_pending_penalty_per_academic_user_once(): void
    {
        $second = User::factory()->create(['is_active' => true]);
        $second->assignRole('academic_lead');
        $soon = $this->makeTest(now()->addHours(20), false);
        $later = $this->makeTest(now()->addHours(30), false);
        $given = $this->makeTest(now()->addHours(10), true);

        $this->artisan('bigtests:enforce-sla', ['--paper-only' => true])->assertSuccessful();
        $this->artisan('bigtests:enforce-sla', ['--paper-only' => true])->assertSuccessful();

        $penalties = Penalty::where('big_test_id', $soon->id)->get();
        $this->assertCount(2, $penalties);
        $this->assertEqualsCanonicalizing([$this->academic->id, $second->id], $penalties->pluck('user_id')->all());
        $penalties->each(function (Penalty $p) {
            $this->assertSame('pending', $p->status);
            $this->assertNull($p->reporter_id);
            $this->assertEquals(0, $p->amount);
            $this->assertSame('academic', $p->error_category);
        });
        $this->assertSame(0, Penalty::whereIn('big_test_id', [$later->id, $given->id])->count());

        // Qua mốc 24h của đợt thi sau → lập thêm cho đợt đó.
        $this->travel(7)->hours();
        $this->artisan('bigtests:enforce-sla', ['--paper-only' => true])->assertSuccessful();
        $this->assertSame(2, Penalty::where('big_test_id', $later->id)->count());
        $this->assertSame(2, Penalty::where('big_test_id', $soon->id)->count());
    }
}
