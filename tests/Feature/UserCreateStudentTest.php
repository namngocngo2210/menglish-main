<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * TK-2026-0004: modal "Thêm người dùng mới" với vai trò chính Học viên. Cơ sở chính bắt buộc; trước đây ô này để trống thì
 * bong bóng "required" của trình duyệt chặn gửi (không có thông báo rõ). Nay form gửi lên server (novalidate) và nhận
 * lỗi tiếng Việt cho từng ô; người chỉ có 1 cơ sở được chọn sẵn cơ sở đó; trường chỉ dành cho nhân sự không chặn học viên.
 */
class UserCreateStudentTest extends TestCase
{
    use InteractsWithInertia;
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG-HV', 'is_active' => true]);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** Đúng các ô bắt buộc trên tab Tài khoản + cờ ẩn form luôn gửi; không có trường hồ sơ / hợp đồng / lương. */
    private function studentPayload(array $overrides = []): array
    {
        return [
            'name' => 'Nguyễn Học Viên',
            'email' => 'hocvien.moi@menglish.edu.vn',
            'phone' => '',
            'branch_id' => $this->branch->id,
            'role' => Roles::STUDENT,
            'password' => 'Password123!',
            'concurrent_roles_present' => '1',
            'weekly_days_off_present' => '1',
            'base_salary' => '',
            'hourly_rate' => '',
            'contract_type' => '',
            ...$overrides,
        ];
    }

    public static function creators(): array
    {
        return ['admin' => [Roles::ADMIN], 'học vụ' => [Roles::ACADEMIC_STAFF]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('creators')]
    public function test_creates_student_from_modal_without_staff_only_fields(string $creatorRole): void
    {
        $this->actingAs($this->actor($creatorRole))
            ->from(route('users.index'))
            ->withHeaders(self::MODAL)
            ->post(route('users.store'), $this->studentPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('users.index'));

        $student = User::where('email', 'hocvien.moi@menglish.edu.vn')->firstOrFail();
        $this->assertSame([Roles::STUDENT], $student->getRoleNames()->all());
        $this->assertSame($this->branch->id, (int) $student->branch_id);
        $this->assertTrue((bool) $student->must_change_password);
        $this->assertNull($student->contract_type);
        $this->assertEquals(0, $student->base_salary);
        $this->assertMatchesRegularExpression('/^MES\d+$/', $student->employee_code);
    }

    public function test_missing_fields_get_vietnamese_message_per_field(): void
    {
        $this->actingAs($this->actor(Roles::ADMIN))
            ->from(route('users.index'))
            ->withHeaders(self::MODAL)
            ->post(route('users.store'), $this->studentPayload(['name' => '', 'email' => '', 'branch_id' => '', 'password' => '']))
            ->assertSessionHasErrors([
                'name' => 'Vui lòng nhập họ và tên.',
                'email' => 'Vui lòng nhập email công việc.',
                'branch_id' => 'Vui lòng chọn cơ sở chính.',
                'password' => 'Vui lòng nhập mật khẩu khởi tạo.',
            ]);

        $this->actingAs($this->actor(Roles::ADMIN))
            ->from(route('users.index'))
            ->withHeaders(self::MODAL)
            ->post(route('users.store'), $this->studentPayload(['role' => '', 'password' => 'short']))
            ->assertSessionHasErrors(['role' => 'Vui lòng chọn vai trò.', 'password' => 'Mật khẩu tối thiểu 8 ký tự.']);

        $this->assertDatabaseMissing('users', ['email' => 'hocvien.moi@menglish.edu.vn']);
    }

    public function test_form_skips_browser_validation_and_preselects_the_only_branch(): void
    {
        $html = $this->actingAs($this->actor(Roles::ACADEMIC_STAFF))
            ->withHeaders(self::MODAL)
            ->get(route('users.create'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<form[^>]*id="modal-user-form"[^>]*novalidate|<form[^>]*novalidate[^>]*id="modal-user-form"/', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$this->branch->id.'" selected[^>]*>Chi nhánh Cầu Giấy<\/option>/', $html);
    }
}
