<?php

namespace Tests\Feature;

use App\Models\AcademicProject;
use App\Models\AcademicProjectMilestone;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\KpiEvaluationItem;
use App\Models\MaterialOrder;
use App\Models\MiniTestScore;
use App\Models\StaffAttendance;
use App\Models\Student;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Kpi\KpiSheetService;
use App\Support\Money;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * KPI Học thuật theo file "KPI_Hoc_Thuat_ME_V11_THEO_THANG 2026": 12 tiêu chí / 100 điểm chấm theo tháng, Bảng A quy đổi tỷ lệ,
 * mục "Không phát sinh" bỏ khỏi tử số và mẫu số, order xong sau giờ dùng → mục tối đa 50% và tháng tối đa loại B,
 * nghỉ không phép → mất KPI tháng, thưởng KPI = 2.000.000đ × hệ số xếp loại.
 */
class KpiAcademicLeadTest extends TestCase
{
    use InteractsWithInertia, RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $head;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00'));
        $this->branch = Branch::create(['name' => 'Cơ sở Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->head = User::factory()->create(['name' => 'Đặng Hồng Nhung', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $this->head->assignRole(Roles::ACADEMIC_LEAD);
        $this->class = ClassModel::create(['code' => 'FLY-01', 'name' => 'Lớp Flyers 01', 'branch_id' => $this->branch->id, 'status' => 'active']);
    }

    private function criterion(string $prefix): KpiCriterion
    {
        return KpiCriterion::forRole(Roles::ACADEMIC_LEAD)->where('name', 'like', $prefix.'%')->firstOrFail();
    }

    private function line(array $sheet, string $prefix): array
    {
        return $sheet['lines']->first(fn ($l) => str_starts_with($l['criterion']->name, $prefix));
    }

    private function milestone(AcademicProject $project, string $due, ?string $completedAt): void
    {
        AcademicProjectMilestone::create([
            'academic_project_id' => $project->id, 'title' => 'Unit '.$due, 'due_date' => $due,
            'status' => $completedAt ? 'done' : 'in_progress', 'completed_at' => $completedAt,
        ]);
    }

    private function task(string $due, ?string $completedAt): void
    {
        WorkTask::create([
            'title' => 'Việc hạn '.$due, 'creator_id' => $this->admin->id, 'assignee_id' => $this->head->id, 'branch_id' => $this->branch->id,
            'task_type' => 'one_time', 'due_date' => substr($due, 0, 10), 'due_time' => substr($due, 11, 5),
            'status' => $completedAt ? 'completed' : 'in_progress', 'completed_at' => $completedAt,
        ]);
    }

    private function order(string $code, string $useDate, string $createdAt, ?string $processedAt): void
    {
        $this->travelTo(Carbon::parse($createdAt));
        MaterialOrder::create([
            'code' => $code, 'branch_id' => $this->branch->id, 'class_id' => $this->class->id, 'requester_id' => $this->admin->id,
            'category' => MaterialOrder::CATEGORY_ACADEMIC, 'title' => 'Bộ thẻ từ vựng', 'use_date' => $useDate, 'due_at' => $useDate.' 00:00:00',
            'status' => $processedAt ? MaterialOrder::STATUS_DONE : MaterialOrder::STATUS_PENDING,
            'processed_by' => $processedAt ? $this->head->id : null, 'processed_at' => $processedAt,
        ]);
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00'));
    }

    /** Số điền tay theo tên tiêu chí (tiền tố tên) → id. */
    private function manual(array $values): array
    {
        return collect($values)->mapWithKeys(fn ($value, $prefix) => [$this->criterion($prefix)->id => $value])->all();
    }

    public function test_default_set_follows_the_file(): void
    {
        $criteria = KpiCriterion::forRole(Roles::ACADEMIC_LEAD)->active()->ordered()->get();
        $this->assertCount(12, $criteria);
        $this->assertEquals(100, (float) $criteria->sum('weight'));
        $this->assertSame(5, $criteria->pluck('group_name')->unique()->count());
        $this->assertSame(1, KpiCriterion::periodMonths(Roles::ACADEMIC_LEAD));
        $this->assertTrue(KpiCriterion::hasGrades(Roles::ACADEMIC_LEAD));
        $this->assertSame(2000000.0, KpiCriterion::gradeFund(Roles::ACADEMIC_LEAD));
        $this->assertNull(KpiCriterion::gradeFund(Roles::TEACHER_PARTTIME));

        // Bảng A: ≥ 95 → 100, 90 → 90, 80 → 75, 70 → 60, 60 → 30, dưới 60 → 0.
        $deliverable = $this->criterion('Deliverable đúng hạn');
        $this->assertSame([100, 90, 75, 60, 30, 0], array_map(fn ($v) => $deliverable->levelFor($v), [95, 92.5, 85, 70, 60, 59.9]));
        $repeat = $this->criterion('Lỗi GV lặp lại');
        $this->assertSame([100, 75, 50, 0], array_map(fn ($v) => $repeat->levelFor($v), [0, 8, 25, 25.1]));
        $this->assertSame('0%: 100% · ≤ 10%: 75% · ≤ 25%: 50% · trên 25%: 0%', $repeat->ruleLabel());
        $late = $this->criterion('Đi muộn');
        $this->assertSame([100, 70, 40, 15, 0], array_map(fn ($v) => $late->levelFor($v), [0, 1, 2, 3, 4]));
        $this->assertTrue($this->criterion('Nghỉ không phép')->knockout);

        // Điều kiện chặn: tối đa loại B.
        $this->assertSame(['B', 'C'], [KpiCriterion::gradeFor(97, 'B')['grade'], KpiCriterion::gradeFor(80, 'B')['grade']]);
    }

    public function test_deliverables_tasks_orders_tests_and_lateness_are_counted_from_system_data(): void
    {
        // Mốc dự án (chủ dự án = Head): đúng hạn 1, trễ 3 ngày 0,5, quá hạn chưa xong 15 ngày 0; mốc chưa tới hạn chưa tính.
        $project = AcademicProject::create(['code' => 'BK-01', 'name' => 'Sách Flyers', 'owner_id' => $this->head->id, 'status' => 'in_progress']);
        $this->milestone($project, '2026-11-03', '2026-11-03 16:00:00');
        $this->milestone($project, '2026-11-05', '2026-11-08 09:00:00');
        $this->milestone($project, '2026-11-10', null);
        $this->milestone($project, '2026-11-28', null);
        // Task: tháng 11 chỉ có 2 việc tới hạn → gộp thêm tháng 10.
        $this->task('2026-10-20 17:00', '2026-10-20 15:00:00');
        $this->task('2026-11-06 17:00', '2026-11-06 19:00:00');
        $this->task('2026-11-07 17:00', '2026-11-08 10:00:00');
        $this->task('2026-11-30 17:00', null);
        // Order: lớp có buổi 18:00 ngày 20/11; ngày 21, 22 không có buổi → giờ dùng 08:00.
        ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => '2026-11-20', 'shift_name' => 'Ca tối', 'start_time' => '18:00', 'end_time' => '19:30', 'status' => 'scheduled']);
        $this->order('ORD-1', '2026-11-20', '2026-11-10 09:00', '2026-11-19 10:00');     // trước 32 giờ → 1
        $this->order('ORD-2', '2026-11-20', '2026-11-19 20:00', '2026-11-20 12:00');     // tạo sát giờ dùng → không tính
        $this->order('ORD-3', '2026-11-21', '2026-11-10 09:00', '2026-11-21 09:00');     // sau giờ dùng → 0, chặn
        $this->order('ORD-4', '2026-11-22', '2026-11-10 09:00', '2026-11-21 20:00');     // trước 12 giờ → 0,5
        // Đầu ra: 2/4 bài trong 3 tháng đạt chuẩn; bài tháng 8 ngoài cửa sổ.
        $student = Student::create(['name' => 'Bảo', 'code' => 'HV-1', 'phone' => '0901000001', 'current_class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'status' => 'studying']);
        foreach ([['2026-09-15', 8], ['2026-10-15', 9], ['2026-11-10', 6], ['2026-11-12', 5], ['2026-08-15', 9]] as [$date, $score]) {
            MiniTestScore::create(['class_id' => $this->class->id, 'student_id' => $student->id, 'user_id' => $this->admin->id, 'name' => 'Mini '.$date, 'score' => $score, 'max_score' => 10, 'test_date' => $date]);
        }
        // Đi muộn: 15 phút (tính), 5 phút (không quá 10 phút), 30 phút có đơn được duyệt (không tính).
        foreach ([['2026-11-03', 15, false], ['2026-11-04', 5, false], ['2026-11-05', 30, true]] as [$date, $minutes, $excused]) {
            StaffAttendance::create(['user_id' => $this->head->id, 'branch_id' => $this->branch->id, 'work_date' => $date, 'check_in_at' => $date.' 08:'.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT).':00', 'late_minutes' => $minutes, 'late_excused' => $excused]);
        }

        $sheet = app(KpiSheetService::class)->sheet($this->head, 11, 2026);
        $deliverable = $this->line($sheet, 'Deliverable đúng hạn');
        $this->assertSame([true, 50.0, 0], [$deliverable['auto'], $deliverable['value'], $deliverable['level']]);
        $this->assertStringContainsString('xong trễ 3 ngày', $deliverable['evidence'][1]['text']);
        $task = $this->line($sheet, 'Task đúng hạn');
        $this->assertSame([58.3, 0], [$task['value'], $task['level']]);
        $this->assertStringContainsString('gộp thêm tháng trước', $task['evidence'][0]['text']);
        $order = $this->line($sheet, 'Order học thuật');
        $this->assertSame([50.0, true], [$order['value'], $order['cap']]);
        $this->assertFalse(collect($order['evidence'])->first(fn ($e) => str_contains($e['text'], 'ORD-2'))['counted']);
        $this->assertSame([50.0, 0], [$this->line($sheet, 'Chất lượng đầu ra')['value'], $this->line($sheet, 'Chất lượng đầu ra')['level']]);
        $this->assertSame([1, 70], [$this->line($sheet, 'Đi muộn')['value'], $this->line($sheet, 'Đi muộn')['level']]);
    }

    public function test_not_applicable_items_leave_the_total_and_the_bonus_follows_the_grade(): void
    {
        $sheets = app(KpiSheetService::class);
        $sheet = $sheets->sheet($this->head, 11, 2026);
        // Nguồn tự động không có dữ liệu → Không phát sinh; tiêu chí điền tay còn thiếu số liệu.
        $this->assertTrue($this->line($sheet, 'Deliverable đúng hạn')['na']);
        $this->assertTrue($this->line($sheet, 'Chất lượng đầu ra')['na']);
        $this->assertSame(7, $sheet['missing']);

        $url = route('kpi.evaluate.store', $this->head->id);
        $actual = $this->manual([
            'Chất lượng deliverable' => '96', 'Kiểm soát tiến độ' => '92', 'Đào tạo' => '100', 'Xử lý phản ánh' => '85,5',
            'Nghỉ không phép' => '0', 'Họp bắt buộc' => '1',
        ]);
        $na = $this->manual(['Lỗi GV lặp lại' => 1]);
        $this->actingAs($this->admin)->post($url, ['month' => 11, 'year' => 2026, 'action' => 'approve', 'actual' => $actual, 'na' => $na])
            ->assertSessionHasErrors(['month' => 'Duyệt KPI từ ngày cuối tháng 30/11.']);

        $this->travelTo(Carbon::parse('2026-11-30 09:00:00'));
        $this->actingAs($this->admin)->get(route('kpi.evaluate', ['userId' => $this->head->id, 'period' => '2026-11']), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Kpi/Evaluate')->where('bonusFund', 2000000)->where('gradeCap', null)->has('grades', 5));
        $this->actingAs($this->admin)->post($url, ['month' => 11, 'year' => 2026, 'action' => 'approve', 'actual' => $actual, 'na' => $na])
            ->assertSessionHasNoErrors();

        // Áp dụng 48 điểm: 10×100 + 9×90 + 9×100 + 10×75 + đi muộn 4×100 + nghỉ 2×100 + họp 4×60 = 4300 → 89,58% → loại B.
        $evaluation = KpiEvaluation::where('user_id', $this->head->id)->firstOrFail();
        $this->assertSame(KpiEvaluation::STATUS_APPROVED, $evaluation->status);
        $this->assertEquals(89.58, (float) $evaluation->total_score);
        $this->assertTrue((bool) KpiEvaluationItem::where('kpi_evaluation_id', $evaluation->id)->where('kpi_criterion_id', $this->criterion('Lỗi GV lặp lại')->id)->value('not_applicable'));

        $this->actingAs($this->admin)->get(route('kpi.monthly', ['period' => '2026-11']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('staff.data.0.amount_label', 'Loại B · '.Money::format(1700000)));
        $this->actingAs($this->head)->get(route('kpi.mine', ['period' => '2026-11']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Kpi/Mine')->where('grade.grade', 'B')->where('bonus', 1700000)->where('missing', 0));
    }

    public function test_absence_without_leave_zeroes_the_month_and_late_orders_cap_the_grade(): void
    {
        $evaluation = KpiEvaluation::create(['user_id' => $this->head->id, 'month' => 11, 'year' => 2026, 'period_months' => 1, 'total_score' => 0, 'status' => KpiEvaluation::STATUS_PENDING]);
        foreach ($this->manual(['Chất lượng deliverable' => '100', 'Kiểm soát tiến độ' => '100', 'Đào tạo' => '100', 'Xử lý phản ánh' => '100', 'Lỗi GV lặp lại' => '0', 'Nghỉ không phép' => '0', 'Họp bắt buộc' => '0']) as $id => $value) {
            KpiEvaluationItem::create(['kpi_evaluation_id' => $evaluation->id, 'kpi_criterion_id' => $id, 'actual' => $value, 'score' => 0]);
        }
        ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => '2026-11-20', 'shift_name' => 'Ca tối', 'start_time' => '18:00', 'end_time' => '19:30', 'status' => 'scheduled']);
        foreach (['ORD-1', 'ORD-2', 'ORD-3'] as $code) {
            $this->order($code, '2026-11-20', '2026-11-10 09:00', '2026-11-15 10:00');
        }
        $sheets = app(KpiSheetService::class);
        $this->assertSame(['A', null], [$sheets->sheet($this->head, 11, 2026, $evaluation->fresh('items'))['grade']['grade'], $sheets->sheet($this->head, 11, 2026, $evaluation->fresh('items'))['knockout']]);

        // Một order giao sau giờ dùng: mục order tối đa 50%, tháng tối đa loại B dù tổng vẫn cao.
        $this->order('ORD-4', '2026-11-20', '2026-11-10 09:00', '2026-11-20 19:00');
        $sheet = $sheets->sheet($this->head, 11, 2026, $evaluation->fresh('items'));
        $this->assertSame(50, $this->line($sheet, 'Order học thuật')['level']);
        $this->assertSame('B', $sheet['grade']['grade']);
        $this->assertSame(1700000.0, $sheet['bonus']);

        KpiEvaluationItem::where('kpi_evaluation_id', $evaluation->id)->where('kpi_criterion_id', $this->criterion('Nghỉ không phép')->id)->update(['actual' => '1']);
        $sheet = $sheets->sheet($this->head, 11, 2026, $evaluation->fresh('items'));
        $this->assertSame(['Nghỉ không phép', 0.0, 'E', 0.0], [$sheet['knockout'], $sheet['total'], $sheet['grade']['grade'], $sheet['bonus']]);
    }

    public function test_criteria_screen_saves_lower_is_better_rates_not_applicable_and_the_bonus_fund(): void
    {
        $this->actingAs($this->admin)->get(route('kpi.criteria', ['role' => Roles::ACADEMIC_LEAD]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('gradeFund', 2000000)->has('grades', 5)
                ->where('criteriaGroups.0.items.0.allow_na', true));

        $this->actingAs($this->admin)->post(route('kpi.criteria.store'), [
            'role' => Roles::TEACHER_FULLTIME, 'name' => 'Tỷ lệ lỗi giáo án', 'weight' => 10, 'measure' => 'rate_down', 'allow_na' => 1,
            'tiers' => [['at' => 5, 'percent' => 50], ['at' => 0, 'percent' => 100]],
        ])->assertSessionHasNoErrors();
        $criterion = KpiCriterion::forRole(Roles::TEACHER_FULLTIME)->where('name', 'Tỷ lệ lỗi giáo án')->firstOrFail();
        $this->assertSame(['rate_down', KpiCriterion::RATE_UNIT, true, [[0.0, 100.0], [5.0, 50.0]]], [$criterion->measure, $criterion->unit, $criterion->allow_na, $criterion->tierList()]);
        $this->assertSame([100, 50, 0], [$criterion->levelFor(0), $criterion->levelFor(4), $criterion->levelFor(6)]);

        $this->actingAs($this->admin)->post(route('kpi.criteria.cycle'), ['role' => Roles::ACADEMIC_LEAD, 'period_months' => 1, 'grade_fund' => 2500000])->assertRedirect();
        $this->assertSame(2500000.0, KpiCriterion::gradeFund(Roles::ACADEMIC_LEAD));
        $this->actingAs($this->admin)->post(route('kpi.criteria.cycle'), ['role' => Roles::ACADEMIC_LEAD, 'period_months' => 1, 'grade_fund' => null])->assertRedirect();
        $this->assertNull(KpiCriterion::gradeFund(Roles::ACADEMIC_LEAD));
    }
}
