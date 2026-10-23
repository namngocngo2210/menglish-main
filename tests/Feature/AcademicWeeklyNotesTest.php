<?php

namespace Tests\Feature;

use App\Models\StaffReport;
use App\Models\StaffReportNote;
use App\Models\WorkTask;
use App\Services\Reports\WeeklyReportMetrics;
use App\Support\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Báo cáo tuần Học thuật (Sheet 4 câu 9): việc hoàn thành / quá hạn / lớp đã giao syllabus; Quản lý và người làm ghi chú. */
class AcademicWeeklyNotesTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private StaffReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        $week = ReportPeriod::currentWeek();
        $this->actingAs($this->lead)->post(route('reports.periodic.academic-weekly.store'), [
            'week' => $week, 'rows' => ['done' => [['content' => 'Rà soát đề Big Test']]],
        ])->assertSessionHasNoErrors();
        $this->report = StaffReport::where('kind', 'academic_weekly')->sole();
    }

    public function test_summary_counts_syllabus_tasks_and_assigned_classes(): void
    {
        $assignment = $this->openStage($this->stage1);
        $base = ['creator_id' => $this->lead->id, 'assignee_id' => $this->lead->id, 'branch_id' => $this->branch->id, 'class_id' => $this->class->id,
            'kind' => WorkTask::KIND_SYLLABUS_STAGE_END, 'syllabus_assignment_id' => $assignment->id, 'task_type' => 'one_time'];
        WorkTask::create($base + ['title' => 'Việc xong', 'status' => 'completed', 'completed_at' => now(), 'due_date' => today()]);
        WorkTask::create($base + ['title' => 'Việc trễ', 'status' => 'overdue', 'due_date' => today()->subDays(2)]);

        $items = collect(WeeklyReportMetrics::academic($this->lead, ReportPeriod::currentWeek())['summary']['items'])->keyBy('key');

        $this->assertSame(1, (int) $items['syllabus_tasks_done']['value']);
        $this->assertSame(1, (int) $items['syllabus_tasks_overdue']['value']);
        $this->assertSame(1, (int) $items['classes_assigned']['value']);
        $this->assertSame('Lớp QT 01 · Chặng 1: Làm quen', $items['classes_assigned']['details'][0]['text']);
    }

    public function test_manager_and_author_can_note_others_cannot(): void
    {
        $url = route('reports.notes.store', $this->report->id);
        $this->actingAs($this->manager)->post($url, ['note' => 'Tuần sau ưu tiên đề chặng 2.'])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->lead)->post($url, ['note' => 'Đã nhận, sẽ xong trước thứ 5.'])->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post($url, ['note' => 'x'])->assertForbidden();
        $this->actingAs($this->manager)->post($url, ['note' => ''])->assertSessionHasErrors('note');

        $this->assertSame([$this->manager->id, $this->lead->id], StaffReportNote::orderBy('id')->pluck('user_id')->all());
    }

    public function test_notes_listed_on_report_for_author_and_manager(): void
    {
        StaffReportNote::create(['staff_report_id' => $this->report->id, 'user_id' => $this->manager->id, 'note' => 'Ghi chú của Quản lý']);

        $this->actingAs($this->lead)->get(route('reports.periodic.academic-weekly'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('notes.0.note', 'Ghi chú của Quản lý')->where('notes.0.user', 'Quản lý')
                ->where('noteUrl', route('reports.notes.store', $this->report->id))->etc());
        $this->actingAs($this->manager)->get(route('reports.detail', $this->report->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('report.notes.0.note', 'Ghi chú của Quản lý')->where('report.note_url', route('reports.notes.store', $this->report->id))->etc());
    }
}
