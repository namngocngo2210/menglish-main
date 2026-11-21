<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * TK-2026-0122 / TK-2026-0163: profile fields follow user.view / user.update; salary remains Admin-only.
 * R-05b / EP5-NS-06: academic leads cannot view or write staff bank accounts; other profile fields remain available.
 */
class UserProfileSensitiveFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $staff;

    private User $teacher;

    private User $academicLead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-PII', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Đội Cấn', 'code' => 'DC-PII', 'is_active' => true]);
        $this->staff = $this->person(Roles::ACADEMIC_STAFF, $this->branch);
        $this->staff->givePermissionTo(['user.view', 'user.update']);
        $this->academicLead = $this->person(Roles::ACADEMIC_LEAD, $this->branch);
        $this->academicLead->givePermissionTo(['user.view', 'user.update']);
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

    public function test_academic_lead_show_hides_bank_account_without_hiding_other_profile_fields(): void
    {
        $response = $this->actingAs($this->academicLead)->get(route('users.show', $this->teacher))->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canViewSensitive', false)
            ->where('user.base_salary', null)
            ->where('user.hourly_rate', null)
            ->where('user.profile.4.1', '001201004567')
            ->where('user.profile.5.1', '01/05/2020')
            ->where('user.profile.6.1', 'Mẹ - 0900111222')
            ->where('user.profile.7.1', 'Nam Định')
            ->where('user.profile.8.1', 'Cầu Giấy, Hà Nội')
            ->where('user.profile.14.1', false));
        $this->assertStringNotContainsString('9704-VCB-123456', json_encode($response->viewData('page')['props']));
        foreach (['9704-VCB-123456', '17777000', '333000', '555000'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_academic_lead_drawer_omits_bank_account_but_keeps_other_profile_fields(): void
    {
        $response = $this->actingAs($this->academicLead)->get(route('users.index', ['per_page' => 100]))->assertOk();
        $page = $response->viewData('page');
        $row = collect($page['props']['users']['data'])->firstWhere('id', $this->teacher->id);
        $this->assertNotNull($row, 'teacher must be listed');
        $profile = $row['profile'];
        $this->assertArrayNotHasKey('bank_account', $profile);
        $this->assertSame('001201004567', $profile['id_card_number']);
        $this->assertSame('Mẹ - 0900111222', $profile['emergency_contact']);
        $this->assertSame('Nam Định', $profile['hometown']);
        $this->assertSame('Cầu Giấy, Hà Nội', $profile['current_address']);
        $this->assertArrayNotHasKey('base_salary', $profile);
        $this->assertArrayNotHasKey('hourly_rate', $profile);
        $this->assertArrayNotHasKey('monthly_allowance', $profile);
        $this->assertStringNotContainsString('9704-VCB-123456', $response->getContent());
    }

    public function test_academic_lead_edit_form_omits_bank_account(): void
    {
        $this->actingAs($this->academicLead)->get(route('users.edit', $this->teacher))->assertOk()
            ->assertDontSee('name="bank_account"', false)
            ->assertDontSee('9704-VCB-123456')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canEditBankAccount', false)
                ->where('canEditSensitive', false)
                ->where('user.id_card_number', '001201004567')
                ->where('user.hometown', 'Nam Định')
                ->missing('user.bank_account')
                ->missing('user.base_salary')
                ->missing('user.hourly_rate'));
        $this->get(route('users.edit', $this->teacher), ['X-Remote-Modal' => '1'])->assertOk()
            ->assertDontSee('name="bank_account"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('asModal', true)->where('canEditBankAccount', false)->missing('user.bank_account'));
    }

    public function test_academic_lead_update_ignores_bank_account_but_updates_other_profile_fields(): void
    {
        $this->actingAs($this->academicLead)->put(route('users.update', $this->teacher), $this->updatePayload([
            'bank_account' => 'HACK', 'hometown' => 'Huế', 'base_salary' => 1,
        ]))->assertSessionHasNoErrors();
        $teacher = $this->teacher->fresh();
        $this->assertSame('9704-VCB-123456', $teacher->bank_account);
        $this->assertSame('Huế', $teacher->hometown);
        $this->assertEquals(17777000, $teacher->base_salary);
    }

    public function test_academic_lead_create_form_and_store_disallow_bank_account(): void
    {
        $this->academicLead->givePermissionTo('user.create');
        $this->actingAs($this->academicLead)->get(route('users.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canEditBankAccount', false));
        $this->post(route('users.store'), [
            'name' => 'New teacher', 'email' => 'lead.created@example.test', 'branch_id' => $this->branch->id,
            'role' => Roles::TEACHER_FULLTIME, 'password' => 'Str0ng!Passw0rd',
            'bank_account' => 'HACK', 'id_card_number' => '001201009999', 'base_salary' => 1,
        ])->assertSessionHasNoErrors();
        $created = User::where('email', 'lead.created@example.test')->firstOrFail();
        $this->assertNull($created->bank_account);
        $this->assertSame('001201009999', $created->id_card_number);
        $this->assertEquals(0, $created->base_salary);
    }

    public function test_audit_log_and_csv_respect_bank_account_access(): void
    {
        activity('Tài khoản & Hồ sơ')->performedOn($this->teacher)->causedBy($this->academicLead)
            ->withProperties([
                'old' => ['bank_account' => 'OLD-BANK', 'hometown' => 'Nam Định'],
                'attributes' => ['bank_account' => '9704-VCB-123456', 'hometown' => 'Huế'],
            ])->event('updated')->log('Updated staff profile');

        foreach ([$this->academicLead, $this->staff] as $actor) {
            $actor->givePermissionTo(['activity_log.view', 'activity_log.scope_all']);
            $response = $this->actingAs($actor)->get(route('activity-logs.index'))->assertOk();
            $props = json_encode($response->viewData('page')['props']);
            $csv = $this->get(route('activity-logs.export', ['format' => 'csv']))->assertOk()->streamedContent();
            foreach ([$props, $csv] as $content) {
                $this->assertStringContainsString('hometown', $content);
                foreach (['OLD-BANK', '9704-VCB-123456'] as $bankAccount) {
                    if ($actor->is($this->academicLead)) {
                        $this->assertStringNotContainsString($bankAccount, $content);
                    } else {
                        $this->assertStringContainsString($bankAccount, $content);
                    }
                }
            }
        }
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
                ->where('canEditBankAccount', true)
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

    public function test_admin_and_manager_retain_bank_account_access(): void
    {
        foreach ([Roles::ADMIN, Roles::MANAGER] as $role) {
            $actor = $this->person($role, $this->branch);
            $actor->givePermissionTo('user.update');
            $bankAccount = $this->teacher->fresh()->bank_account;
            $this->actingAs($actor)->get(route('users.show', $this->teacher))->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('user.profile.14.1', $bankAccount));
            $this->get(route('users.edit', $this->teacher))->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('canEditBankAccount', true)->where('user.bank_account', $bankAccount));
            $this->assertSame($bankAccount, \App\Http\Controllers\UserController::profilePayload($this->teacher->fresh(), $actor)['bank_account']);
            $this->put(route('users.update', $this->teacher), $this->updatePayload(['bank_account' => $role.'-BANK']))
                ->assertSessionHasNoErrors();
            $this->assertSame($role.'-BANK', $this->teacher->fresh()->bank_account);
        }
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
