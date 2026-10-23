<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Import 1 chặng từ file Sheet 1: tải lên → xem trước → xác nhận (docs/kho-syllabus.md §3.3). */
class SyllabusStageImportPageTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $lead;

    private SyllabusCurriculum $curriculum;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Cơ sở Import', 'code' => 'IM', 'is_active' => true]);
        $this->lead = $this->userWithRole(Roles::ACADEMIC_LEAD);
        $this->curriculum = SyllabusCurriculum::create(['code' => 'GS6', 'title' => 'Global Success 6', 'version' => 'v1']);
    }

    public function test_full_flow_new_stage(): void
    {
        $token = $this->preview([
            ['B' => 1, 'C' => 'Học', 'D' => 'Unit 1: My New School', 'F' => 'Getting started'],
            ['B' => 2, 'C' => 'Mini test', 'F' => 'Mini test Unit 1'],
        ]);

        $this->actingAs($this->lead)->get(route('syllabus.stage-import.show', ['curriculum' => $this->curriculum->id, 'token' => $token]))
            ->assertOk()
            ->assertSee('Import chặng từ file')
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Syllabus/StageImport')
                ->where('plan.summary.create', 2)
                ->where('stage', null));

        $this->actingAs($this->lead)->post(route('syllabus.stage-import.confirm', $this->curriculum->id), ['token' => $token])
            ->assertRedirect(route('syllabus.curriculums.show', ['id' => $this->curriculum->id, 'tab' => 'stages']));

        $this->assertSame(2, $this->curriculum->stages()->count());
        $this->assertSame(2, SyllabusLesson::where('curriculum_id', $this->curriculum->id)->count());
    }

    public function test_update_existing_stage(): void
    {
        $stage = $this->curriculum->stages()->firstOrFail();
        $token = $this->preview([['B' => 1, 'D' => 'Unit 1: A', 'F' => 'Bài A']], $stage->id);

        $this->actingAs($this->lead)->post(route('syllabus.stage-import.confirm', $this->curriculum->id), ['token' => $token])
            ->assertRedirect();

        $this->assertSame(1, $this->curriculum->stages()->count());
        $this->assertSame('Bài A', SyllabusLesson::where('curriculum_id', $this->curriculum->id)->value('title'));
    }

    public function test_errors_block_confirm(): void
    {
        $token = $this->preview([['B' => 1, 'D' => 'Bài 1 không có số unit', 'F' => 'x']]);

        $this->actingAs($this->lead)->get(route('syllabus.stage-import.show', ['curriculum' => $this->curriculum->id, 'token' => $token]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('plan.errors', 1));
        $this->actingAs($this->lead)->post(route('syllabus.stage-import.confirm', $this->curriculum->id), ['token' => $token])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, SyllabusLesson::count());
    }

    public function test_academic_staff_forbidden(): void
    {
        $staff = $this->userWithRole(Roles::ACADEMIC_STAFF);

        $this->actingAs($staff)->get(route('syllabus.stage-import.template'))->assertForbidden();
        $this->actingAs($staff)->post(route('syllabus.stage-import.preview', $this->curriculum->id), ['file' => $this->upload([])])->assertForbidden();
    }

    public function test_template_downloads(): void
    {
        $this->actingAs($this->lead)->get(route('syllabus.stage-import.template'))
            ->assertOk()
            ->assertDownload('mau-syllabus-chang.xlsx');
    }

    public function test_stage_of_other_curriculum_rejected(): void
    {
        $other = SyllabusCurriculum::create(['code' => 'KHAC', 'title' => 'Khác', 'version' => 'v1']);

        $this->actingAs($this->lead)->post(route('syllabus.stage-import.preview', $this->curriculum->id), [
            'file' => $this->upload([['B' => 1, 'D' => 'Unit 1: A', 'F' => 'x']]), 'stage_id' => $other->stages()->firstOrFail()->id,
        ])->assertSessionHasErrors('stage_id');
    }

    public function test_detail_page_has_import_buttons_and_template_link(): void
    {
        $this->actingAs($this->lead)->get(route('syllabus.curriculums.show', $this->curriculum->id))
            ->assertOk()
            ->assertSee('Thêm chặng từ file')
            ->assertSee('href="'.route('syllabus.stage-import.template', [], false).'"', false);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function preview(array $rows, ?int $stageId = null): string
    {
        $response = $this->actingAs($this->lead)->post(route('syllabus.stage-import.preview', $this->curriculum->id), array_filter([
            'file' => $this->upload($rows), 'stage_id' => $stageId,
        ]))->assertSessionHasNoErrors();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['token'];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function upload(array $rows): UploadedFile
    {
        return new UploadedFile(SyllabusStageImportReaderTest::filledTemplate($rows, 'Global Success 6', 'Chặng mới'), 'chang.xlsx', null, null, true);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }
}
