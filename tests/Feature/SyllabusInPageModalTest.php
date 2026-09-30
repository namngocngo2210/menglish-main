<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * Màn Giao chặng / Tài liệu giáo trình (Vue): form tạo nằm trong hộp thoại đóng sẵn trên trang, mở bằng nút trên đầu trang;
 * lỗi validate → Inertia giữ nguyên trang (hộp thoại vẫn mở, dữ liệu đã nhập còn nguyên) kèm lỗi.
 */
class SyllabusInPageModalTest extends TestCase
{
    use InteractsWithInertia;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-SM', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->admin->assignRole('admin');
        SyllabusCurriculum::create(['code' => 'CUR-SM', 'version' => 'v1.0', 'title' => 'Giáo trình thử']);
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function pages(): array
    {
        return [
            'giao chặng' => ['syllabus.assignments', 'Syllabus/Assignments', 'canManage', 'Thiết lập chặng mới', 'new-assignment-form'],
            'tài liệu giáo trình' => ['syllabus.documents', 'Syllabus/Documents', 'canUpload', 'Tải lên tài liệu', 'upload-document-form'],
        ];
    }

    #[DataProvider('pages')]
    public function test_create_form_lives_in_a_closed_modal_opened_by_a_button(string $route, string $component, string $flag, string $button, string $form): void
    {
        $html = $this->actingAs($this->admin)->get(route($route))->assertOk()
            ->assertSee($button)
            ->assertInertia(fn (AssertableInertia $page) => $page->component($component)->where($flag, true))
            ->getContent();

        // Hộp thoại đóng sẵn (v-show → display:none) và chứa form tạo — không có form tạo nằm cạnh danh sách.
        $formAt = strpos($html, 'id="'.$form.'"');
        $this->assertNotFalse($formAt);
        // Khung hộp thoại gần nhất bao quanh form (thuộc tính data-modal, không phải nút data-modal-close).
        preg_match_all('/data-modal(?=[\s>])[^>]*>/', substr($html, 0, $formAt), $frames);
        $this->assertNotEmpty($frames[0]);
        $this->assertStringContainsString('display:none', end($frames[0]));
    }

    public function test_validation_error_returns_to_the_page_with_errors(): void
    {
        $this->actingAs($this->admin)->from(route('syllabus.assignments'))
            ->post(route('syllabus.assignments.store'), ['class_id' => ''])
            ->assertRedirect(route('syllabus.assignments'))
            ->assertSessionHasErrors('class_id');
    }
}
