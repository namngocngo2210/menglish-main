<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dọn giao diện mục Giáo trình (docs/giao-trinh-don-giao-dien.md): /syllabus vào màn theo quyền,
 * đầu trang chỉ có thao tác của trang, nút quay lại chỉ ở trang con, ô soạn Builder mở trong modal.
 */
class SyllabusNavigationCleanupTest extends TestCase
{
    use RefreshDatabase;

    private User $academic;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::create(['name' => 'Cơ sở Dọn GT', 'code' => 'DG', 'is_active' => true]);
        $this->academic = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->academic->assignRole(Roles::ACADEMIC_LEAD);
        $this->teacher = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->teacher->assignRole(Roles::TEACHER_FULLTIME);
    }

    public function test_syllabus_index_sends_managers_to_documents(): void
    {
        $this->actingAs($this->academic)->get('/syllabus')->assertRedirect(route('syllabus.documents'));
    }

    public function test_syllabus_index_sends_teachers_to_teaching_stages(): void
    {
        $this->actingAs($this->teacher)->get('/syllabus')->assertRedirect(route('syllabus.teaching-stages'));
    }
}
