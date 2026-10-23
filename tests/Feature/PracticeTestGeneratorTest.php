<?php

namespace Tests\Feature;

use App\Models\PracticeBlueprint;
use App\Models\QuestionBankPassage;
use App\Models\QuestionBankQuestion;
use App\Services\Practice\PracticeTestGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PracticeTestGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const PH = ['Ngữ âm', 'Phát âm'];

    private const RC = ['Đọc hiểu', 'Đọc hiểu – trả lời câu hỏi'];

    private function q(array $skillType, array $attrs = []): QuestionBankQuestion
    {
        static $n = 0;
        $n++;

        return QuestionBankQuestion::create($attrs + [
            'grade' => 7, 'term' => 'gk1', 'skill' => $skillType[0], 'type' => $skillType[1],
            'stem' => "Q$n", 'options' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'],
            'answer' => 'A', 'status' => 'approved', 'position' => 1, 'source_key' => "q-$n",
        ]);
    }

    private function passage(int $size, string $status = 'approved'): QuestionBankPassage
    {
        $p = QuestionBankPassage::create([
            'grade' => 7, 'term' => 'gk1', 'skill' => self::RC[0], 'type' => self::RC[1], 'content' => 'text', 'source_key' => 'p-'.uniqid(),
        ]);
        for ($i = 1; $i <= $size; $i++) {
            $this->q(self::RC, ['passage_id' => $p->id, 'position' => $i, 'status' => $status]);
        }

        return $p;
    }

    private function blueprint(array $items): PracticeBlueprint
    {
        return new PracticeBlueprint(['grade' => 7, 'term' => 'gk1', 'duration_minutes' => 45, 'items' => $items]);
    }

    private function item(array $st, int $count): array
    {
        return ['skill' => $st[0], 'type' => $st[1], 'count' => $count];
    }

    public function test_orders_by_skill_and_takes_whole_fitting_passage(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->q(self::PH);
        }
        $big = $this->passage(5);
        $this->passage(8); // too big while a fitting passage exists

        // blueprint listed out of skill order on purpose
        $result = (new PracticeTestGenerator)->generate($this->blueprint([
            $this->item(self::RC, 5), $this->item(self::PH, 2),
        ]));

        $this->assertCount(7, $result);
        $this->assertSame(['Ngữ âm', 'Ngữ âm'], $result->take(2)->pluck('skill')->all());
        $rest = $result->slice(2)->values();
        $this->assertSame([$big->id], $rest->pluck('passage_id')->unique()->all());
        $this->assertSame([1, 2, 3, 4, 5], $rest->pluck('position')->all());
        $this->assertTrue($rest->every(fn ($q) => $q->relationLoaded('passage')));
    }

    public function test_drafts_and_hidden_are_never_returned(): void
    {
        $this->q(self::PH, ['status' => 'draft']);
        $this->q(self::PH, ['status' => 'hidden']);
        $ok = $this->q(self::PH);
        $this->passage(2, 'draft');

        $result = (new PracticeTestGenerator)->generate($this->blueprint([
            $this->item(self::PH, 5), $this->item(self::RC, 5),
        ]));

        $this->assertSame([$ok->id], $result->pluck('id')->all());
    }

    public function test_prefers_questions_not_seen_recently(): void
    {
        $qs = collect(range(1, 5))->map(fn () => $this->q(self::PH));
        $recent = $qs->take(3)->pluck('id')->all();

        for ($i = 0; $i < 10; $i++) {
            $result = (new PracticeTestGenerator)->generate($this->blueprint([$this->item(self::PH, 2)]), $recent);
            $this->assertEqualsCanonicalizing($qs->slice(3)->pluck('id')->all(), $result->pluck('id')->all());
        }
    }

    public function test_shortage_returns_what_exists(): void
    {
        $this->q(self::PH);

        $result = (new PracticeTestGenerator)->generate($this->blueprint([$this->item(self::PH, 2)]));

        $this->assertCount(1, $result);
    }

    public function test_duplicate_rows_ask_for_more_without_repeats(): void
    {
        $ids = collect(range(1, 5))->map(fn () => $this->q(self::PH)->id)->all();

        $result = (new PracticeTestGenerator)->generate($this->blueprint([
            $this->item(self::PH, 2), $this->item(self::PH, 2), $this->item(self::PH, 3),
        ]));

        $this->assertCount(5, $result);
        $this->assertEqualsCanonicalizing($ids, $result->pluck('id')->all());
    }

    public function test_zero_count_row_is_skipped_and_mixed_pool_uses_units(): void
    {
        $this->q(self::RC);
        $p = $this->passage(2);

        $none = (new PracticeTestGenerator)->generate($this->blueprint([$this->item(self::RC, 0)]));
        $this->assertCount(0, $none);

        $result = (new PracticeTestGenerator)->generate($this->blueprint([$this->item(self::RC, 3)]));
        $this->assertCount(3, $result);
        $this->assertSame(2, $result->where('passage_id', $p->id)->count());
    }

    public function test_has_questions(): void
    {
        $generator = new PracticeTestGenerator;
        $this->assertFalse($generator->hasQuestions(9, 'gk1'));

        $this->q(self::PH, ['status' => 'draft', 'grade' => 9]);
        $this->assertFalse($generator->hasQuestions(9, 'gk1'));

        $this->q(self::PH, ['grade' => 9]);
        $this->assertTrue($generator->hasQuestions(9, 'gk1'));
        $this->assertFalse($generator->hasQuestions(9, 'ck1'));
    }
}
