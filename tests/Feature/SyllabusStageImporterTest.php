<?php

namespace Tests\Feature;

use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Services\Syllabus\StageImport\StageImporter;
use App\Services\Syllabus\StageImport\StageImportPlanner;
use App\Services\Syllabus\StageImport\StageSheet;
use App\Services\Syllabus\StageImport\StageSheetRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Áp dụng kế hoạch import chặng: tạo / cập nhật chặng, đánh lại số buổi, 1 dòng lịch sử (docs/kho-syllabus.md §3.3). */
class SyllabusStageImporterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SyllabusCurriculum $curriculum;

    private SyllabusStage $stage1;

    private SyllabusStage $stage2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->curriculum = SyllabusCurriculum::create(['code' => 'GS6', 'title' => 'Global Success 6', 'version' => 'v1']);
        $this->stage1 = $this->curriculum->stages()->firstOrFail();
        $this->stage2 = SyllabusStage::create(['curriculum_id' => $this->curriculum->id, 'position' => 2, 'name' => 'Chặng 2']);
        $unit1 = SyllabusUnit::create(['curriculum_id' => $this->curriculum->id, 'stage_id' => $this->stage1->id, 'unit_number' => 1, 'title' => 'U1']);
        $unit2 = SyllabusUnit::create(['curriculum_id' => $this->curriculum->id, 'stage_id' => $this->stage2->id, 'unit_number' => 2, 'title' => 'U2']);
        foreach ([1, 2, 3] as $n) {
            SyllabusLesson::create(['unit_id' => $unit1->id, 'session_no' => $n, 'title' => "C1 B{$n}"]);
        }
        foreach ([4, 5] as $n) {
            SyllabusLesson::create(['unit_id' => $unit2->id, 'session_no' => $n, 'title' => "C2 B{$n}"]);
        }
    }

    public function test_new_stage_is_appended_and_sessions_continue(): void
    {
        $stage = $this->import([
            $this->row(11, unit: 'Unit 3: Friends', content: 'Bài 1'),
            $this->row(12, content: 'Bài 2'),
            $this->row(13, type: 'Mini test', content: 'Mini test Unit 3'),
        ], null, 'Chặng 3: Unit 3');

        $this->assertSame(3, $stage->position);
        $this->assertSame('Chặng 3: Unit 3', $stage->name);
        $this->assertSame([6, 7, 8], $this->sessionNumbers($stage));
        $this->assertSame($stage->id, SyllabusUnit::where('curriculum_id', $this->curriculum->id)->where('unit_number', 3)->value('stage_id'));
        $this->assertSame('mini_test', SyllabusLesson::where('title', 'Mini test Unit 3')->value('lesson_type'));
    }

    public function test_update_keeps_lesson_ids_soft_deletes_extra_and_renumbers_next_stage(): void
    {
        $first = SyllabusLesson::where('title', 'C1 B1')->firstOrFail();
        $third = SyllabusLesson::where('title', 'C1 B3')->firstOrFail();
        $proposal = SyllabusChangeProposal::create(['curriculum_id' => $this->curriculum->id, 'lesson_id' => $first->id, 'user_id' => $this->user->id,
            'new_content' => 'x', 'reason' => 'y', 'status' => 'pending']);

        $this->import([
            $this->row(11, unit: 'Unit 1: U1 mới', content: 'C1 B1 mới'),
            $this->row(12, content: 'C1 B2 mới'),
        ], $this->stage1);

        $this->assertSame('C1 B1 mới', $first->fresh()->title);
        $this->assertSoftDeleted('syllabus_lessons', ['id' => $third->id]);
        $this->assertSame($first->id, $proposal->fresh()->lesson->id);
        $this->assertSame('U1 mới', SyllabusUnit::where('unit_number', 1)->value('title'));
        $this->assertSame([1, 2], $this->sessionNumbers($this->stage1));
        $this->assertSame([3, 4], $this->sessionNumbers($this->stage2));
    }

    public function test_import_writes_one_history_row(): void
    {
        $before = Activity::count();

        $this->import([$this->row(11, unit: 'Unit 1: U1', content: 'Một buổi')], $this->stage1);

        $this->assertSame($before + 1, Activity::count());
        $log = Activity::latest('id')->firstOrFail();
        $this->assertStringStartsWith('Import chặng từ file', $log->description);
        $this->assertSame($this->curriculum->id, $log->properties['curriculum_id']);
    }

    public function test_reimporting_same_rows_is_idempotent(): void
    {
        $rows = [$this->row(11, unit: 'Unit 1: U1', content: 'A'), $this->row(12, content: 'B'), $this->row(13, content: 'C')];
        $this->import($rows, $this->stage1);

        $plan = app(StageImportPlanner::class)->plan(new StageSheet('', 'Chặng 1', 'v1', $rows, 0), $this->curriculum, $this->stage1->fresh());

        $this->assertSame(['create' => 0, 'update' => 3, 'delete' => 0, 'units' => 0], $plan->summary());
    }

    public function test_ghost_soft_deleted_lesson_with_positive_number_does_not_block_import(): void
    {
        // Buổi xóa mềm trước bản sửa MariaDB (8c782125) còn giữ số buổi dương.
        $ghost = SyllabusLesson::create(['unit_id' => SyllabusUnit::where('unit_number', 1)->value('id'), 'session_no' => 6, 'title' => 'Ma']);
        $ghost->delete();
        SyllabusLesson::withTrashed()->whereKey($ghost->id)->update(['session_no' => 6]);

        $stage = $this->import([$this->row(11, unit: 'Unit 3: Friends', content: 'Bài 1')], null, 'Chặng 3');

        $this->assertSame([6], $this->sessionNumbers($stage));
        $this->assertSame(-$ghost->id, SyllabusLesson::withTrashed()->find($ghost->id)->session_no);
    }

    /** @param  list<StageSheetRow>  $rows */
    private function import(array $rows, ?SyllabusStage $stage, string $stageName = ''): SyllabusStage
    {
        $plan = app(StageImportPlanner::class)->plan(new StageSheet('Global Success 6', $stageName, 'v1', $rows, 0), $this->curriculum, $stage);
        $this->assertTrue($plan->ok(), json_encode($plan->errors, JSON_UNESCAPED_UNICODE));

        return app(StageImporter::class)->import($plan, $this->curriculum, $this->user);
    }

    /** @return list<int> */
    private function sessionNumbers(SyllabusStage $stage): array
    {
        return SyllabusLesson::whereHas('unit', fn ($q) => $q->where('stage_id', $stage->id))->orderBy('session_no')->pluck('session_no')->all();
    }

    private function row(int $line, ?string $type = null, ?string $unit = null, ?string $content = null): StageSheetRow
    {
        return new StageSheetRow($line, $type, $unit, null, $content, null, null, null, null, null, null, null, null, null, null, null);
    }
}
