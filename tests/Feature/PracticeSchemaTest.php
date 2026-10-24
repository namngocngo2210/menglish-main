<?php

namespace Tests\Feature;

use App\Models\PracticeAttempt;
use App\Models\PracticeAttemptItem;
use App\Models\PracticeBlueprint;
use App\Models\QuestionBankPassage;
use App\Models\QuestionBankQuestion;
use App\Models\Student;
use App\Support\Practice\PracticeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PracticeSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function passage(): QuestionBankPassage
    {
        return QuestionBankPassage::create([
            'grade' => 7, 'term' => 'ck1', 'skill' => 'Đọc hiểu', 'type' => 'Đọc hiểu – trả lời câu hỏi',
            'content' => 'Text', 'source_key' => 'p-1',
        ]);
    }

    private function question(array $overrides = []): QuestionBankQuestion
    {
        static $n = 0;
        $n++;

        return QuestionBankQuestion::create($overrides + [
            'position' => 1, 'grade' => 7, 'term' => 'ck1', 'skill' => 'Viết', 'type' => 'Câu đồng nghĩa',
            'stem' => 'Stem', 'options' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'], 'answer' => 'A',
            'status' => 'draft', 'source_key' => 'q-'.$n,
        ]);
    }

    public function test_default_blueprints_cover_every_grade_and_term(): void
    {
        $this->assertSame(16, PracticeBlueprint::count());
        $bp = PracticeBlueprint::forGradeTerm(7, 'ck1');
        $this->assertTrue($bp->exists);
        $this->assertSame(45, $bp->duration_minutes);
        $this->assertSame(40, array_sum(array_column($bp->items, 'count')));
    }

    public function test_question_belongs_to_passage_and_orders_by_position(): void
    {
        $passage = $this->passage();
        $this->question(['passage_id' => $passage->id, 'position' => 2, 'status' => 'approved']);
        $this->question(['passage_id' => $passage->id, 'position' => 1]);

        $this->assertSame([1, 2], $passage->questions->pluck('position')->all());
        $this->assertSame($passage->id, $passage->questions->first()->passage->id);
        $this->assertSame(['approved'], QuestionBankQuestion::approved()->pluck('status')->all());
    }

    public function test_for_grade_term_falls_back_to_defaults(): void
    {
        PracticeBlueprint::where('grade', 6)->where('term', 'gk1')->delete();

        $bp = PracticeBlueprint::forGradeTerm(6, 'gk1');

        $this->assertFalse($bp->exists);
        $this->assertSame(PracticeCatalog::DEFAULT_ITEMS, $bp->items);
        $this->assertSame(45, $bp->duration_minutes);
    }

    public function test_attempt_item_keeps_snapshot_after_question_deleted(): void
    {
        $student = Student::create(['code' => 'HS-PRAC-1', 'name' => 'Học viên', 'phone' => '0391000001', 'status' => 'active']);
        $q = $this->question();
        $attempt = PracticeAttempt::create([
            'student_id' => $student->id, 'grade' => 7, 'term' => 'ck1', 'status' => PracticeAttempt::IN_PROGRESS,
            'started_at' => now(), 'deadline_at' => now()->addMinutes(45), 'total' => 1,
        ]);
        $item = PracticeAttemptItem::create([
            'attempt_id' => $attempt->id, 'question_id' => $q->id, 'position' => 1, 'skill' => 'Viết',
            'type' => 'Câu đồng nghĩa', 'snapshot' => ['stem' => 'Stem', 'answer' => 'A'],
        ]);

        $q->delete();
        $item->refresh();

        $this->assertNull($item->question_id);
        $this->assertSame('Stem', $item->snapshot['stem']);
        $this->assertCount(1, $student->practiceAttempts);
        $this->assertSame(0, $student->fresh()->grade_adjust);
    }
}
