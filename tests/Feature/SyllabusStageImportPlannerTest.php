<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Services\Syllabus\StageImport\StageImportPlanner;
use App\Services\Syllabus\StageImport\StageSheet;
use App\Services\Syllabus\StageImport\StageSheetRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kiểm tra & so sánh file chặng (Sheet 1) với chặng hiện có (docs/kho-syllabus.md §3.3). */
class SyllabusStageImportPlannerTest extends TestCase
{
    use RefreshDatabase;

    private SyllabusCurriculum $curriculum;

    private StageImportPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->curriculum = SyllabusCurriculum::create(['code' => 'GS6', 'title' => 'Global Success 6', 'version' => 'v1']);
        $this->planner = app(StageImportPlanner::class);
    }

    public function test_maps_types_milestones_units_and_titles(): void
    {
        $plan = $this->planner->plan($this->sheet([
            $this->row(11, type: 'Học', unit: 'Unit 1: My New School', objectives: 'Obj U1', content: "Getting started\nchi tiết", ppt: 'link PPT', quizizz: 'https://quizizz.com/a'),
            $this->row(12, type: 'mini  test', milestone: 'Order in ấn mini test'),
            $this->row(13, type: 'Big test', unit: 'Unit 2 – My House', milestone: 'Order Big test'),
        ]), $this->curriculum, null);

        $this->assertTrue($plan->ok(), json_encode($plan->errors, JSON_UNESCAPED_UNICODE));
        $types = array_map(fn ($l) => $l['attributes']['lesson_type'], $plan->lessons);
        $this->assertSame(['lesson', 'mini_test', 'big_test'], $types);
        $this->assertSame('Getting started', $plan->lessons[0]['attributes']['title']);
        $this->assertSame("Getting started\nchi tiết", $plan->lessons[0]['attributes']['content']);
        $this->assertSame('Mini test', $plan->lessons[1]['attributes']['title']);
        $this->assertNull($plan->lessons[0]['attributes']['ppt_url']);
        $this->assertSame('https://quizizz.com/a', $plan->lessons[0]['attributes']['quizizz_url']);
        $this->assertSame('order_mini_test', $plan->lessons[1]['attributes']['notice_milestone']);
        $this->assertSame('order_big_test', $plan->lessons[2]['attributes']['notice_milestone']);
        $this->assertSame([1, 1, 2], array_column($plan->lessons, 'unit_number'));
        $this->assertSame(['My New School', 'My House'], array_column($plan->units, 'title'));
        $this->assertSame('Obj U1', $plan->units[0]['objectives']);
        $this->assertSame(['create' => 3, 'update' => 0, 'delete' => 0, 'units' => 2], $plan->summary());
    }

    public function test_row_errors(): void
    {
        $plan = $this->planner->plan($this->sheet([
            $this->row(11, content: 'trước unit'),
            $this->row(12, unit: 'Bài 2', content: 'x'),
            $this->row(13, unit: 'Unit 3: A', type: 'Thi', ppt: 'ftp:/x', milestone: 'Gọi PH'),
        ]), $this->curriculum, null);

        $this->assertFalse($plan->ok());
        $this->assertEqualsCanonicalizing([11, 12, 13, 13, 13], array_column($plan->errors, 'line'));
    }

    public function test_update_matches_lessons_by_order_and_deletes_extra(): void
    {
        $stage = $this->curriculum->stages()->firstOrFail();
        $unit = SyllabusUnit::create(['curriculum_id' => $this->curriculum->id, 'stage_id' => $stage->id, 'unit_number' => 1, 'title' => 'Cũ']);
        $ids = collect([1, 2, 3])->map(fn ($n) => SyllabusLesson::create(['unit_id' => $unit->id, 'session_no' => $n, 'title' => "B{$n}"])->id)->all();

        $plan = $this->planner->plan($this->sheet([
            $this->row(11, unit: 'Unit 1: Mới', content: 'B1 mới'),
            $this->row(12, content: 'B2 mới'),
        ]), $this->curriculum, $stage);

        $this->assertSame([$ids[0], $ids[1]], array_column($plan->lessons, 'lesson_id'));
        $this->assertSame([$ids[2]], $plan->deleteLessonIds);
        $this->assertSame($unit->id, $plan->units[0]['unit_id']);
        $this->assertSame(['create' => 0, 'update' => 2, 'delete' => 1, 'units' => 0], $plan->summary());
    }

    public function test_unit_number_owned_by_other_stage_is_error(): void
    {
        $stage1 = $this->curriculum->stages()->firstOrFail();
        $stage2 = SyllabusStage::create(['curriculum_id' => $this->curriculum->id, 'position' => 2, 'name' => 'Chặng 2']);
        SyllabusUnit::create(['curriculum_id' => $this->curriculum->id, 'stage_id' => $stage2->id, 'unit_number' => 1, 'title' => 'U1']);

        $plan = $this->planner->plan($this->sheet([$this->row(11, unit: 'Unit 1: U1', content: 'x')]), $this->curriculum, $stage1);

        $this->assertFalse($plan->ok());
        $this->assertSame(11, $plan->errors[0]['line']);
    }

    public function test_warns_about_open_classes_and_curriculum_mismatch(): void
    {
        $stage = $this->curriculum->stages()->firstOrFail();
        $branch = Branch::create(['name' => 'CS', 'code' => 'CS', 'is_active' => true]);
        $class = ClassModel::create(['code' => 'L1', 'name' => 'Lớp 1', 'branch_id' => $branch->id, 'status' => 'active']);
        SyllabusAssignment::create(['class_id' => $class->id, 'curriculum_id' => $this->curriculum->id, 'stage_id' => $stage->id, 'stage_name' => $stage->label,
            'user_id' => User::factory()->create()->id,
            'assigned_chapters' => $stage->label, 'status' => SyllabusAssignment::STATUS_OPEN, 'open_class_id' => $class->id, 'opened_at' => now()]);

        $plan = $this->planner->plan($this->sheet([$this->row(11, unit: 'Unit 1: A', content: 'x')], curriculum: 'Giáo trình khác'), $this->curriculum, $stage);

        $this->assertSame(1, $plan->openClasses);
        $this->assertTrue(collect($plan->warnings)->contains(fn ($w) => str_contains($w, '1 lớp đang học')));
        $this->assertTrue(collect($plan->warnings)->contains(fn ($w) => str_contains($w, 'Giáo trình khác')));
    }

    public function test_empty_sheet_is_error(): void
    {
        $plan = $this->planner->plan($this->sheet([]), $this->curriculum, null);

        $this->assertFalse($plan->ok());
        $this->assertStringContainsString('File chưa có buổi nào', $plan->errors[0]['message']);
    }

    public function test_warns_about_skipped_example_rows(): void
    {
        $plan = $this->planner->plan(new StageSheet('Global Success 6', 'Chặng 1', 'v1', [$this->row(11, unit: 'Unit 1: A', content: 'x')], 2), $this->curriculum, null);

        $this->assertTrue(collect($plan->warnings)->contains(fn ($w) => str_contains($w, '2 dòng ví dụ')));
    }

    public function test_warns_when_updated_lessons_have_documents_or_pending_proposals(): void
    {
        $stage = $this->curriculum->stages()->firstOrFail();
        $unit = SyllabusUnit::create(['curriculum_id' => $this->curriculum->id, 'stage_id' => $stage->id, 'unit_number' => 1, 'title' => 'U1']);
        $lesson = SyllabusLesson::create(['unit_id' => $unit->id, 'session_no' => 1, 'title' => 'B1']);
        SyllabusChangeProposal::create(['curriculum_id' => $this->curriculum->id, 'lesson_id' => $lesson->id, 'user_id' => User::factory()->create()->id,
            'new_content' => 'x', 'reason' => 'y', 'status' => 'pending']);

        $plan = $this->planner->plan($this->sheet([$this->row(11, unit: 'Unit 1: U1', content: 'B1 mới')]), $this->curriculum, $stage);

        $this->assertTrue(collect($plan->warnings)->contains(fn ($w) => str_contains($w, 'đề xuất sửa')));
    }

    public function test_strips_stage_number_prefix_from_file_stage_name(): void
    {
        $plan = $this->planner->plan($this->sheet([$this->row(11, unit: 'Unit 1: A', content: 'x')], stage: 'Chặng 1 – Unit 1 → Unit 3'), $this->curriculum, null);

        $this->assertSame('Unit 1 → Unit 3', $plan->stageName);
    }

    /** @param  list<StageSheetRow>  $rows */
    private function sheet(array $rows, string $curriculum = 'Global Success 6', string $stage = 'Chặng 1'): StageSheet
    {
        return new StageSheet($curriculum, $stage, 'v1', $rows, 0);
    }

    private function row(int $line, ?string $type = null, ?string $unit = null, ?string $objectives = null, ?string $content = null,
        ?string $ppt = null, ?string $quizizz = null, ?string $milestone = null): StageSheetRow
    {
        return new StageSheetRow($line, $type, $unit, $objectives, $content, null, null, null, null, null, null, $ppt, $quizizz, null, $milestone, null);
    }
}
