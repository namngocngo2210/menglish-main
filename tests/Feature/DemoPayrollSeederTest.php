<?php

namespace Tests\Feature;

use App\Models\AcademicProject;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\MaterialOrder;
use App\Models\PayrollPeriod;
use App\Models\Penalty;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Kpi\KpiSheetService;
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

        // KPI Học thuật: nguồn tự đếm có số liệu (mốc dự án, việc giao, order học liệu); phiếu tháng trước chốt có xếp loại,
        // phiếu tháng này có mục Không phát sinh, order giao sau giờ dùng chặn mục ở 50%.
        $lead = User::where('email', 'academiclead@menglish.edu.vn')->firstOrFail();
        $this->assertSame(2, AcademicProject::where('owner_id', $lead->id)->count());
        $this->assertTrue(WorkTask::where('assignee_id', $lead->id)->where('status', 'completed')->exists());
        $this->assertTrue(MaterialOrder::where('category', MaterialOrder::CATEGORY_ACADEMIC)->where('created_late', true)->exists());
        $sheets = app(KpiSheetService::class);
        $leadLast = KpiEvaluation::where('user_id', $lead->id)->where('year', $last->year)->where('month', $last->month)->firstOrFail();
        $this->assertSame(KpiEvaluation::STATUS_APPROVED, $leadLast->status);
        $lastSheet = $sheets->sheet($lead, $last->month, $last->year, $leadLast);
        $this->assertNotNull($lastSheet['grade']);
        foreach (['academic_deliverable_on_time', 'task_on_time', 'academic_order_on_time'] as $source) {
            $line = $lastSheet['lines']->first(fn ($l) => $l['criterion']->auto_source === $source);
            $this->assertTrue($line['auto'] && $line['value'] !== null, "Thiếu số liệu {$source} tháng trước.");
        }
        $currentSheet = $sheets->sheet($lead, $month->month, $month->year, KpiSheetService::evaluationFor($lead, $month->month, $month->year));
        $this->assertTrue($currentSheet['lines']->contains(fn ($l) => $l['na']));
        $this->assertTrue($currentSheet['lines']->contains(fn ($l) => $l['cap']));

        // Bảng lương tháng này đang soát, có phiếu cho nhân sự mới; kỳ tháng trước vẫn khóa, không thêm phiếu.
        $period = PayrollPeriod::where('year', $month->year)->where('month', $month->month)->firstOrFail();
        $this->assertSame('reviewing', $period->status);
        $newTeacher = User::where('email', 'gv.minhduc@menglish.edu.vn')->firstOrFail();
        $this->assertTrue($period->records()->where('user_id', $newTeacher->id)->where('teaching_sessions', '>', 0)->exists());
        // Kỳ tháng trước: đã duyệt, từ ngày 10 (lịch trả lương) đã chi trả; có phiếu cho mọi vai trò nhân sự (lớp K27 cho GV / TA
        // chưa có lịch dạy), không có phiếu của người vào làm từ tháng này.
        $lastPeriod = PayrollPeriod::where('year', $last->year)->where('month', $last->month)->firstOrFail();
        $this->assertSame('paid', $lastPeriod->status);
        $this->assertFalse($lastPeriod->records()->where('user_id', $newTeacher->id)->exists());
        $staffRoles = [Roles::MANAGER, Roles::ACADEMIC_LEAD, Roles::ACADEMIC_STAFF, Roles::SALES_CONSULTANT, Roles::TEACHER_FULLTIME, Roles::TEACHER_PARTTIME, Roles::ASSISTANT];
        foreach ([$lastPeriod, $period] as $p) {
            foreach ($staffRoles as $role) {
                $this->assertTrue($p->records()->whereHas('user.roles', fn ($q) => $q->where('name', $role))->exists(), "Kỳ {$p->code} thiếu phiếu {$role}.");
            }
        }
        foreach (['manager@menglish.edu.vn', 'gv.native1@menglish.edu.vn', 'ta.linh@menglish.edu.vn'] as $email) {
            $this->assertTrue($lastPeriod->records()->whereHas('user', fn ($q) => $q->where('email', $email))->where('net_salary', '>', 0)->exists(), "Thiếu phiếu tháng trước của {$email}.");
        }
        $this->assertSame(0, $lastPeriod->records()->whereNot('status', 'paid')->count());

        // Biên bản lập tay (có bằng chứng) cho mọi vai trò, đủ các bước; biên bản quá hạn nộp tháng trước đã trừ lương rồi khắc phục.
        $manual = Penalty::whereNotNull('evidence_path');
        foreach ($staffRoles as $role) {
            $this->assertTrue((clone $manual)->whereHas('user.roles', fn ($q) => $q->where('name', $role))->exists(), "Thiếu biên bản {$role}.");
        }
        foreach (['pending', 'explained', 'confirmed', 'fined', 'paid', 'deducted', 'resolved', 'cancelled'] as $status) {
            $this->assertTrue((clone $manual)->where('status', $status)->exists(), "Thiếu biên bản lập tay {$status}.");
        }
        $this->assertTrue((clone $manual)->where('status', 'deducted')->whereNotNull('remedied_at')
            ->whereIn('payroll_record_id', $lastPeriod->records()->select('id'))->exists());

        // KPI mọi vai trò (có bộ mẫu Quản lý cơ sở), mỗi vai trò có người tốt / người kém.
        foreach ($staffRoles as $role) {
            $this->assertTrue(KpiEvaluation::where('year', $last->year)->where('month', $last->month)->where('status', KpiEvaluation::STATUS_APPROVED)
                ->whereHas('user.roles', fn ($q) => $q->where('name', $role))->exists(), "Thiếu phiếu KPI tháng trước {$role}.");
        }
        $score = fn (string $email) => (float) KpiEvaluation::where('year', $last->year)->where('month', $last->month)
            ->whereHas('user', fn ($q) => $q->where('email', $email))->value('total_score');
        $this->assertGreaterThan($score('ttb@menglish.edu.vn') + 30, $score('manager@menglish.edu.vn'));
        $this->assertGreaterThan($score('gv.cohuu1@menglish.edu.vn') + 20, $score('gv.cohuu2@menglish.edu.vn'));
        $this->assertTrue(TeacherTimesheet::whereHas('classModel', fn ($q) => $q->where('code', 'DEMO-CG-SPK27'))->where('status', 'valid')->exists());

        // Chạy lại không nhân bản.
        $counts = fn () => [StaffAttendance::count(), StaffAttendanceRequest::count(), TeacherTimesheet::count(), KpiEvaluation::count(), User::count(),
            AcademicProject::count(), WorkTask::count(), MaterialOrder::count()];
        $before = $counts();
        $this->seed(DemoPayrollSeeder::class);
        $this->assertSame($before, $counts());
    }

    public function test_command_refuses_production_without_force(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('demo:luong')->assertFailed();
        $this->assertFalse(ClassModel::where('code', 'DEMO-CG-IF1')->exists());
    }
}
