<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\MiniTestScore;
use App\Models\StaffAttendanceRequest;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SystemSetting;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Services\Kpi\KpiSheetService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * KPI GV part-time theo file "KPI_GV_PartTime_BangTrongSo": 15 chỉ tiêu / 100 điểm + điều kiện nghỉ dạy không phép, bậc điểm
 * theo số lần hoặc tỉ lệ %, xếp loại A–E. Chủ dự án chọn phiếu theo tháng; Admin đổi được sang quý (một phiếu cả quý, đi muộn /
 * nghỉ có phép tính từng tháng rồi lấy trung bình).
 */
class KpiParttimeTeacherTest extends TestCase
{
    use InteractsWithInertia, RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $teacher;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00:00'));
        $this->branch = Branch::create(['name' => 'Cơ sở Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->teacher = User::factory()->create(['name' => 'Trần Minh Anh', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $this->teacher->assignRole(Roles::TEACHER_PARTTIME);
        $this->class = ClassModel::create(['code' => 'FLY-01', 'name' => 'Lớp Flyers 01', 'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id, 'status' => 'active']);

        // Tháng 10: 2 buổi check-in muộn; tháng 11: 1 buổi đúng giờ, 1 buổi không có giờ dạy.
        $this->teachingSession('2026-10-06', lateMinutes: 5);
        $this->teachingSession('2026-10-08', lateMinutes: 20);
        $this->teachingSession('2026-11-03', lateMinutes: 0);
        $this->teachingSession('2026-11-05');
    }

    private function teachingSession(string $date, ?int $lateMinutes = null): ClassSession
    {
        $session = ClassSession::create([
            'class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => $date, 'shift_name' => 'Ca tối',
            'start_time' => '18:00', 'end_time' => '19:30', 'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);
        if ($lateMinutes !== null) {
            TeacherTimesheet::create([
                'user_id' => $this->teacher->id, 'class_id' => $this->class->id, 'class_session_id' => $session->id,
                'teaching_date' => $date, 'hours' => 1.5, 'type' => 'regular', 'status' => 'valid', 'late_minutes' => $lateMinutes,
            ]);
        }

        return $session;
    }

    private function criterion(string $name): KpiCriterion
    {
        return KpiCriterion::forRole(Roles::TEACHER_PARTTIME)->where('name', $name)->firstOrFail();
    }

    private function approveLeave(string $date): void
    {
        StaffAttendanceRequest::create([
            'user_id' => $this->teacher->id, 'branch_id' => $this->branch->id, 'type' => StaffAttendanceRequest::TYPE_LEAVE,
            'date_from' => $date, 'date_to' => $date, 'reason' => 'Ốm', 'status' => StaffAttendanceRequest::STATUS_APPROVED,
        ]);
    }

    private function quarterly(): void
    {
        SystemSetting::set('kpi_cycle_'.Roles::TEACHER_PARTTIME, 3);
    }

    /** Line của phiếu theo tên tiêu chí. */
    private function line(array $sheet, string $name): array
    {
        return $sheet['lines']->first(fn ($l) => $l['criterion']->name === $name);
    }

    public function test_default_set_follows_the_file_and_is_scored_by_month(): void
    {
        $criteria = KpiCriterion::forRole(Roles::TEACHER_PARTTIME)->active()->ordered()->get();
        $this->assertCount(16, $criteria);
        $this->assertEquals(100, (float) $criteria->sum('weight'));
        $this->assertSame(6, $criteria->pluck('group_name')->unique()->count());
        $this->assertSame(1, KpiCriterion::periodMonths(Roles::TEACHER_PARTTIME));
        $this->assertSame(1, KpiCriterion::periodMonths(Roles::ACADEMIC_STAFF));

        $late = $this->criterion('Đi muộn giờ dạy');
        $this->assertSame([100, 100, 62.5, 25, 0], array_map(fn ($n) => $late->levelFor($n), [0, 1, 2, 3, 4]));
        $this->assertSame('≤ 1 lần: 100% · 2: 62,5% · 3: 25% · từ 4: 0%', $late->ruleLabel());

        $homework = $this->criterion('Tỷ lệ hoàn thành bài tập về nhà (BTVN)');
        $this->assertSame([100, 80, 60, 0], array_map(fn ($n) => $homework->levelFor($n), [96, 92, 85, 84.9]));
        $this->assertSame('≥ 95%: 100% · ≥ 90%: 80% · ≥ 85%: 60% · dưới 85%: 0%', $homework->ruleLabel());

        $progress = $this->criterion('Tăng trưởng / tiến bộ học tập của học sinh');
        $this->assertSame([50, 100, 0], [$progress->levelFor(5), $progress->levelFor(12), $progress->levelFor(-3)]);
        $this->assertSame('Theo tỉ lệ, đủ điểm khi đạt 10%', $progress->ruleLabel());

        $feedback = $this->criterion('Feedback phụ huynh đã xác minh là lỗi GV');
        $this->assertSame([100, 60, 0], [$feedback->levelFor(0), $feedback->levelFor(2), $feedback->levelFor(6)]);
        $this->assertSame('Có từ 1 buổi: mất toàn bộ KPI kỳ', $this->criterion('Nghỉ dạy không phép')->ruleLabel());

        $this->assertSame(['A', 'B', 'C', 'D', 'E'], array_map(fn ($t) => KpiCriterion::gradeFor($t)['grade'], [95, 94.99, 70, 50, 49]));
    }

    public function test_one_sheet_per_month_or_per_quarter_when_the_cycle_is_quarterly(): void
    {
        $this->artisan('kpi:create-sheets')->assertSuccessful();
        $this->assertSame([11, 1], [KpiEvaluation::where('user_id', $this->teacher->id)->value('month'), KpiEvaluation::where('user_id', $this->teacher->id)->value('period_months')]);
        KpiEvaluation::query()->delete();

        $this->quarterly();
        $academic = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $academic->assignRole(Roles::ACADEMIC_STAFF);
        $this->artisan('kpi:create-sheets')->assertSuccessful();

        $this->assertSame([10, 3], [KpiEvaluation::where('user_id', $this->teacher->id)->value('month'), KpiEvaluation::where('user_id', $this->teacher->id)->value('period_months')]);
        $this->assertSame([11, 1], [KpiEvaluation::where('user_id', $academic->id)->value('month'), KpiEvaluation::where('user_id', $academic->id)->value('period_months')]);

        $this->travelTo(Carbon::parse('2026-12-01 01:00'));
        $this->artisan('kpi:create-sheets')->assertSuccessful();
        $this->assertSame(1, KpiEvaluation::where('user_id', $this->teacher->id)->count(), 'cả quý một phiếu');
        $this->assertSame(2, KpiEvaluation::where('user_id', $academic->id)->count());
    }

    public function test_absence_without_leave_zeroes_the_month_and_lateness_is_tiered(): void
    {
        $sheets = app(KpiSheetService::class);
        $october = $sheets->sheet($this->teacher, 10, 2026);
        $this->assertSame([2, 62.5], [$this->line($october, 'Đi muộn giờ dạy')['value'], $this->line($october, 'Đi muộn giờ dạy')['level']]);
        $this->assertNull($october['knockout']);

        $sheet = $sheets->sheet($this->teacher, 11, 2026);
        $this->assertSame('Tháng 11/2026', $sheet['period']['label']);
        $this->assertSame(0, $this->line($sheet, 'Đi muộn giờ dạy')['value']);
        $absent = $this->line($sheet, 'Nghỉ dạy không phép');
        $this->assertSame(1, $absent['value']);
        $this->assertStringContainsString('FLY-01', $absent['evidence'][0]['text']);
        $this->assertSame('Nghỉ dạy không phép', $sheet['knockout']);
        $this->assertSame(0.0, $sheet['total']);
        $this->assertSame('E', $sheet['grade']['grade']);

        // Có đơn nghỉ được duyệt → nghỉ có phép (1 lần trong tháng vẫn đủ điểm), không còn mất KPI tháng.
        $this->approveLeave('2026-11-05');
        $sheet = $sheets->sheet($this->teacher, 11, 2026);
        $this->assertSame(0, $this->line($sheet, 'Nghỉ dạy không phép')['value']);
        $this->assertSame([1, 100], [$this->line($sheet, 'Chuyên cần (nghỉ có phép)')['value'], $this->line($sheet, 'Chuyên cần (nghỉ có phép)')['level']]);
        $this->assertNull($sheet['knockout']);

        // Chu kỳ quý: đi muộn tháng 10 (2 lần = 62,5%) và tháng 11 (0 lần = 100%) lấy trung bình = 87,5%.
        $this->quarterly();
        $quarter = $sheets->sheet($this->teacher, 11, 2026);
        $this->assertSame('Quý 4/2026', $quarter['period']['label']);
        $this->assertSame([2, 87.5], [$this->line($quarter, 'Đi muộn giờ dạy')['value'], $this->line($quarter, 'Đi muộn giờ dạy')['level']]);
    }

    public function test_class_rates_and_test_scores_come_from_class_data(): void
    {
        $students = collect(['Bảo', 'Chi'])->map(fn ($name, $i) => Student::create([
            'name' => $name, 'code' => 'HV-KPI-'.$i, 'phone' => '090100000'.$i, 'current_class_id' => $this->class->id,
            'branch_id' => $this->branch->id, 'status' => 'studying',
        ]));
        // Mini test: lần đầu 6 / 8, lần cuối 7 / 9 (thang 10) → TB 7,5, 3/4 bài đạt chuẩn ≥ 7; lớp tăng 7 → 8 (+14,3%).
        foreach ([['2026-11-02', [6, 8]], ['2026-11-07', [7, 9]]] as [$date, $scores]) {
            foreach ($students as $i => $student) {
                MiniTestScore::create(['class_id' => $this->class->id, 'student_id' => $student->id, 'user_id' => $this->teacher->id, 'name' => 'Mini '.$date, 'score' => $scores[$i], 'max_score' => 10, 'test_date' => $date]);
            }
        }
        // Chuyên cần: 3/4 lượt có mặt = 75% (< 80% → 0 điểm).
        foreach (['2026-11-03' => ['present', 'present'], '2026-11-05' => ['present', 'absent']] as $date => $statuses) {
            $session = ClassSession::whereDate('date', $date)->firstOrFail();
            foreach ($students as $i => $student) {
                StudentAttendance::create(['class_session_id' => $session->id, 'class_id' => $this->class->id, 'student_id' => $student->id, 'session_date' => $date, 'status' => $statuses[$i]]);
            }
        }

        $sheet = app(KpiSheetService::class)->sheet($this->teacher, 11, 2026);
        $result = $this->line($sheet, 'Kết quả Test (điểm + tỷ lệ đạt chuẩn)');
        $this->assertSame([true, 75.0, 75], [$result['auto'], $result['value'], $result['level']]);
        $progress = $this->line($sheet, 'Tăng trưởng / tiến bộ học tập của học sinh');
        $this->assertSame([14.3, 100], [$progress['value'], $progress['level']]);
        $this->assertStringContainsString('7 → 8', $progress['evidence'][0]['text']);
        $attendance = $this->line($sheet, 'Tỷ lệ chuyên cần học sinh của lớp');
        $this->assertSame([75.0, 0], [$attendance['value'], $attendance['level']]);
        // Chưa có bài tập giao trong tháng → người chấm điền tay.
        $this->assertSame([false, null], [$this->line($sheet, 'Tỷ lệ hoàn thành bài tập về nhà (BTVN)')['auto'], $this->line($sheet, 'Tỷ lệ hoàn thành bài tập về nhà (BTVN)')['value']]);
    }

    public function test_month_sheet_is_approved_from_the_last_day_of_the_month_with_a_grade(): void
    {
        $url = route('kpi.evaluate.store', $this->teacher->id);
        $manual = KpiCriterion::forRole(Roles::TEACHER_PARTTIME)->active()->get()
            ->reject(fn (KpiCriterion $c) => $c->isAuto() && ! $c->isRateSource())
            ->mapWithKeys(fn (KpiCriterion $c) => [$c->id => $c->isRate() ? '100' : '0'])->all();

        $this->actingAs($this->admin)->post($url, ['month' => 11, 'year' => 2026, 'action' => 'approve', 'actual' => $manual])
            ->assertSessionHasErrors(['month' => 'Duyệt KPI từ ngày cuối tháng 30/11.']);
        $this->actingAs($this->admin)->get(route('kpi.evaluate', ['userId' => $this->teacher->id, 'period' => '2026-10']), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Kpi/Evaluate')->where('month', 10)->where('periodMonths', 1)->where('canClose', true)->has('grades', 5));
        $this->actingAs($this->admin)->post($url, ['month' => 10, 'year' => 2026, 'action' => 'approve', 'actual' => $manual])
            ->assertSessionHasNoErrors();
        $evaluation = KpiEvaluation::where('user_id', $this->teacher->id)->firstOrFail();
        // 100 điểm − đi muộn 2 lần (8 × 37,5% = 3) − báo cáo tháng 10 chưa nộp (5) = 92% → loại B.
        $this->assertSame([10, 1, KpiEvaluation::STATUS_APPROVED], [$evaluation->month, $evaluation->period_months, $evaluation->status]);
        $this->assertEquals(92, (float) $evaluation->total_score);

        $this->actingAs($this->admin)->get(route('kpi.monthly', ['period' => '2026-10']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('staff.data.0.amount_label', 'Loại B · hệ số 85%')->where('staff.data.0.period_label', null));
        $this->actingAs($this->teacher)->get(route('kpi.mine', ['period' => '2026-10']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Kpi/Mine')->where('grade.grade', 'B')->where('missing', 0)->where('periodLabel', 'Kỳ lương tháng 10/2026'));
    }

    public function test_quarter_sheet_is_approved_from_the_last_day_of_the_quarter_with_a_grade(): void
    {
        $this->quarterly();
        $this->approveLeave('2026-11-05');
        $url = route('kpi.evaluate.store', $this->teacher->id);
        $manual = KpiCriterion::forRole(Roles::TEACHER_PARTTIME)->active()->get()
            ->reject(fn (KpiCriterion $c) => $c->isAuto() && ! $c->isRateSource())
            ->mapWithKeys(fn (KpiCriterion $c) => [$c->id => $c->isRate() ? '100' : '0'])->all();

        $this->actingAs($this->admin)->get(route('kpi.evaluate', ['userId' => $this->teacher->id, 'period' => '2026-11']), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Kpi/Evaluate')->where('month', 10)->where('periodMonths', 3)
                ->where('periodLabel', 'Quý 4/2026 (tháng 10–12)')->where('simpleRules', false)->where('canClose', false)->has('grades', 5));
        $this->actingAs($this->admin)->post($url, ['month' => 11, 'year' => 2026, 'action' => 'approve', 'actual' => $manual])
            ->assertSessionHasErrors(['month' => 'Duyệt KPI quý từ ngày cuối quý 31/12.']);

        $this->travelTo(Carbon::parse('2026-12-31 09:00'));
        $this->actingAs($this->admin)->post($url, ['month' => 12, 'year' => 2026, 'action' => 'approve', 'actual' => ['x' => '1'] + $manual])
            ->assertSessionHasNoErrors();
        $evaluation = KpiEvaluation::where('user_id', $this->teacher->id)->firstOrFail();
        // 100 điểm − đi muộn 8 × 12,5% − báo cáo tháng 5 × 100% (chưa nộp tháng 10, 11) = 94% → loại B.
        $this->assertSame([10, 3, KpiEvaluation::STATUS_APPROVED], [$evaluation->month, $evaluation->period_months, $evaluation->status]);
        $this->assertEquals(94, (float) $evaluation->total_score);

        $this->actingAs($this->admin)->get(route('kpi.monthly', ['period' => '2026-12']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('staff.data.0.amount_label', 'Loại B · hệ số 85%')->where('staff.data.0.period_label', 'Phiếu quý 4/2026'));
        $this->actingAs($this->teacher)->get(route('kpi.mine', ['period' => '2026-10']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Kpi/Mine')->where('grade.grade', 'B')->where('status', 'confirmed')->where('periodLabel', 'Quý 4/2026 (tháng 10–12)'));
    }

    public function test_criteria_screen_saves_tiers_rates_and_the_scoring_cycle(): void
    {
        $this->actingAs($this->admin)->get(route('kpi.criteria', ['role' => Roles::TEACHER_PARTTIME]))->assertOk()
            ->assertSee('Đi muộn giờ dạy')->assertSee('Chu kỳ chấm')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('periodMonths', 1)->has('grades', 5));

        $this->actingAs($this->admin)->post(route('kpi.criteria.store'), [
            'role' => Roles::TEACHER_FULLTIME, 'new_group' => 'Lớp học', 'name' => 'Tỷ lệ giữ chân học sinh', 'weight' => 50, 'measure' => 'rate',
            'tiers' => [['at' => 80, 'percent' => 50], ['at' => 90, 'percent' => 100], ['at' => '', 'percent' => '']], 'linear' => 0,
        ])->assertSessionHasNoErrors();
        $rate = KpiCriterion::forRole(Roles::TEACHER_FULLTIME)->where('name', 'Tỷ lệ giữ chân học sinh')->firstOrFail();
        $this->assertSame(['rate', KpiCriterion::RATE_UNIT, [[90.0, 100.0], [80.0, 50.0]]], [$rate->measure, $rate->unit, $rate->tierList()]);
        $this->assertSame([100, 50, 0], [$rate->levelFor(91), $rate->levelFor(85), $rate->levelFor(79)]);

        $this->actingAs($this->admin)->post(route('kpi.criteria.store'), [
            'role' => Roles::TEACHER_FULLTIME, 'name' => 'Đi muộn', 'weight' => 50, 'measure' => 'count', 'unit' => 'lan',
            'tiers' => [['at' => 1, 'percent' => 100], ['at' => 3, 'percent' => 40]], 'per_month' => 1,
        ])->assertSessionHasNoErrors();
        $count = KpiCriterion::forRole(Roles::TEACHER_FULLTIME)->where('name', 'Đi muộn')->firstOrFail();
        $this->assertSame([[1.0, 100.0], [3.0, 40.0]], $count->tierList());
        $this->assertSame([100, 40, 0], [$count->levelFor(1), $count->levelFor(3), $count->levelFor(4)]);
        $this->actingAs($this->admin)->post(route('kpi.criteria.store'), [
            'role' => Roles::TEACHER_FULLTIME, 'name' => 'Không bậc', 'weight' => 1, 'measure' => 'count', 'unit' => 'lan', 'tiers' => [['at' => '', 'percent' => '']],
        ])->assertSessionHasErrors('tiers');

        $this->actingAs($this->admin)->post(route('kpi.criteria.cycle'), ['role' => Roles::TEACHER_FULLTIME, 'period_months' => 3])
            ->assertRedirect(route('kpi.criteria', ['role' => Roles::TEACHER_FULLTIME]));
        $this->assertSame(3, KpiCriterion::periodMonths(Roles::TEACHER_FULLTIME));
        $this->actingAs($this->teacher)->post(route('kpi.criteria.cycle'), ['role' => Roles::TEACHER_FULLTIME, 'period_months' => 1])->assertForbidden();
    }
}
