<?php

namespace Tests\Feature;

use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\CrmTrialBooking;
use App\Models\Holiday;
use App\Models\PlacementTestSubmission;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoPhase1Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Dữ liệu demo Phase 1: đủ các bước pipeline theo chi nhánh, đúng quy tắc nghiệp vụ, chạy lại không nhân bản. */
class DemoPhase1SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_builds_a_coherent_phase1_dataset_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);

        $demo = CrmCustomer::query()->where('phone', 'like', '039%')->with('branch')->get();
        foreach (['CG', 'BD'] as $code) {
            $stages = $demo->filter(fn (CrmCustomer $c) => $c->branch->code === $code)->pluck('stage')->unique();
            foreach ([...array_keys(CrmCustomer::PIPELINE_STAGES), CrmCustomer::STAGE_LOST] as $stage) {
                $this->assertContains($stage, $stages, "Chi nhánh {$code} thiếu khách ở bước {$stage}.");
            }
        }
        $this->assertTrue($demo->where('stage', 'lost')->every(fn (CrmCustomer $c) => filled($c->lost_reason) && $c->lost_at));
        $this->assertTrue($demo->contains(fn (CrmCustomer $c) => $c->followUpStatus() === 'overdue'));
        $this->assertTrue($demo->contains(fn (CrmCustomer $c) => $c->followUpStatus() === 'due_soon'));

        // Chốt: có hồ sơ học viên + học phí; Chờ xếp lớp chưa có lớp; chưa đóng phí → task nhắc thu.
        foreach ($demo->whereIn('stage', CrmCustomer::CLOSED_STAGES) as $closed) {
            $student = Student::findOrFail($closed->converted_student_id);
            $this->assertNotNull($student->user_id);
            $this->assertTrue(StudentTuition::where('student_id', $student->id)->exists());
            $this->assertSame($closed->stage === 'won', $student->current_class_id !== null);
        }
        $this->assertTrue(WorkTask::where('title', 'like', 'Nhắc thu học phí%')->exists());
        $this->assertTrue(ClassEnrollment::whereNotNull('customer_id')->whereNull('confirmed_at')->exists(), 'Có học viên chờ xác nhận chính thức.');
        $this->assertTrue(ClassEnrollment::whereNotNull('customer_id')->whereNotNull('confirmed_at')->exists());

        // Test đầu vào chấm theo thang khối lớp + bài chờ chấm; học thử có nhận xét GV lưu theo khách.
        $this->assertTrue(PlacementTestSubmission::where('status', PlacementTestSubmission::STATUS_PENDING)->whereNotNull('customer_id')->exists());
        $this->assertGreaterThanOrEqual(4, PlacementTestSubmission::whereNotNull('total_score')->distinct()->count('grade_group'));
        $this->assertTrue(CrmTrialBooking::where('status', 'attended')->whereNotNull('remarks')->whereNotNull('feedback_by')->exists());
        $this->assertTrue(CrmTrialBooking::where('status', 'scheduled')->exists());
        $this->assertTrue(CrmCustomerHistory::where('type', 'stage_change')->whereIn('customer_id', $demo->pluck('id'))->exists());

        // Lớp đang học + sắp khai giảng, buổi học bỏ ngày nghỉ chi nhánh, sĩ số không vượt tối đa.
        foreach (ClassModel::where('code', 'like', 'DEMO-%')->get() as $class) {
            $this->assertTrue($class->sessions()->exists());
            $this->assertLessThanOrEqual($class->max_capacity, $class->occupiedSeats());
            $holiday = Holiday::where('code', 'HOL-DEMO-'.explode('-', $class->code)[1])->firstOrFail();
            $this->assertFalse($class->sessions()->whereDate('date', $holiday->start_date)->exists());
        }
        $this->assertTrue(ClassModel::where('code', 'like', 'DEMO-%')->where('status', 'upcoming')->exists());

        // Chạy lại không tạo trùng.
        $tables = ['crm_customers', 'crm_customer_histories', 'crm_trial_bookings', 'placement_test_submissions', 'classes', 'class_sessions',
            'students', 'class_enrollments', 'student_tuitions', 'tuition_receipts', 'work_tasks', 'holidays', 'users'];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]);
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoPhase1Seeder::class);
        $this->assertSame($before->all(), collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all());

        // Màn hình Phase 1 mở được trên dữ liệu demo bằng tài khoản demo.
        $manager = User::where('email', 'manager@menglish.edu.vn')->firstOrFail();
        $academic = User::where('email', 'nva@menglish.edu.vn')->firstOrFail();
        $teacher = User::where('email', 'nguyenvanan@menglish.edu.vn')->firstOrFail();
        $resultSent = $demo->first(fn (CrmCustomer $c) => $c->stage === 'result_sent' && $c->branch->code === 'CG');
        $this->actingAs($manager)->get(route('crm.pipeline'))->assertOk();
        $this->actingAs($manager)->get(route('crm.customers.show', $resultSent->id))->assertOk();
        $this->actingAs($manager)->get(route('crm.closing-wizard', ['customer_id' => $resultSent->id]))->assertOk();
        $this->actingAs($manager)->get(route('crm.customers.won'))->assertOk();
        $this->actingAs($manager)->get(route('crm.lost-deals'))->assertOk();
        $this->actingAs($academic)->get(route('crm.waiting-list'))->assertOk();
        $this->actingAs($academic)->get(route('crm.confirmations'))->assertOk();
        $this->actingAs($teacher)->get(route('teacher.trial-guests', ['scope' => 'past']))->assertOk();
        $this->actingAs($manager)->get(route('crm.customers.show', $demo->first(fn (CrmCustomer $c) => $c->branch->code === 'BD')->id))->assertNotFound();
    }
}
