<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** TK-2026-0044: lộ trình cổng HS = đúng 1 ảnh của giáo trình lớp, không còn nội dung cứng. */
class StudentPortalRoadmapImageTest extends TestCase
{
    use RefreshDatabase;

    private User $parent;

    private Student $student;

    private ClassModel $class;

    private SyllabusCurriculum $curriculum;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $branch = Branch::create(['name' => 'Cơ sở CG', 'code' => 'CG', 'is_active' => true]);
        $this->parent = User::factory()->create();
        $this->class = ClassModel::create(['name' => 'Starters FAM 1', 'code' => 'ST-1', 'max_capacity' => 16, 'status' => 'active', 'branch_id' => $branch->id]);
        $this->student = Student::create(['user_id' => $this->parent->id, 'name' => 'Học sinh A', 'code' => 'HV-1', 'phone' => '0987654321',
            'status' => 'active', 'current_class_id' => $this->class->id, 'branch_id' => $branch->id]);
        $this->curriculum = SyllabusCurriculum::create(['code' => 'CUR-ST', 'title' => 'Starters', 'version' => 'v1']);
        SyllabusAssignment::create(['class_id' => $this->class->id, 'curriculum_id' => $this->curriculum->id,
            'stage_id' => $this->curriculum->stages()->firstOrFail()->id, 'stage_name' => 'Chặng 1', 'assigned_chapters' => 'Chặng 1',
            'user_id' => $this->parent->id, 'status' => SyllabusAssignment::STATUS_OPEN, 'opened_at' => now()]);
    }

    private function homework()
    {
        return $this->actingAs($this->parent)->get(route('portal.student.homework', ['studentId' => $this->student->id]));
    }

    public function test_without_image_shows_placeholder_and_no_hardcoded_text(): void
    {
        $this->homework()->assertOk()
            ->assertSee('Chưa cập nhật lộ trình')
            ->assertDontSee('IELTS 6.5')
            ->assertDontSee('Đạt 65% thời lượng');
        $this->actingAs($this->parent)->get(route('portal.student.roadmap-image', $this->student->id))->assertNotFound();
    }

    public function test_with_image_student_sees_it_and_can_fetch_it(): void
    {
        $this->curriculum->update(['roadmap_image_path' => UploadedFile::fake()->image('r.png')->storeAs('syllabus_roadmaps', 'r.png', 'local')]);

        $this->homework()->assertOk()->assertDontSee('Chưa cập nhật lộ trình')
            ->assertSee(route('portal.student.roadmap-image', $this->student->id), false);
        $this->actingAs($this->parent)->get(route('portal.student.roadmap-image', $this->student->id))->assertOk();
    }

    public function test_other_users_cannot_fetch_the_image(): void
    {
        $this->curriculum->update(['roadmap_image_path' => UploadedFile::fake()->image('r.png')->storeAs('syllabus_roadmaps', 'r.png', 'local')]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('portal.student.roadmap-image', $this->student->id))->assertForbidden();
    }

    public function test_academic_lead_uploads_and_removes_roadmap_image(): void
    {
        $lead = User::factory()->create(['is_active' => true]);
        $lead->assignRole(Roles::ACADEMIC_LEAD);

        $this->actingAs($lead)->post(route('syllabus.curriculums.roadmap-image.store', $this->curriculum->id), [
            'roadmap_image' => UploadedFile::fake()->image('road.png'),
        ])->assertSessionHasNoErrors();
        $path = $this->curriculum->fresh()->roadmap_image_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);

        $this->actingAs($lead)->delete(route('syllabus.curriculums.roadmap-image.destroy', $this->curriculum->id))->assertRedirect();
        $this->assertNull($this->curriculum->fresh()->roadmap_image_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_upload_rejects_non_image_and_unauthorised_users(): void
    {
        $lead = User::factory()->create(['is_active' => true]);
        $lead->assignRole(Roles::ACADEMIC_LEAD);

        $this->actingAs($lead)->post(route('syllabus.curriculums.roadmap-image.store', $this->curriculum->id), [
            'roadmap_image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('roadmap_image');

        $this->actingAs($this->parent)->post(route('syllabus.curriculums.roadmap-image.store', $this->curriculum->id), [
            'roadmap_image' => UploadedFile::fake()->image('road.png'),
        ])->assertForbidden();
        $this->assertNull($this->curriculum->fresh()->roadmap_image_path);
    }
}
