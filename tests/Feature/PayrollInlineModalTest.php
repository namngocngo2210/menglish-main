<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Màn Lương / KPI (Vue): form tạo không nằm cạnh danh sách mà ở hộp thoại đóng sẵn, mở bằng nút trên đầu trang
 * (tương đương Inertia của InlineFormModalTest). Lỗi validate: Inertia giữ nguyên trang nên hộp thoại vẫn mở kèm dữ liệu đã nhập.
 */
class PayrollInlineModalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-PM', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->admin->assignRole('admin');
    }

    /** @return array<string, array{string, string, string}> */
    public static function pages(): array
    {
        return [
            'mốc hoa hồng' => ['payroll.config.commission-tiers', 'new-tier', 'Thêm mốc mới'],
            'đơn giá GV' => ['payroll.config.teacher-rates', 'new-rate', 'Cập nhật đơn giá'],
            'KPI' => ['kpi.criteria', 'new-kpi', 'Thêm mục mới'],
        ];
    }

    #[DataProvider('pages')]
    public function test_create_form_lives_in_a_closed_modal_opened_by_a_button(string $route, string $modal, string $button): void
    {
        $html = $this->actingAs($this->admin)->get(route($route))->assertOk()->assertSee($button)->getContent();

        // Hộp thoại có sẵn trong trang (render phía server) nhưng đang đóng.
        $this->assertMatchesRegularExpression('/<div(?=[^>]*data-modal="'.preg_quote($modal, '/').'")(?=[^>]*style="display:\s*none;?")[^>]*>/', $html);
    }
}
