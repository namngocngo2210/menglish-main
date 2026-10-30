<?php

namespace Tests\Feature;

use App\Models\AcademicProject;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\MaterialOrder;
use App\Models\MiniTestScore;
use App\Models\PayrollPeriod;
use App\Models\Penalty;
use App\Models\SlaEvent;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Kpi\KpiAutoCounter;
use App\Services\Kpi\KpiSheetService;
use App\Support\Roles;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoPayrollSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        // DatabaseSeeder chỉ gọi demo lương ngoài unit test (staging / local).
        $this->assertSame(0, StaffAttendance::count());
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

        // Đơn xin duyệt đủ trạng thái; đi muộn chấm công không lập biên bản từng lần (TK-0013).
        foreach (['pending', 'approved', 'rejected', 'cancelled'] as $status) {
            $this->assertTrue(StaffAttendanceRequest::where('status', $status)->exists(), "Thiếu đơn {$status}.");
        }
        $this->assertFalse(Penalty::where('notes', 'like', 'Tự động từ chấm công%')->exists());
        $this->assertFalse(StaffAttendance::whereNotNull('penalty_id')->exists());

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

        $this->assertKpiSourcesCovered($last, $month);

        // Chạy lại không nhân bản.
        $counts = fn () => [StaffAttendance::count(), StaffAttendanceRequest::count(), TeacherTimesheet::count(), KpiEvaluation::count(), User::count(),
            AcademicProject::count(), WorkTask::count(), MaterialOrder::count(), Student::count(), StudentAttendance::count(), MiniTestScore::count(),
            StaffReport::count(), SlaEvent::count()];
        $before = $counts();
        $this->seed(DemoPayrollSeeder::class);
        $this->assertSame($before, $counts());
    }

    /**
     * Mọi tiêu chí KPI tự đếm có số liệu thật ở tháng trước và tháng này: ít nhất 1 người có số khác rỗng / khác 0 (trừ báo cáo
     * tháng của tháng này — chưa tới hạn — và chăm sóc tháng đầu tháng trước). Người tốt hơn người kém; phiếu tháng trước chốt
     * theo số tự đếm.
     */
    private function assertKpiSourcesCovered(Carbon $last, Carbon $month): void
    {
        $counter = app(KpiAutoCounter::class);
        $sheets = app(KpiSheetService::class);
        $skip = ['this|monthly_report_on_time', 'last|care_overdue'];
        $measure = function (string $email, string $source, Carbon $m) use ($counter) {
            $user = User::where('email', $email)->firstOrFail();
            $period = KpiSheetService::periodFor($user, $m->month, $m->year);

            return $counter->measure($source, $user, $period['from'], $period['to'])['value'];
        };

        foreach (['last' => $last, 'this' => $month] as $label => $m) {
            foreach (KpiCriterion::active()->whereNotNull('auto_source')->get() as $criterion) {
                if (in_array("{$label}|{$criterion->auto_source}", $skip, true)) {
                    continue;
                }
                $period = KpiSheetService::period($criterion->role, $m->month, $m->year);
                $values = $sheets->staffQuery($criterion->role)->get()
                    ->map(fn (User $u) => $counter->measure($criterion->auto_source, $u, $period['from'], $period['to'])['value']);
                $this->assertTrue($values->contains(fn ($v) => $v !== null && (float) $v != 0.0),
                    "Nguồn {$criterion->auto_source} ({$criterion->role}) {$label} không có số liệu.");
            }
        }

        // Người tốt / kém: lớp GV part-time tốt chuyên cần, BTVN, kết quả test cao hơn lớp GV full-time kém (tháng trước lớp K27 của
        // gv.cohuu1, tháng này lớp K28 của GV full-time mới).
        foreach (['class_attendance_rate', 'homework_rate', 'test_result'] as $source) {
            foreach ([$last, $month] as $m) {
                $good = $measure('gv.banthoigian1@menglish.edu.vn', $source, $m);
                $bad = $measure($m->eq($last) ? 'gv.cohuu1@menglish.edu.vn' : 'gv.hamy@menglish.edu.vn', $source, $m);
                $this->assertNotNull($good, "{$source} GV tốt {$m->format('m/Y')}");
                $this->assertNotNull($bad, "{$source} GV kém {$m->format('m/Y')}");
                $this->assertGreaterThan($bad, $good, "{$source} {$m->format('m/Y')}: GV tốt phải cao hơn GV kém.");
            }
        }
        $this->assertSame(1, $measure('gv.cohuu1@menglish.edu.vn', 'teacher_absent_unexcused', $last));
        $this->assertSame(1, $measure('gv.native1@menglish.edu.vn', 'teacher_leave', $last));
        $this->assertGreaterThan($measure('levanvu@menglish.edu.vn', 'crm_sla_late', $month), $measure('hoangthinh@menglish.edu.vn', 'crm_sla_late', $month));
        $this->assertGreaterThan($measure('nva@menglish.edu.vn', 'tuition_no_reminder', $last), $measure('giaovu2@menglish.edu.vn', 'tuition_no_reminder', $last));

        // Phiếu tháng trước đã chốt lấy số tự đếm (không phải số điền tay): nghỉ không phép → loại trừ, tổng 0.
        $cohuu1 = User::where('email', 'gv.cohuu1@menglish.edu.vn')->firstOrFail();
        $evaluation = KpiEvaluation::where('user_id', $cohuu1->id)->where('year', $last->year)->where('month', $last->month)->firstOrFail();
        $this->assertSame(KpiEvaluation::STATUS_APPROVED, $evaluation->status);
        $this->assertSame(0.0, (float) $evaluation->total_score);
        $attendance = $evaluation->items()->whereHas('criterion', fn ($q) => $q->where('auto_source', 'class_attendance_rate'))->firstOrFail();
        $this->assertNotNull($attendance->evidence);
    }

    public function test_command_refuses_production_without_force(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('demo:luong')->assertFailed();
        $this->assertFalse(ClassModel::where('code', 'DEMO-CG-IF1')->exists());
    }
}
