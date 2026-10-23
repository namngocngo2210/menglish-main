<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PracticeAttempt;
use App\Models\PracticeAttemptItem;
use App\Models\QuestionBankQuestion;
use App\Models\Student;
use App\Models\User;
use App\Services\Practice\PracticeReport;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Phân tích câu hỏi (docs/luyen-tap-ngan-hang-cau-hoi.md §7). */
class QuestionAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $lead;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-QA', 'is_active' => true]);
        $this->lead = $this->staff(Roles::ACADEMIC_LEAD);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function question(array $attributes = []): QuestionBankQuestion
    {
        $this->seq++;

        return QuestionBankQuestion::query()->create(array_merge([
            'position' => 1, 'grade' => 7, 'term' => 'ck1', 'skill' => 'Ngữ âm', 'type' => 'Phát âm',
            'stem' => "Stem {$this->seq}", 'options' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'],
            'answer' => 'A', 'status' => 'approved', 'source_key' => "QA-{$this->seq}",
        ], $attributes));
    }

    /**
     * Làm câu $question $chosen.count lần, mỗi lần một lượt nộp riêng; null = bỏ trống.
     *
     * @param  list<?string>  $chosen
     */
    private function answer(QuestionBankQuestion $question, array $chosen, string $status = PracticeAttempt::SUBMITTED): void
    {
        foreach ($chosen as $choice) {
            $this->seq++;
            $student = Student::create([
                'name' => "HV {$this->seq}", 'code' => "HV-QA-{$this->seq}", 'phone' => '0933'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
                'status' => 'studying', 'branch_id' => $this->branch->id, 'dob' => '2014-03-10',
            ]);
            $attempt = PracticeAttempt::create([
                'student_id' => $student->id, 'grade' => 7, 'term' => 'ck1', 'status' => $status,
                'started_at' => now()->subHour(), 'deadline_at' => now()->subMinutes(15),
                'submitted_at' => $status === PracticeAttempt::SUBMITTED ? now() : null, 'total' => 1,
            ]);
            PracticeAttemptItem::create([
                'attempt_id' => $attempt->id, 'question_id' => $question->id, 'position' => 1,
                'skill' => $question->skill, 'type' => $question->type, 'snapshot' => ['stem' => $question->stem],
                'chosen' => $choice, 'is_correct' => $status === PracticeAttempt::SUBMITTED ? $choice === $question->answer : null,
            ]);
        }
    }

    public function test_wrong_rate_and_top_wrong_choice(): void
    {
        $hard = $this->question();
        // 2 đúng (A), 8 sai: 5 chọn B, 2 chọn C, 1 bỏ trống.
        $this->answer($hard, ['A', 'A', 'B', 'B', 'B', 'B', 'B', 'C', 'C', null]);

        $rows = app(PracticeReport::class)->questionStats([])->items();

        $this->assertCount(1, $rows);
        $this->assertSame($hard->id, $rows[0]['question_id']);
        $this->assertSame(10, $rows[0]['attempts']);
        $this->assertSame(8, $rows[0]['wrong']);
        $this->assertSame(0.8, $rows[0]['wrong_rate']);
        $this->assertSame('B', $rows[0]['top_wrong_choice']);
        $this->assertSame('A', $rows[0]['answer']);
    }

    public function test_top_wrong_choice_is_null_when_all_wrong_answers_are_blank_and_ties_are_alphabetical(): void
    {
        $blank = $this->question();
        $this->answer($blank, [null, null, null, null, null, null, null, null, null, 'A']);
        $tie = $this->question();
        $this->answer($tie, ['D', 'D', 'C', 'C', 'A', 'A', 'A', 'A', 'A', 'A']);

        $byId = collect(app(PracticeReport::class)->questionStats([])->items())->keyBy('question_id');

        $this->assertNull($byId[$blank->id]['top_wrong_choice']);
        $this->assertSame(0.9, $byId[$blank->id]['wrong_rate']);
        $this->assertSame('C', $byId[$tie->id]['top_wrong_choice']);
    }

    public function test_questions_under_minimum_attempts_or_from_unsubmitted_attempts_are_excluded(): void
    {
        $few = $this->question();
        $this->answer($few, array_fill(0, 9, 'B'));
        $mixed = $this->question();
        $this->answer($mixed, array_fill(0, 9, 'B'));
        $this->answer($mixed, ['B'], PracticeAttempt::IN_PROGRESS);

        $this->assertSame([], app(PracticeReport::class)->questionStats([])->items());
        $this->assertCount(2, app(PracticeReport::class)->questionStats([], 9)->items());
    }

    public function test_sorted_by_wrong_rate_then_attempts_and_filtered(): void
    {
        $easy = $this->question(['skill' => 'Viết', 'type' => 'Sắp xếp']);
        $this->answer($easy, array_merge(['A'], array_fill(0, 9, 'B'))); // 0.9
        $hardest = $this->question();
        $this->answer($hardest, array_fill(0, 10, 'B')); // 1.0
        $hardMore = $this->question(['grade' => 8]);
        $this->answer($hardMore, array_fill(0, 12, 'B')); // 1.0, more attempts

        $report = app(PracticeReport::class);
        $this->assertSame([$hardMore->id, $hardest->id, $easy->id], collect($report->questionStats([])->items())->pluck('question_id')->all());
        $this->assertSame([$hardMore->id], collect($report->questionStats(['grade' => 8])->items())->pluck('question_id')->all());
        $this->assertSame([$easy->id], collect($report->questionStats(['skill' => 'Viết'])->items())->pluck('question_id')->all());
        $this->assertSame([$easy->id], collect($report->questionStats(['type' => 'Sắp xếp'])->items())->pluck('question_id')->all());
        $this->assertSame(['grade' => null, 'term' => null, 'skill' => null, 'type' => 'x'], $report->questionFilters(['grade' => '5', 'term' => 'zz', 'skill' => 'Nope', 'type' => ' x ']));
    }

    public function test_items_before_the_last_edit_are_not_counted(): void
    {
        $this->travelTo(now()->subDays(3));
        $q = $this->question();
        $this->answer($q, array_fill(0, 10, 'B')); // khoá đáp án sai (A) → 10 lượt "sai"
        $this->travelBack();

        $this->travelTo(now()->subDay());
        $q->update(['answer' => 'B', 'edited_at' => now()]); // Học thuật sửa khoá A → B
        $this->travelBack();

        $report = app(PracticeReport::class);
        $this->assertSame([], $report->questionStats([])->items());

        // Lượt mới sau lần sửa được tính lại từ đầu.
        $this->answer($q->fresh(), array_merge(['C'], array_fill(0, 9, 'B')));
        $rows = $report->questionStats([])->items();

        $this->assertCount(1, $rows);
        $this->assertSame(10, $rows[0]['attempts']);
        $this->assertSame(1, $rows[0]['wrong']);
        $this->assertSame('C', $rows[0]['top_wrong_choice']);
        $this->assertSame($q->fresh()->edited_at->toDateString(), $rows[0]['edited_at']);

        $this->actingAs($this->lead)->get(route('question-bank.analysis'))
            ->assertOk()
            ->assertSee('Tính từ lần sửa '.$q->fresh()->edited_at->format('d/m/Y'));
    }

    public function test_query_count_is_constant(): void
    {
        $report = app(PracticeReport::class);
        $count = function () use ($report) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $report->questionStats([]);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->answer($this->question(), array_fill(0, 10, 'B'));
        $one = $count();
        foreach (range(1, 3) as $_) {
            $this->answer($this->question(), array_merge(['A'], array_fill(0, 10, 'C')));
        }

        $this->assertSame($one, $count());
    }

    public function test_page_lists_rows_linking_to_edit_modal(): void
    {
        $q = $this->question();
        $this->answer($q, array_fill(0, 10, 'B'));

        $this->actingAs($this->lead)->get(route('question-bank.analysis'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('QuestionBank/Analysis')
                ->where('canManage', true)
                ->where('questions.data.0.question_id', $q->id)
                ->where('questions.data.0.wrong_rate', 1));
        $this->assertStringEndsWith("/question-bank/questions/{$q->id}", route('question-bank.questions.edit', $q->id));

        // Link sửa mang `return` = trang phân tích đang xem (kể cả bộ lọc) → mở trang đầy đủ, lưu xong quay về đây.
        $this->actingAs($this->lead)->get(route('question-bank.analysis', ['grade' => 7]))
            ->assertSee('/question-bank/questions/'.$q->id.'?return='.e(rawurlencode('/question-bank/analysis?grade=7')), false);
    }

    public function test_teacher_is_forbidden(): void
    {
        $this->actingAs($this->staff(Roles::TEACHER_FULLTIME))->get(route('question-bank.analysis'))->assertForbidden();
    }
}
