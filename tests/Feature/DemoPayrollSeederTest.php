<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\PayrollPeriod;
use App\Models\Penalty;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoPayrollSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Dữ liệu mẫu phần lương (php artisan demo:luong): đủ trạng thái chấm công / đơn / giờ dạy / KPI / bảng lương, chạy lại không nhân bản. */
class DemoPayrollSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_payroll_seed_fills_attendance_kpi_and_payroll_and_is_idempotent(): void
    {
        Storage::fake('local');
        $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoPayrollSeeder::class);

        $month = now()->startOfMonth();
        $last = $month->copy()->subMonthNoOverflow();

        // Cơ sở có toạ độ chấm công; chấm công điện thoại cả tháng trước (ghi thẳng) và tháng này (bấm qua service, có ảnh).
        $this->assertSame(3, Branch::whereIn('code', ['CG', 'BD', 'DD'])->whereNotNull('latitude')->count());
        $this->assertGreaterThan(100, StaffAttendance::whereBetween('work_date', [$last->toDateString(), $month->copy()->subDay()->toDateString()])->count());
        $current = StaffAttendance::where('work_date', '>=', $month->toDateString())->whereNotNull('check_in_photo')->firstOrFail();
        Storage::disk('local')->assertExists($current->check_in_photo);
        $this->assertTrue(StaffAttendance::where('work_date', '>=', $month->toDateString())->where('late_minutes', '>', 0)->where('late_excused', false)->exists());
        $this->assertTrue(StaffAttendance::where('late_excused', true)->where('late_minutes', '>', 0)->exists());
        $this->assertTrue(StaffAttendance::where('source', StaffAttendance::SOURCE_REQUEST)->exists());

        // Đơn xin duyệt đủ trạng thái; biên bản đi muộn tự động đi qua các bước.
        foreach (['pending', 'approved', 'rejected', 'cancelled'] as $status) {
            $this->assertTrue(StaffAttendanceRequest::where('status', $status)->exists(), "Thiếu đơn {$status}.");
        }
        $auto = Penalty::where('notes', 'like', 'Tự động từ chấm công%');
        foreach (['pending', 'explained', 'confirmed', 'fined', 'paid', 'cancelled', 'resolved'] as $status) {
            $this->assertTrue((clone $auto)->where('status', $status)->exists(), "Thiếu biên bản đi muộn {$status}.");
        }

        // Lớp K28: ca hợp lệ, ca chờ đối soát (2 ngày gần nhất), ca ngoài lịch bị từ chối.
        $classIds = ClassModel::whereIn('code', ['DEMO-CG-IF1', 'DEMO-CG-SPK1', 'DEMO-BD-GT1', 'DEMO-DD-GT1', 'DEMO-DD-IE1'])->pluck('id');
        $this->assertCount(5, $classIds);
        foreach (['valid', 'pending_review', 'invalid'] as $status) {
            $this->assertTrue(TeacherTimesheet::whereIn('class_id', $classIds)->where('status', $status)->exists(), "Thiếu ca {$status}.");
        }

        // KPI: bộ mẫu cho GV full-time / TA / Sale; phiếu tháng trước đã duyệt (+1 không duyệt), phiếu tháng này chờ duyệt.
        foreach ([Roles::TEACHER_FULLTIME, Roles::ASSISTANT, Roles::SALES_CONSULTANT] as $role) {
            $this->assertTrue(KpiCriterion::forRole($role)->active()->exists(), "Thiếu tiêu chí KPI {$role}.");
        }
        $lastSheets = KpiEvaluation::where('year', $last->year)->where('month', $last->month);
        $this->assertGreaterThan(10, (clone $lastSheets)->where('status', KpiEvaluation::STATUS_APPROVED)->count());
        $this->assertSame(1, (clone $lastSheets)->where('status', KpiEvaluation::STATUS_REJECTED)->count());
        $this->assertGreaterThan(15, KpiEvaluation::where('year', $month->year)->where('month', $month->month)->where('status', KpiEvaluation::STATUS_PENDING)->count());

        // Bảng lương tháng này đang soát, có phiếu cho nhân sự mới; kỳ tháng trước vẫn khóa, không thêm phiếu.
        $period = PayrollPeriod::where('year', $month->year)->where('month', $month->month)->firstOrFail();
        $this->assertSame('reviewing', $period->status);
        $newTeacher = User::where('email', 'gv.minhduc@menglish.edu.vn')->firstOrFail();
        $this->assertTrue($period->records()->where('user_id', $newTeacher->id)->where('teaching_sessions', '>', 0)->exists());
        $lastPeriod = PayrollPeriod::where('year', $last->year)->where('month', $last->month)->firstOrFail();
        $this->assertSame('approved', $lastPeriod->status);
        $this->assertFalse($lastPeriod->records()->where('user_id', $newTeacher->id)->exists());

        // Chạy lại không nhân bản.
        $counts = [StaffAttendance::count(), StaffAttendanceRequest::count(), TeacherTimesheet::count(), KpiEvaluation::count(), User::count()];
        $this->seed(DemoPayrollSeeder::class);
        $this->assertSame($counts, [StaffAttendance::count(), StaffAttendanceRequest::count(), TeacherTimesheet::count(), KpiEvaluation::count(), User::count()]);
    }

    public function test_command_refuses_production_without_force(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('demo:luong')->assertFailed();
        $this->assertFalse(ClassModel::where('code', 'DEMO-CG-IF1')->exists());
    }
}
