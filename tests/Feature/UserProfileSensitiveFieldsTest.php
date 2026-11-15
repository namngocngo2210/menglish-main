<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * TK-2026-0122 (EP5-NS-04): hồ sơ nhân sự (CCCD, liên lạc khẩn cấp, quê quán, nơi ở, TK ngân hàng) hiện/sửa theo quyền
 * nhân sự user.view / user.update; chỉ lương cơ bản, phụ cấp, thù lao giờ dành riêng cho Admin.
 */
class UserProfileSensitiveFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $staff;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-PII', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Đội Cấn', 'code' => 'DC-PII', 'is_active' => true]);
        $this->staff = $this->person(Roles::ACADEMIC_STAFF, $this->branch);
        $this->staff->givePermissionTo(['user.view', 'user.update']);
        $this->teacher = $this->person(Roles::TEACHER_FULLTIME, $this->branch, [
            'id_card_number' => '001201004567', 'id_card_issued_on' => '2020-05-01', 'emergency_contact' => 'Mẹ - 0900111222',
            'hometown' => 'Nam Định', 'current_address' => 'Cầu Giấy, Hà Nội', 'bank_account' => '9704-VCB-123456',
            'base_salary' => 17777000, 'hourly_rate' => 333000, 'monthly_allowance' => 555000,
        ]);
    }

    private function person(string $role, Branch $branch, array $attrs = []): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true] + $attrs);
        $user->assignRole($role);

        return $user;
    }

    private function updatePayload(array $extra = []): array
    {
        return [
            'name' => $this->teacher->name, 'email' => $this->teacher->email, 'branch_id' => $this->branch->id,
            'role' => Roles::TEACHER_FULLTIME,
        ] + $extra;
    }

    public function test_user_view_sees_profile_fields_but_not_salary_in_show_props(): void
    {
        $this->actingAs($this->staff)->get(route('users.show', $this->teacher))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canViewSensitive', false)
                ->where('user.base_salary', null)
                ->where('user.hourly_rate', null)
                ->where('user.profile.4.1', '001201004567')
                ->where('user.profile.5.1', '01/05/2020')
                ->where('user.profile.6.1', 'Mẹ - 0900111222')
                ->where('user.profile.7.1', 'Nam Định')
                ->where('user.profile.8.1', 'Cầu Giấy, Hà Nội')
                ->where('user.profile.14.1', '9704-VCB-123456'));
    }

    public function test_show_payload_does_not_leak_salary_numbers(): void
    {
        $html = $this->actingAs($this->staff)->get(route('users.show', $this->teacher))->assertOk()->getContent();
        foreach (['17777000', '333000', '555000'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_index_drawer_payload_has_profile_fields_without_salary(): void
    {
        $page = $this->actingAs($this->staff)->get(route('users.index', ['per_page' => 100]))->assertOk()->viewData('page');
        $row = collect($page['props']['users']['data'])->firstWhere('id', $this->teacher->id);
        $this->assertNotNull($row, 'teacher must be listed');
        $profile = $row['profile'] ?? $row;
        $this->assertSame('001201004567', $profile['id_card_number']);
        $this->assertSame('9704-VCB-123456', $profile['bank_account']);
        $this->assertSame('Nam Định', $profile['hometown']);
        $this->assertArrayNotHasKey('base_salary', $profile);
        $this->assertArrayNotHasKey('hourly_rate', $profile);
        $this->assertArrayNotHasKey('monthly_allowance', $profile);
    }

    public function test_without_user_view_profile_fields_are_hidden(): void
    {
        $outsider = $this->person(Roles::ASSISTANT, $this->branch);
        $this->assertFalse($outsider->can('user.view'));
        $this->actingAs($outsider)->get(route('users.show', $this->teacher))->assertForbidden();
        $this->assertFalse(\App\Http\Controllers\UserController::canViewProfile($outsider));
        $payload = \App\Http\Controllers\UserController::profilePayload($this->teacher, $outsider);
        $this->assertArrayNotHasKey('id_card_number', $payload);
        $this->assertArrayNotHasKey('bank_account', $payload);
    }

    public function test_user_update_edits_profile_fields_but_never_salary(): void
    {
        $this->actingAs($this->staff)->put(route('users.update', $this->teacher), $this->updatePayload([
            'id_card_number' => '009988776655', 'id_card_issued_on' => '2021-02-03', 'emergency_contact' => 'Bố - 0911',
            'hometown' => 'Huế', 'current_address' => 'Hà Đông', 'bank_account' => 'TCB-999',
            'base_salary' => 1, 'hourly_rate' => 2, 'monthly_allowance' => 3,
        ]))->assertSessionHasNoErrors();

        $t = $this->teacher->fresh();
        $this->assertSame('009988776655', $t->id_card_number);
        $this->assertSame('2021-02-03', $t->id_card_issued_on->format('Y-m-d'));
        $this->assertSame('Bố - 0911', $t->emergency_contact);
        $this->assertSame('Huế', $t->hometown);
        $this->assertSame('Hà Đông', $t->current_address);
        $this->assertSame('TCB-999', $t->bank_account);
        $this->assertEquals(17777000, $t->base_salary);
        $this->assertEquals(333000, $t->hourly_rate);
        $this->assertEquals(555000, $t->monthly_allowance);
    }

    public function test_without_user_update_cannot_edit_profile_fields(): void
    {
        $outsider = $this->person(Roles::ASSISTANT, $this->branch);
        $this->assertFalse($outsider->can('user.update'));
        $this->actingAs($outsider)->put(route('users.update', $this->teacher), $this->updatePayload(['bank_account' => 'HACK']))->assertForbidden();
        $this->assertSame('9704-VCB-123456', $this->teacher->fresh()->bank_account);
    }

    public function test_edit_form_exposes_profile_fields_but_not_salary_to_non_admin(): void
    {
        $this->actingAs($this->staff)->get(route('users.edit', $this->teacher))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canEditSensitive', false)
                ->where('user.id_card_number', '001201004567')
                ->where('user.bank_account', '9704-VCB-123456')
                ->where('user.id_card_issued_on', '2020-05-01')
                ->missing('user.base_salary')
                ->missing('user.hourly_rate'));
    }

    public function test_non_admin_cannot_set_salary_when_creating_user(): void
    {
        $this->staff->givePermissionTo('user.create');
        $this->actingAs($this->staff)->post(route('users.store'), [
            'name' => 'Nhân sự mới', 'email' => 'new.pii@example.test', 'branch_id' => $this->branch->id,
            'role' => Roles::TEACHER_FULLTIME, 'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
            'base_salary' => 9999999, 'hourly_rate' => 888888, 'bank_account' => 'ACB-1',
        ]);

        $created = User::where('email', 'new.pii@example.test')->first();
        $this->assertNotNull($created);
        $this->assertEquals(0, $created->base_salary);
        $this->assertEquals(0, $created->hourly_rate);
        $this->assertSame('ACB-1', $created->bank_account);
    }

    public function test_admin_sees_and_edits_salary(): void
    {
        $admin = $this->person(Roles::ADMIN, $this->branch);
        $this->actingAs($admin)->get(route('users.show', $this->teacher))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canViewSensitive', true)->where('user.hourly_rate', '333000.00'));
        $this->actingAs($admin)->put(route('users.update', $this->teacher), $this->updatePayload(['hourly_rate' => 400000]))->assertSessionHasNoErrors();
        $this->assertEquals(400000, $this->teacher->fresh()->hourly_rate);
    }

    public function test_cm_of_other_branch_still_out_of_scope(): void
    {
        $far = $this->person(Roles::TEACHER_FULLTIME, $this->otherBranch, ['bank_account' => 'FAR-1']);
        $this->actingAs($this->staff)->get(route('users.show', $far))->assertForbidden();
    }
}
