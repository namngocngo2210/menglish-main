<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PracticeBlueprint;
use App\Models\QuestionBankQuestion;
use App\Models\User;
use App\Services\Practice\QuestionAvailability;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/** Màn Khung đề luyện tập: lưới khối × kỳ kèm số câu đã duyệt, sửa khung. */
class PracticeBlueprintControllerTest extends TestCase
{
    use InteractsWithInertia;
    use RefreshDatabase;

    private User $lead;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lead = $this->userWithRole(Roles::ACADEMIC_LEAD);
    }

    private function userWithRole(string $role): User
    {
        $branch = Branch::query()->firstOrCreate(['code' => 'BP-CG'], ['name' => 'Cầu Giấy', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
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
            'answer' => 'A', 'status' => 'approved', 'source_key' => "BP-{$this->seq}",
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function cell(AssertableInertia $page, int $grade, string $term): array
    {
        return collect($page->toArray()['props']['cells'])->first(fn ($c) => $c['grade'] === $grade && $c['term'] === $term);
    }

    private function row(array $cell, string $skill, string $type): array
    {
        return collect($cell['items'])->first(fn ($i) => $i['skill'] === $skill && $i['type'] === $type);
    }

    public function test_index_lists_16_cells_with_default_totals_and_available_counts(): void
    {
        $this->question();
        $this->question();
        $this->question(['status' => 'draft']);
        $this->question(['status' => 'hidden']);
        $this->question(['grade' => 8]);

        $this->actingAs($this->lead)->get(route('question-bank.blueprints.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $page->component('QuestionBank/Blueprints')->has('cells', 16)->where('canManage', true);
                $cell = $this->cell($page, 7, 'ck1');
                $this->assertSame(40, $cell['total']);
                $this->assertSame(45, $cell['duration_minutes']);
                $row = $this->row($cell, 'Ngữ âm', 'Phát âm');
                $this->assertSame(2, $row['available']);
                $this->assertFalse($row['short']);
                $this->assertTrue($this->row($cell, 'Ngữ âm', 'Trọng âm')['short']);
                $this->assertSame(1, $this->row($this->cell($page, 8, 'ck1'), 'Ngữ âm', 'Phát âm')['available']);
            });
    }

    public function test_row_is_short_when_fewer_approved_than_needed(): void
    {
        $this->question();

        $this->actingAs($this->lead)->get(route('question-bank.blueprints.index'))
            ->assertInertia(function (AssertableInertia $page) {
                $row = $this->row($this->cell($page, 7, 'ck1'), 'Ngữ âm', 'Phát âm');
                $this->assertSame(1, $row['available']);
                $this->assertTrue($row['short']);
            });
    }

    public function test_availability_counts_use_one_grouped_query(): void
    {
        $this->question();
        $this->question(['skill' => 'Viết', 'type' => 'Câu đồng nghĩa']);
        $this->question(['term' => 'gk1']);

        DB::enableQueryLog();
        $counts = app(QuestionAvailability::class)->counts(7, 'ck1');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertSame(['Ngữ âm|Phát âm' => 1, 'Viết|Câu đồng nghĩa' => 1], $counts);
    }

    public function test_index_runs_a_constant_number_of_queries(): void
    {
        $this->question();
        $this->actingAs($this->lead);
        DB::enableQueryLog();
        $this->get(route('question-bank.blueprints.index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $count);
    }

    public function test_update_persists_items_and_duration_and_creates_missing_row(): void
    {
        PracticeBlueprint::query()->where('grade', 7)->where('term', 'ck1')->delete();
        $items = [
            ['skill' => 'Ngữ âm', 'type' => 'Phát âm', 'count' => 3],
            ['skill' => 'Viết', 'type' => ' Câu đồng nghĩa ', 'count' => 0],
        ];

        $this->actingAs($this->lead)
            ->put(route('question-bank.blueprints.update', [7, 'ck1']), ['duration_minutes' => 30, 'items' => $items])
            ->assertRedirect(route('question-bank.blueprints.index'));

        $blueprint = PracticeBlueprint::forGradeTerm(7, 'ck1');
        $this->assertSame(30, $blueprint->duration_minutes);
        $this->assertSame([
            ['skill' => 'Ngữ âm', 'type' => 'Phát âm', 'count' => 3],
            ['skill' => 'Viết', 'type' => 'Câu đồng nghĩa', 'count' => 0],
        ], $blueprint->items);
        $this->assertSame(1, PracticeBlueprint::query()->where('grade', 7)->where('term', 'ck1')->count());

        $this->actingAs($this->lead)
            ->put(route('question-bank.blueprints.update', [7, 'ck1']), ['duration_minutes' => 50, 'items' => $items])
            ->assertRedirect();
        $this->assertSame(1, PracticeBlueprint::query()->where('grade', 7)->where('term', 'ck1')->count());
        $this->assertSame(50, PracticeBlueprint::forGradeTerm(7, 'ck1')->duration_minutes);
    }

    public function test_update_rejects_invalid_input(): void
    {
        $url = route('question-bank.blueprints.update', [7, 'ck1']);
        $ok = [['skill' => 'Ngữ âm', 'type' => 'Phát âm', 'count' => 2]];
        $this->actingAs($this->lead);

        $this->putJson($url, ['duration_minutes' => 45, 'items' => [['skill' => 'Toán', 'type' => 'x', 'count' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.skill');
        $this->putJson($url, ['duration_minutes' => 4, 'items' => $ok])->assertStatus(422)->assertJsonValidationErrors('duration_minutes');
        $this->putJson($url, ['duration_minutes' => 181, 'items' => $ok])->assertStatus(422)->assertJsonValidationErrors('duration_minutes');
        $this->putJson($url, ['duration_minutes' => 45, 'items' => []])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->putJson($url, ['duration_minutes' => 45, 'items' => array_fill(0, 31, $ok[0])])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->putJson($url, ['duration_minutes' => 45, 'items' => [['skill' => 'Ngữ âm', 'type' => 'x', 'count' => 101]]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.count');
        $this->putJson($url, ['duration_minutes' => 45, 'items' => [['skill' => 'Ngữ âm', 'type' => str_repeat('a', 101), 'count' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.type');
    }

    public function test_unknown_grade_or_term_is_404(): void
    {
        $this->actingAs($this->lead);
        $this->get(route('question-bank.blueprints.edit', [5, 'ck1']))->assertNotFound();
        $this->get(route('question-bank.blueprints.edit', [7, 'xx']))->assertNotFound();
        $this->put(route('question-bank.blueprints.update', [10, 'ck1']), [])->assertNotFound();
    }

    public function test_edit_form_carries_blueprint_and_type_suggestions(): void
    {
        $this->question(['type' => 'Phát âm']);

        $this->actingAs($this->lead)->get(route('question-bank.blueprints.edit', [7, 'ck1']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('QuestionBank/BlueprintForm')
                ->where('blueprint.grade', 7)
                ->where('blueprint.term_label', 'Cuối kì 1')
                ->has('blueprint.items', 11)
                ->where('typeSuggestions.Ngữ âm', ['Phát âm']));
    }

    public function test_academic_staff_is_forbidden_and_viewer_without_manage_cannot_edit(): void
    {
        $staff = $this->userWithRole(Roles::ACADEMIC_STAFF);
        $this->actingAs($staff)->get(route('question-bank.blueprints.index'))->assertForbidden();
        $this->actingAs($staff)->put(route('question-bank.blueprints.update', [7, 'ck1']), [])->assertForbidden();

        $viewer = $this->userWithRole(Roles::TEACHER_FULLTIME);
        $viewer->givePermissionTo('question_bank.view');
        $this->actingAs($viewer)->get(route('question-bank.blueprints.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));
        $this->actingAs($viewer)->get(route('question-bank.blueprints.edit', [7, 'ck1']))->assertForbidden();
        $this->actingAs($viewer)->put(route('question-bank.blueprints.update', [7, 'ck1']), [])->assertForbidden();
    }
}
