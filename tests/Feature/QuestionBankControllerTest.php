<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\QuestionBankPassage;
use App\Models\QuestionBankQuestion;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithInertia;
use Tests\Support\QuestionBankZip as Zip;
use Tests\TestCase;

/** Màn Ngân hàng câu hỏi: danh sách / lọc, duyệt hàng loạt, sửa câu / ngữ liệu, import ZIP. */
class QuestionBankControllerTest extends TestCase
{
    use InteractsWithInertia;
    use RefreshDatabase;

    private User $lead;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-24 09:00:00'));
        Storage::fake('public');

        $this->lead = $this->userWithRole(Roles::ACADEMIC_LEAD);
    }

    protected function tearDown(): void
    {
        Zip::cleanup();
        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        $branch = Branch::query()->firstOrCreate(['code' => 'QB-CG'], ['name' => 'Cầu Giấy', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $user->assignRole($role);

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function question(array $attributes = []): QuestionBankQuestion
    {
        $this->seq++;

        return QuestionBankQuestion::query()->create(array_merge([
            'position' => 1,
            'grade' => 7,
            'term' => 'gk1',
            'skill' => 'Từ vựng & Ngữ pháp',
            'type' => 'Chọn đáp án đúng',
            'stem' => "Stem {$this->seq} _____.",
            'options' => ['A' => 'go', 'B' => 'goes', 'C' => 'going', 'D' => 'gone'],
            'answer' => 'B',
            'status' => 'draft',
            'source_key' => "T-{$this->seq}",
        ], $attributes));
    }

    private function passage(string $content = 'My village is (1) _____ and (2) _____.'): QuestionBankPassage
    {
        $this->seq++;

        return QuestionBankPassage::query()->create([
            'grade' => 7, 'term' => 'gk1', 'skill' => 'Đọc hiểu', 'type' => 'Điền từ – đoạn văn',
            'instruction' => 'Read the passage.', 'content' => $content, 'source_key' => "P-{$this->seq}",
        ]);
    }

    public function test_only_question_bank_viewers_can_open_the_index(): void
    {
        $this->actingAs($this->lead)->get(route('question-bank.index'))->assertOk()
            ->assertSee('Ngân hàng câu hỏi');

        $this->actingAs($this->userWithRole(Roles::ACADEMIC_STAFF))->get(route('question-bank.index'))->assertForbidden();
        $this->actingAs($this->userWithRole(Roles::TEACHER_FULLTIME))->get(route('question-bank.index'))->assertForbidden();

        auth()->logout();
        $this->get(route('question-bank.index'))->assertRedirect(route('login'));
    }

    public function test_question_bank_tab_in_syllabus_workspace_only_for_viewers(): void
    {
        $html = $this->actingAs($this->lead)->get(route('question-bank.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-workspace-tabs="syllabus"', $html);
        $this->assertMatchesRegularExpression('#<a(?=[^>]*href="'.preg_quote(route('question-bank.index'), '#').'")[^>]*aria-current="page"#', $html);
        $this->assertMatchesRegularExpression('/<a(?=[^>]*data-menu-item="syllabus")[^>]*aria-current="page"/', $html);

        // Học vụ xem được workspace Giáo trình nhưng không có tab Ngân hàng câu hỏi.
        $html = $this->actingAs($this->userWithRole(Roles::ACADEMIC_STAFF))->get(route('syllabus.teaching-stages'))->assertOk()->getContent();
        $this->assertStringContainsString('data-workspace-tabs="syllabus"', $html);
        $this->assertStringNotContainsString('href="'.route('question-bank.index').'"', $html);
    }

    public function test_filters_narrow_the_list_and_rows_carry_answers_and_passage(): void
    {
        $passage = $this->passage();
        $this->question([
            'passage_id' => $passage->id, 'skill' => 'Đọc hiểu', 'type' => 'Điền từ – đoạn văn',
            'stem' => 'He [lives] near here.', 'answer' => 'C', 'status' => 'approved',
        ]);
        $this->question(['grade' => 8]);
        $this->question(['term' => 'ck1']);
        $this->question(['skill' => 'Ngữ âm', 'type' => 'Phát âm']);
        $this->question(['status' => 'hidden']);

        $total = fn (array $filters, int $expected) => $this->actingAs($this->lead)
            ->get(route('question-bank.index', $filters))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('QuestionBank/Index')->where('questions.total', $expected));

        $total([], 5);
        $total(['grade' => 8], 1);
        $total(['term' => 'ck1'], 1);
        $total(['skill' => 'Ngữ âm'], 1);
        $total(['status' => 'hidden'], 1);
        $total(['type' => 'Phát âm'], 1);
        $total(['q' => 'lives'], 1);
        $total(['q' => 'My village'], 1); // tìm cả trong ngữ liệu
        $total(['grade' => 7, 'status' => 'draft'], 2);

        $this->actingAs($this->lead)->get(route('question-bank.index', ['q' => 'lives']))->assertOk()
            ->assertSee('He <u class="decoration-2 underline-offset-2">lives</u> near here.', false) // [x] → gạch chân, không v-html
            ->assertSee('My village is (1)')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canManage', true)
                ->where('questions.data.0.stem', 'He [lives] near here.')
                ->where('questions.data.0.options.A', 'go')
                ->where('questions.data.0.answer', 'C')
                ->where('questions.data.0.status', 'approved')
                ->where('questions.data.0.passage.id', $passage->id)
                ->where('questions.data.0.passage.content', 'My village is (1) _____ and (2) _____.')
                ->where('statusCounts.approved', 1)
                ->has('filterOptions.grades', 4)
                ->has('filterOptions.terms', 4));
    }

    public function test_bulk_approve_all_matching_filter(): void
    {
        $grade7 = collect(range(1, 3))->map(fn () => $this->question(['grade' => 7]));
        $grade8 = collect(range(1, 2))->map(fn () => $this->question(['grade' => 8]));

        $this->actingAs($this->lead)->from(route('question-bank.index'))
            ->post(route('question-bank.bulk'), ['action' => 'approve', 'all_matching' => true, 'grade' => 7])
            ->assertRedirect(route('question-bank.index'))
            ->assertSessionHas('status', 'Đã duyệt 3 câu hỏi.');

        foreach ($grade7 as $question) {
            $question->refresh();
            $this->assertSame('approved', $question->status);
            $this->assertSame($this->lead->id, $question->approved_by);
            $this->assertNotNull($question->approved_at);
            $this->assertNull($question->edited_at, 'Duyệt không phải sửa nội dung: không gắn "Đã sửa".');
        }
        foreach ($grade8 as $question) {
            $question->refresh();
            $this->assertSame('draft', $question->status);
            $this->assertNull($question->approved_by);
            $this->assertNull($question->edited_at);
        }

        $this->assertDatabaseHas('activity_log', ['log_name' => 'Ngân hàng câu hỏi', 'description' => 'Duyệt 3 câu hỏi']);
    }

    public function test_bulk_with_ids_hides_selected(): void
    {
        $a = $this->question(['status' => 'approved', 'approved_by' => $this->lead->id, 'approved_at' => now()]);
        $b = $this->question();
        $c = $this->question();

        $this->actingAs($this->lead)->from(route('question-bank.index'))
            ->post(route('question-bank.bulk'), ['action' => 'hide', 'ids' => [$a->id, $b->id]])
            ->assertRedirect(route('question-bank.index'))
            ->assertSessionHas('status', 'Đã ẩn 2 câu hỏi.');

        $this->assertSame('hidden', $a->fresh()->status);
        $this->assertSame('hidden', $b->fresh()->status);
        $this->assertNull($b->fresh()->edited_at, 'Ẩn không phải sửa nội dung: không gắn "Đã sửa".');
        $this->assertSame('draft', $c->fresh()->status);
        $this->assertNull($c->fresh()->edited_at);
    }

    public function test_bulk_draft_clears_approval_and_requires_a_target(): void
    {
        $a = $this->question(['status' => 'approved', 'approved_by' => $this->lead->id, 'approved_at' => now()]);

        $this->actingAs($this->lead)->post(route('question-bank.bulk'), ['action' => 'draft', 'ids' => [$a->id]])->assertRedirect();
        $a->refresh();
        $this->assertSame('draft', $a->status);
        $this->assertNull($a->approved_by);
        $this->assertNull($a->approved_at);
        $this->assertNotNull($a->edited_at);

        $this->actingAs($this->lead)->post(route('question-bank.bulk'), ['action' => 'approve'])->assertSessionHasErrors('ids');
        $this->actingAs($this->lead)->post(route('question-bank.bulk'), ['action' => 'delete', 'ids' => [$a->id]])->assertSessionHasErrors('action');
    }

    public function test_bulk_and_edit_require_manage_permission(): void
    {
        $question = $this->question();
        $teacher = $this->userWithRole(Roles::TEACHER_FULLTIME);

        $this->actingAs($teacher)->post(route('question-bank.bulk'), ['action' => 'approve', 'ids' => [$question->id]])->assertForbidden();
        $this->actingAs($teacher)->get(route('question-bank.questions.edit', $question))->assertForbidden();
        $this->assertSame('draft', $question->fresh()->status);
    }

    public function test_edit_question_renders_as_modal_and_full_page(): void
    {
        $question = $this->question(['stem' => 'She [often] goes swimming.']);

        $this->actingAs($this->lead)->get(route('question-bank.questions.edit', $question), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('QuestionBank/QuestionForm')
                ->where('asModal', true)
                ->where('question.stem', 'She [often] goes swimming.')
                ->where('question.answer', 'B'));

        $this->actingAs($this->lead)->get(route('question-bank.questions.edit', $question))->assertOk()
            ->assertSee('Sửa câu hỏi')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('QuestionBank/QuestionForm')->where('asModal', false));
    }

    public function test_update_question_sets_edited_at_and_validates_answer(): void
    {
        $question = $this->question();
        $payload = [
            'instruction' => 'Choose the best answer.',
            'stem' => 'She [often] goes swimming.',
            'options' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'],
            'answer' => 'E',
            'knowledge' => 'Present simple',
            'status' => 'draft',
        ];

        $this->actingAs($this->lead)->from(route('question-bank.questions.edit', $question))
            ->put(route('question-bank.questions.update', $question), $payload)
            ->assertSessionHasErrors('answer');
        $this->assertNull($question->fresh()->edited_at);

        $this->actingAs($this->lead)->from(route('question-bank.index'))
            ->put(route('question-bank.questions.update', $question), [...$payload, 'answer' => 'D', 'status' => 'approved'], self::MODAL)
            ->assertRedirect(route('question-bank.index'))
            ->assertSessionHas('status', 'Đã lưu câu hỏi.');

        $question->refresh();
        $this->assertNotNull($question->edited_at);
        $this->assertSame('D', $question->answer);
        $this->assertSame('She [often] goes swimming.', $question->stem);
        $this->assertSame(['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'], $question->options);
        $this->assertSame('Present simple', $question->knowledge);
        $this->assertSame('approved', $question->status);
        $this->assertSame($this->lead->id, $question->approved_by);
        $this->assertNotNull($question->approved_at);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'Ngân hàng câu hỏi', 'subject_id' => $question->id, 'event' => 'updated']);

        // Về lại "Chưa duyệt" → bỏ người / lúc duyệt.
        $this->actingAs($this->lead)->put(route('question-bank.questions.update', $question), [...$payload, 'answer' => 'D', 'status' => 'draft'])
            ->assertRedirect(route('question-bank.index'));
        $this->assertNull($question->fresh()->approved_by);
        $this->assertNull($question->fresh()->approved_at);
    }

    public function test_update_passage_marks_its_questions_edited(): void
    {
        $passage = $this->passage();
        $inside = $this->question(['passage_id' => $passage->id, 'position' => 1]);
        $inside2 = $this->question(['passage_id' => $passage->id, 'position' => 2]);
        $outside = $this->question();

        $this->actingAs($this->lead)->get(route('question-bank.passages.edit', $passage), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('QuestionBank/PassageForm')
                ->where('passage.content', 'My village is (1) _____ and (2) _____.')
                ->has('passage.questions', 2));

        $this->actingAs($this->lead)->put(route('question-bank.passages.update', $passage), ['instruction' => 'x', 'content' => ''])
            ->assertSessionHasErrors('content');

        $this->actingAs($this->lead)->from(route('question-bank.index'))
            ->put(route('question-bank.passages.update', $passage), ['instruction' => 'Read carefully.', 'content' => 'My town is [quiet].'], self::MODAL)
            ->assertRedirect(route('question-bank.index'))
            ->assertSessionHas('status', 'Đã lưu ngữ liệu.');

        $this->assertSame('My town is [quiet].', $passage->fresh()->content);
        $this->assertSame('Read carefully.', $passage->fresh()->instruction);
        $this->assertNotNull($inside->fresh()->edited_at);
        $this->assertNotNull($inside2->fresh()->edited_at);
        $this->assertNull($outside->fresh()->edited_at);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'Ngân hàng câu hỏi', 'subject_id' => $passage->id, 'description' => 'Sửa ngữ liệu #'.$passage->id]);
    }

    public function test_import_endpoint_shows_summary(): void
    {
        $zip = Zip::write([
            'data/070.json' => Zip::dataFile(70, [
                Zip::group('Từ vựng & Ngữ pháp', 'Chọn đáp án đúng', [
                    Zip::question('B3.1'),
                    Zip::question('B3.2', ['anh' => 'F070_missing.png']),
                ]),
            ]),
        ]);

        $this->actingAs($this->lead)->from(route('question-bank.index'))
            ->post(route('question-bank.import'), ['file' => new UploadedFile($zip, 'ngan-hang.zip', 'application/zip', null, true)])
            ->assertRedirect(route('question-bank.index'))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Thêm 2'))
            ->assertSessionHas('import_errors', fn (array $errors) => count($errors) === 1 && str_contains($errors[0], 'F070_missing.png'));

        $this->assertSame(2, QuestionBankQuestion::query()->count());
        // Một dòng nhật ký tổng cho cả lần import (không ghi từng câu).
        $this->assertSame(1, Activity::query()->where('log_name', 'Ngân hàng câu hỏi')->count());

        $this->followingRedirects()->actingAs($this->lead)->from(route('question-bank.index'))
            ->post(route('question-bank.import'), ['file' => new UploadedFile($zip, 'ngan-hang.zip', 'application/zip', null, true)])
            ->assertOk()
            ->assertSee('F070_missing.png')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('importErrors', 1));
    }

    public function test_import_rejects_unsafe_or_missing_zip(): void
    {
        $zip = Zip::write(['../evil.json' => '{}']);

        $this->actingAs($this->lead)->from(route('question-bank.index'))
            ->post(route('question-bank.import'), ['file' => new UploadedFile($zip, 'evil.zip', 'application/zip', null, true)])
            ->assertRedirect(route('question-bank.index'))
            ->assertSessionHasErrors('file');

        $this->actingAs($this->lead)->post(route('question-bank.import'), [])->assertSessionHasErrors('file');
        $this->assertSame(0, QuestionBankQuestion::query()->count());
    }
}
