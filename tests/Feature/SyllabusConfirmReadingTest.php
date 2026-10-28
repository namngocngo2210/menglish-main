<?php

namespace Tests\Feature;

use App\Models\SyllabusAssignment;
use App\Models\SyllabusDocument;
use App\Models\SyllabusStage;
use App\Services\Syllabus\ConfirmReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** GV phải đọc tổng quan chặng + mở hết tài liệu chặng rồi mới XÁC NHẬN được (docs/kho-syllabus.md §7.3 bước 2). */
class SyllabusConfirmReadingTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private SyllabusAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        $this->buildSyllabusWorkflow();
        $this->stage1->update(['description' => 'Làm quen chữ cái và chào hỏi', 'big_test_title' => 'Big Test Làm quen']);
        $this->assignment = $this->openStage($this->stage1, ['start_date' => today(), 'assigned_at' => now()]);
    }

    public function test_read_page_shows_stage_overview_and_records_first_open_for_the_confirmer_only(): void
    {
        $this->document('Kế hoạch chặng 1', $this->stage1);

        $this->actingAs($this->lead)->get(route('syllabus.assignments.read', $this->assignment->id))
            ->assertOk()
            ->assertSee('chế độ chỉ xem')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('reading.can_confirm', false)->etc());
        $this->assertSame(0, $this->assignment->reads()->count());

        $this->actingAs($this->teacher)->get(route('syllabus.assignments.read', $this->assignment->id))
            ->assertOk()
            ->assertSee('Làm quen chữ cái và chào hỏi')
            ->assertSee('Big Test Làm quen')
            ->assertSee('Unit 1')
            ->assertSee('Kế hoạch chặng 1')
            // Quyết định 7: không lộ nội dung các buổi.
            ->assertDontSee('Bài 2')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reading.can_confirm', true)
                ->where('reading.min_seconds', ConfirmReading::OVERVIEW_MIN_SECONDS)
                ->where('reading.documents_done', false)
                ->where('stage.units.0.lessons', 6)
                ->etc());
        $openedAt = $this->assignment->reads()->sole()->opened_at;

        $this->travel(5)->minutes();
        $this->actingAs($this->teacher)->get(route('syllabus.assignments.read', $this->assignment->id))->assertOk();
        $this->assertTrue($openedAt->equalTo($this->assignment->reads()->sole()->opened_at), 'Mở lại không tính lại mốc đọc.');

        $this->actingAs($this->staff)->get(route('syllabus.assignments.read', $this->assignment->id))->assertForbidden();
    }

    public function test_old_confirm_button_without_reading_goes_to_the_read_page(): void
    {
        $this->actingAs($this->teacher)->post(route('syllabus.assignments.confirm', $this->assignment->id))
            ->assertRedirect(route('syllabus.assignments.read', $this->assignment->id));

        $this->assertFalse($this->assignment->fresh()->isConfirmed());
    }

    public function test_confirm_requires_reading_time_and_acknowledgement(): void
    {
        $this->actingAs($this->teacher)->post(route('syllabus.assignments.confirm', $this->assignment->id), ['acknowledged' => '1'])
            ->assertSessionHasErrors('reading');

        $this->actingAs($this->teacher)->get(route('syllabus.assignments.read', $this->assignment->id));
        $this->travel(ConfirmReading::OVERVIEW_MIN_SECONDS - 5)->seconds();
        $this->actingAs($this->teacher)->post(route('syllabus.assignments.confirm', $this->assignment->id), ['acknowledged' => '1'])
            ->assertSessionHasErrors(['reading' => 'Đọc tổng quan chặng ít nhất '.ConfirmReading::OVERVIEW_MIN_SECONDS.' giây.']);

        $this->travel(5)->seconds();
        $this->actingAs($this->teacher)->post(route('syllabus.assignments.confirm', $this->assignment->id), ['acknowledged' => '0'])
            ->assertSessionHasErrors('acknowledged');
        $this->assertFalse($this->assignment->fresh()->isConfirmed());

        $this->actingAs($this->teacher)->post(route('syllabus.assignments.confirm', $this->assignment->id), ['acknowledged' => '1'])
            ->assertSessionHasNoErrors();
        $assignment = $this->assignment->fresh();
        $this->assertTrue($assignment->isConfirmed());
        $this->assertSame(ConfirmReading::OVERVIEW_MIN_SECONDS, $assignment->confirm_evidence['read_seconds']);
        $this->assertSame('Đọc tổng quan 30 giây · chặng không có tài liệu', ConfirmReading::summary($assignment->confirm_evidence));
    }

    public function test_every_stage_document_must_be_viewed_before_confirming(): void
    {
        $plan = $this->document('Kế hoạch chặng 1', $this->stage1);
        // Không bắt mở: tài liệu buổi (quyết định 7), tài liệu chặng khác, tài liệu chung của giáo trình.
        $this->document('Slide buổi 1', $this->stage1, $this->stage1->units()->first()->lessons()->first()->id);
        $this->document('Kế hoạch chặng 2', $this->stage2);
        $this->document('Nội quy chung', null);

        $this->actingAs($this->teacher)->get(route('syllabus.assignments.read', $this->assignment->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('documents', 1)->where('documents.0.title', 'Kế hoạch chặng 1')->etc());
        $this->travel(ConfirmReading::OVERVIEW_MIN_SECONDS)->seconds();
        $this->actingAs($this->teacher)->post(route('syllabus.assignments.confirm', $this->assignment->id), ['acknowledged' => '1'])
            ->assertSessionHasErrors(['reading' => 'Mở và đánh dấu đã xem tài liệu chặng: Kế hoạch chặng 1.']);

        // Mở tài liệu từ trang đọc, chưa đủ thời gian thì chưa đánh dấu đã xem được.
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-view', ['class' => $this->class->id, 'scope' => 'stage', 'document' => $plan->id, 'confirm' => $this->assignment->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('returnUrl', route('syllabus.assignments.read', $this->assignment->id))->etc());
        $this->actingAs($this->teacher)->post(route('syllabus.documents.viewed', $plan->id), ['confirm' => $this->assignment->id])
            ->assertSessionHas('error');
        $this->travel(ConfirmReading::DOCUMENT_MIN_SECONDS)->seconds();
        $this->actingAs($this->teacher)->post(route('syllabus.documents.viewed', $plan->id), ['confirm' => $this->assignment->id])
            ->assertRedirect(route('syllabus.assignments.read', $this->assignment->id));

        $this->actingAs($this->teacher)->post(route('syllabus.assignments.confirm', $this->assignment->id), ['acknowledged' => '1'])
            ->assertSessionHasNoErrors();
        $evidence = $this->assignment->fresh()->confirm_evidence;
        $this->assertSame([$plan->id], array_column($evidence['documents'], 'id'));
        $this->assertSame('Đọc tổng quan 40 giây · mở 1/1 tài liệu chặng', ConfirmReading::summary($evidence));

        // Học thuật thấy bằng chứng đọc ở Giao chặng và tab Syllabus của lớp.
        $this->actingAs($this->lead)->get(route('syllabus.assignments'))
            ->assertOk()->assertSee('Đọc tổng quan 40 giây · mở 1/1 tài liệu chặng')->assertDontSee('Chờ GV xác nhận');
        $this->actingAs($this->lead)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'academic']))
            ->assertOk()->assertSee('Đọc tổng quan 40 giây · mở 1/1 tài liệu chặng')->assertDontSee('Chờ GV xác nhận');
    }

    public function test_new_teacher_after_reassignment_reads_again(): void
    {
        $this->readAndConfirm($this->teacher, $this->assignment)->assertSessionHasNoErrors();
        $this->assertNotNull($this->assignment->fresh()->confirm_evidence);

        $this->actingAs($this->lead)->put(route('syllabus.assignments.update', $this->assignment->id), ['user_id' => $this->foreignTeacher->id])
            ->assertSessionHasNoErrors();

        $assignment = $this->assignment->fresh();
        $this->assertFalse($assignment->isConfirmed());
        $this->assertNull($assignment->confirm_evidence);
    }

    private function document(string $title, ?SyllabusStage $stage, ?int $lessonId = null): SyllabusDocument
    {
        return SyllabusDocument::create([
            'curriculum_id' => $this->curriculum->id, 'stage_id' => $stage?->id, 'syllabus_lesson_id' => $lessonId,
            'title' => $title, 'file_path' => 'syllabus/'.$title.'.pdf', 'original_name' => $title.'.pdf', 'extension' => 'pdf',
            'size_bytes' => 1, 'visible_to_teachers' => true,
        ]);
    }
}
