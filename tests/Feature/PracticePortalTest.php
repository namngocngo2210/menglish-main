<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PracticeAttempt;
use App\Models\QuestionBankPassage;
use App\Models\QuestionBankQuestion;
use App\Models\Student;
use App\Models\User;
use App\Support\Navigation\SidebarMenu;
use App\Support\Practice\PracticeCatalog;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Luyện tập ở cổng học viên (docs/luyen-tap-ngan-hang-cau-hoi.md §6). */
class PracticePortalTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $user;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        // năm học 2026–2027: sinh 2014 → khối 7
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(9, 0));

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-LT', 'is_active' => true]);
        $this->user = $this->portalUser();
        $this->student = $this->makeStudent($this->user, '2014-03-10');
        $this->seedBank(7, 'ck1');
    }

    private function portalUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::STUDENT);

        return $user;
    }

    private function staff(string $role, Branch $branch): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeStudent(User $user, ?string $dob): Student
    {
        static $n = 0;
        $n++;

        return Student::create([
            'user_id' => $user->id,
            'name' => "Học viên luyện tập $n",
            'code' => "HV-LT-$n",
            'phone' => '09110000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'status' => 'active',
            'branch_id' => $this->branch->id,
            'dob' => $dob,
        ]);
    }

    /** Câu đã duyệt đủ khung mặc định (40 câu); dòng "Điền từ – đoạn văn" là ngữ liệu 5 câu. Đáp án luôn B. */
    private function seedBank(int $grade, string $term): void
    {
        static $n = 0;
        foreach (PracticeCatalog::DEFAULT_ITEMS as $row) {
            $passageId = null;
            if ($row['type'] === 'Điền từ – đoạn văn') {
                $passageId = QuestionBankPassage::create([
                    'grade' => $grade, 'term' => $term, 'skill' => $row['skill'], 'type' => $row['type'],
                    'instruction' => 'Read the passage', 'content' => 'My [school] is big.', 'source_key' => 'lt-p-'.(++$n),
                ])->id;
            }
            for ($i = 1; $i <= $row['count']; $i++) {
                $n++;
                QuestionBankQuestion::create([
                    'passage_id' => $passageId, 'position' => $i,
                    'grade' => $grade, 'term' => $term, 'skill' => $row['skill'], 'type' => $row['type'],
                    'instruction' => $passageId ? null : 'Choose the best answer', 'knowledge' => 'Thì hiện tại',
                    'stem' => "Question $n", 'options' => ['A' => 'apple', 'B' => 'banana', 'C' => 'cherry', 'D' => 'durian'],
                    'answer' => 'B', 'status' => 'approved', 'source_key' => "lt-q-$n",
                ]);
            }
        }
    }

    private function startAttempt(): PracticeAttempt
    {
        $this->actingAs($this->user)
            ->post(route('portal.student.practice.start'), ['student_id' => $this->student->id, 'term' => 'ck1'])
            ->assertRedirect();

        return PracticeAttempt::query()->latest('id')->firstOrFail();
    }

    public function test_index_shows_grade_and_term_cards(): void
    {
        $this->actingAs($this->user)
            ->get(route('portal.student.practice', ['studentId' => $this->student->id]))
            ->assertOk()
            ->assertSee('Khối 7')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Practice')
                ->where('grade', 7)
                ->where('schoolYear', '2026–2027')
                ->where('canStart', true)
                ->has('terms', 4)
                ->where('terms.0.key', 'gk1')
                ->where('terms.0.available', false)
                ->where('terms.1.key', 'ck1')
                ->where('terms.1.available', true)
                ->where('terms.1.questionCount', 40)
                ->where('terms.1.duration', 45)
                ->where('terms.1.inProgressAttemptId', null)
                ->where('history.total', 0));
    }

    public function test_student_without_dob_sees_friendly_message_and_cannot_start(): void
    {
        $user = $this->portalUser();
        $student = $this->makeStudent($user, null);

        $this->actingAs($user)
            ->get(route('portal.student.practice', ['studentId' => $student->id]))
            ->assertOk()
            ->assertSee('Chưa xác định khối')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('grade', null)
                ->where('canStart', false)
                ->where('terms.1.available', false));

        $this->actingAs($user)
            ->from(route('portal.student.practice', ['studentId' => $student->id]))
            ->post(route('portal.student.practice.start'), ['student_id' => $student->id, 'term' => 'ck1'])
            ->assertRedirect(route('portal.student.practice', ['studentId' => $student->id]))
            ->assertSessionHas('error');
        $this->assertSame(0, PracticeAttempt::count());
    }

    public function test_start_opens_take_page_without_answers(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('portal.student.practice.start'), ['student_id' => $this->student->id, 'term' => 'ck1']);
        $attempt = PracticeAttempt::query()->firstOrFail();
        $response->assertRedirect(route('portal.student.practice.attempt', $attempt->id));

        $this->actingAs($this->user)
            ->get(route('portal.student.practice.attempt', $attempt->id))
            ->assertOk()
            ->assertSee('Bắt đầu làm bài')
            ->assertSee('Rời bài: 0/3')
            ->assertInertia(function (AssertableInertia $page) {
                $page->component('Portal/PracticeTake')
                    ->has('items', 40)
                    ->where('attempt.remainingSeconds', 45 * 60)
                    ->where('attempt.violationCount', 0)
                    ->where('attempt.maxViolations', 3)
                    ->where('items.0.position', 1)
                    ->where('items.0.options.B', 'banana');
                $json = json_encode($page->toArray()['props'], JSON_UNESCAPED_UNICODE);
                $this->assertStringNotContainsString('"answer"', $json);
                $this->assertStringNotContainsString('"is_correct"', $json);
            });

        // bấm lại → làm tiếp lượt đang làm, và thẻ kỳ hiện "Làm tiếp"
        $this->actingAs($this->user)
            ->post(route('portal.student.practice.start'), ['student_id' => $this->student->id, 'term' => 'ck1'])
            ->assertRedirect(route('portal.student.practice.attempt', $attempt->id));
        $this->actingAs($this->user)
            ->get(route('portal.student.practice', ['studentId' => $this->student->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('terms.1.inProgressAttemptId', $attempt->id));
    }

    public function test_start_without_questions_flashes_error(): void
    {
        $this->actingAs($this->user)
            ->from(route('portal.student.practice'))
            ->post(route('portal.student.practice.start'), ['student_id' => $this->student->id, 'term' => 'gk1'])
            ->assertRedirect(route('portal.student.practice'))
            ->assertSessionHas('error');
        $this->assertSame(0, PracticeAttempt::count());
    }

    public function test_answer_is_saved_and_rejected_after_deadline_grace(): void
    {
        $attempt = $this->startAttempt();

        $this->actingAs($this->user)
            ->postJson(route('portal.student.practice.answer', $attempt->id), ['position' => 1, 'choice' => 'C'])
            ->assertOk()
            ->assertExactJson(['ok' => true]);
        $this->assertSame('C', $attempt->items()->where('position', 1)->value('chosen'));

        $this->actingAs($this->user)
            ->postJson(route('portal.student.practice.answer', $attempt->id), ['position' => 1, 'choice' => 'E'])
            ->assertStatus(422);

        $this->travelTo($attempt->deadline_at->copy()->addSeconds(31));
        $this->actingAs($this->user)
            ->postJson(route('portal.student.practice.answer', $attempt->id), ['position' => 2, 'choice' => 'A'])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
        $this->assertNull($attempt->items()->where('position', 2)->value('chosen'));
    }

    public function test_third_violation_submits_attempt(): void
    {
        $attempt = $this->startAttempt();
        $url = route('portal.student.practice.violation', $attempt->id);

        $this->actingAs($this->user)->postJson($url, ['type' => 'hidden'])->assertExactJson(['count' => 1, 'submitted' => false]);
        $this->actingAs($this->user)->postJson($url, ['type' => 'blur'])->assertExactJson(['count' => 2, 'submitted' => false]);
        $this->actingAs($this->user)->postJson($url, ['type' => 'fullscreen_exit'])->assertExactJson(['count' => 3, 'submitted' => true]);

        $attempt->refresh();
        $this->assertSame(PracticeAttempt::SUBMITTED, $attempt->status);
        $this->assertSame('violations', $attempt->submit_reason);

        // vi phạm tới sau khi đã nộp: không đổi gì
        $this->actingAs($this->user)->postJson($url, ['type' => 'hidden'])->assertExactJson(['count' => 3, 'submitted' => true]);
    }

    public function test_submit_shows_result_with_answers_and_score(): void
    {
        $attempt = $this->startAttempt();
        $this->actingAs($this->user)
            ->postJson(route('portal.student.practice.answer', $attempt->id), ['position' => 1, 'choice' => 'B'])
            ->assertOk();
        $this->actingAs($this->user)
            ->postJson(route('portal.student.practice.answer', $attempt->id), ['position' => 2, 'choice' => 'A'])
            ->assertOk();

        $this->actingAs($this->user)
            ->post(route('portal.student.practice.submit', $attempt->id))
            ->assertRedirect(route('portal.student.practice.attempt', $attempt->id));

        $attempt->refresh();
        $this->assertSame(PracticeAttempt::SUBMITTED, $attempt->status);
        $this->assertNull($attempt->submit_reason);

        $this->actingAs($this->user)
            ->get(route('portal.student.practice.attempt', $attempt->id))
            ->assertOk()
            ->assertSee('Đáp án đúng')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/PracticeResult')
                ->where('attempt.score', 0.25)
                ->where('attempt.correct', 1)
                ->where('attempt.total', 40)
                ->where('attempt.skill_scores.0.skill', 'Ngữ âm')
                ->where('attempt.skill_scores.0.correct', 1)
                ->where('attempt.skill_scores.0.total', 4)
                ->has('items', 40)
                ->where('items.0.chosen', 'B')
                ->where('items.0.answer', 'B')
                ->where('items.0.is_correct', true)
                ->where('items.1.is_correct', false)
                ->where('items.2.chosen', null)
                ->where('items.2.is_correct', false));

        // nộp lần 2: idempotent, điểm không đổi
        $this->actingAs($this->user)->post(route('portal.student.practice.submit', $attempt->id))->assertRedirect();
        $this->assertSame('0.25', $attempt->fresh()->score);

        $this->actingAs($this->user)
            ->get(route('portal.student.practice', ['studentId' => $this->student->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('history.total', 1)
                ->where('history.data.0.id', $attempt->id)
                ->where('history.data.0.term_label', 'Cuối kì 1')
                ->where('history.data.0.score', 0.25)
                ->where('history.data.0.submit_reason', null));
    }

    public function test_submit_after_deadline_is_timeout(): void
    {
        $attempt = $this->startAttempt();
        $this->travelTo($attempt->deadline_at->copy()->addSeconds(5));

        $this->actingAs($this->user)->post(route('portal.student.practice.submit', $attempt->id))->assertRedirect();

        $this->assertSame('timeout', $attempt->fresh()->submit_reason);
    }

    public function test_timer_submit_just_before_deadline_is_timeout(): void
    {
        $attempt = $this->startAttempt();
        $this->travelTo($attempt->deadline_at->copy()->subSecond());

        $this->actingAs($this->user)
            ->post(route('portal.student.practice.submit', $attempt->id), ['timeout' => true])
            ->assertRedirect(route('portal.student.practice.attempt', $attempt->id));

        $attempt->refresh();
        $this->assertSame('timeout', $attempt->submit_reason);
        $this->assertTrue($attempt->auto_submitted);
    }

    public function test_timeout_flag_sent_too_early_is_a_manual_submit(): void
    {
        $attempt = $this->startAttempt();
        $this->travelTo($attempt->deadline_at->copy()->subSeconds(60));

        $this->actingAs($this->user)->post(route('portal.student.practice.submit', $attempt->id), ['timeout' => true])->assertRedirect();

        $attempt->refresh();
        $this->assertSame(PracticeAttempt::SUBMITTED, $attempt->status);
        $this->assertNull($attempt->submit_reason);
        $this->assertFalse($attempt->auto_submitted);
    }

    public function test_take_page_rounds_remaining_seconds_up(): void
    {
        $attempt = $this->startAttempt();
        $this->travelTo($attempt->deadline_at->copy()->subSeconds(10)->addMilliseconds(400));

        $this->actingAs($this->user)
            ->get(route('portal.student.practice.attempt', $attempt->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('attempt.remainingSeconds', 10));
    }

    public function test_opening_expired_attempt_finalizes_it_and_shows_result(): void
    {
        $attempt = $this->startAttempt();
        $this->travelTo($attempt->deadline_at->copy()->addSeconds(31));

        $this->actingAs($this->user)
            ->get(route('portal.student.practice.attempt', $attempt->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/PracticeResult')
                ->where('attempt.submit_reason', 'timeout')
                ->where('attempt.auto_submitted', true));
    }

    public function test_another_family_gets_404_on_every_attempt_route(): void
    {
        $attempt = $this->startAttempt();
        $other = $this->portalUser();
        $this->makeStudent($other, '2014-01-01');

        $this->actingAs($other)->get(route('portal.student.practice.attempt', $attempt->id))->assertNotFound();
        $this->actingAs($other)->postJson(route('portal.student.practice.answer', $attempt->id), ['position' => 1, 'choice' => 'A'])->assertNotFound();
        $this->actingAs($other)->postJson(route('portal.student.practice.violation', $attempt->id), ['type' => 'hidden'])->assertNotFound();
        $this->actingAs($other)->post(route('portal.student.practice.submit', $attempt->id))->assertNotFound();
        $this->actingAs($other)->post(route('portal.student.practice.start'), ['student_id' => $this->student->id, 'term' => 'ck1'])->assertNotFound();
        $this->actingAs($other)->get(route('portal.student.practice.attempt', 999999))->assertNotFound();

        $attempt->refresh();
        $this->assertSame(PracticeAttempt::IN_PROGRESS, $attempt->status);
        $this->assertSame(0, $attempt->violation_count);
        $this->assertNull($attempt->items()->where('position', 1)->value('chosen'));
    }

    public function test_staff_outside_scope_gets_404(): void
    {
        $attempt = $this->startAttempt();
        $this->actingAs($this->user)->post(route('portal.student.practice.submit', $attempt->id));
        $otherBranch = Branch::create(['name' => 'Hà Đông', 'code' => 'HD-LT', 'is_active' => true]);
        $lead = $this->staff(Roles::ACADEMIC_LEAD, $otherBranch);

        $this->actingAs($lead)->get(route('portal.student.practice.attempt', $attempt->id))->assertNotFound();
        $this->actingAs($lead)->post(route('portal.student.practice.start'), ['student_id' => $this->student->id, 'term' => 'ck1'])->assertNotFound();
    }

    public function test_staff_in_scope_reads_results_but_cannot_write(): void
    {
        $attempt = $this->startAttempt();
        $this->actingAs($this->user)->post(route('portal.student.practice.submit', $attempt->id));
        $lead = $this->staff(Roles::ACADEMIC_LEAD, $this->branch);

        $this->actingAs($lead)
            ->get(route('portal.student.practice.attempt', $attempt->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Portal/PracticeResult'));
        $this->actingAs($lead)
            ->get(route('portal.student.practice', ['studentId' => $this->student->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canStart', false)->where('history.total', 1));

        $this->actingAs($lead)
            ->post(route('portal.student.practice.start'), ['student_id' => $this->student->id, 'term' => 'ck1'])
            ->assertForbidden();
        $this->actingAs($lead)
            ->postJson(route('portal.student.practice.violation', $attempt->id), ['type' => 'hidden'])
            ->assertForbidden();
        $this->assertSame(1, PracticeAttempt::count());
    }

    public function test_staff_opening_in_progress_attempt_never_sees_answers(): void
    {
        $attempt = $this->startAttempt();
        $lead = $this->staff(Roles::ACADEMIC_LEAD, $this->branch);

        $this->actingAs($lead)
            ->get(route('portal.student.practice.attempt', $attempt->id))
            ->assertRedirect(route('portal.student.practice', ['studentId' => $this->student->id]));
    }

    public function test_portal_navigation_links_to_practice(): void
    {
        $routes = collect(app(SidebarMenu::class)->groupsFor($this->user->fresh()))
            ->flatMap(fn (array $g) => collect($g['items'])->pluck('route'))->all();
        $this->assertContains('portal.student.practice', $routes);

        // thanh điều hướng đáy (cổng trên điện thoại)
        $this->actingAs($this->user)
            ->get(route('portal.student.home', ['studentId' => $this->student->id]))
            ->assertOk()
            ->assertSee('Luyện tập');
    }
}
