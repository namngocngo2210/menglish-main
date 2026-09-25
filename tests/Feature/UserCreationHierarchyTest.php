<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserCreationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected User $academicLead;
    protected User $academicStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG', 'is_active' => true]);

        // 1. Admin
        $this->admin = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');

        // 2. Học thuật độc lập (academic_lead)
        $this->academicLead = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->academicLead->assignRole('academic_lead');

        // 3. Học vụ (academic_staff)
        $this->academicStaff = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->academicStaff->assignRole('academic_staff');
    }

    public function test_admin_can_create_any_role(): void
    {
        $response = $this->actingAs($this->admin)->get(route('users.create'));
        $response->assertOk();

        // Admin can create manager
        $res = $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'Test Manager',
            'email' => 'manager_test@menglish.edu.vn',
            'branch_id' => $this->branch->id,
            'role' => 'manager',
            'password' => 'Password123!',
        ]);
        $res->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['email' => 'manager_test@menglish.edu.vn']);
    }

    public function test_academic_lead_can_only_create_teachers(): void
    {
        // Allowed: teacher, teacher_fulltime, teacher_parttime
        $res1 = $this->actingAs($this->academicLead)->post(route('users.store'), [
            'name' => 'GV Fulltime A',
            'email' => 'gv.ft@menglish.edu.vn',
            'branch_id' => $this->branch->id,
            'role' => 'teacher_fulltime',
            'password' => 'Password123!',
        ]);
        $res1->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['email' => 'gv.ft@menglish.edu.vn']);

        $res2 = $this->actingAs($this->academicLead)->post(route('users.store'), [
            'name' => 'GV Parttime B',
            'email' => 'gv.pt@menglish.edu.vn',
            'branch_id' => $this->branch->id,
            'role' => 'teacher_parttime',
            'password' => 'Password123!',
        ]);
        $res2->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['email' => 'gv.pt@menglish.edu.vn']);

        // Forbidden: cannot create academic_staff, assistant, student, admin
        $resForbidden = $this->actingAs($this->academicLead)->post(route('users.store'), [
            'name' => 'Học vụ trái phép',
            'email' => 'hv.forbidden@menglish.edu.vn',
            'branch_id' => $this->branch->id,
            'role' => 'academic_staff',
            'password' => 'Password123!',
        ]);
        $resForbidden->assertSessionHasErrors(['role']);
        $this->assertDatabaseMissing('users', ['email' => 'hv.forbidden@menglish.edu.vn']);
    }

    public function test_academic_staff_can_only_create_assistant_teachers_and_students(): void
    {
        // Allowed: assistant
        $res1 = $this->actingAs($this->academicStaff)->post(route('users.store'), [
            'name' => 'Trợ giảng A',
            'email' => 'ta.allowed@menglish.edu.vn',
            'branch_id' => $this->branch->id,
            'role' => 'assistant',
            'password' => 'Password123!',
        ]);
        $res1->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['email' => 'ta.allowed@menglish.edu.vn']);

        // Allowed: teacher_fulltime
        $res2 = $this->actingAs($this->academicStaff)->post(route('users.store'), [
            'name' => 'Giáo viên FT',
            'email' => 'gv.ft2@menglish.edu.vn',
            'branch_id' => $this->branch->id,
            'role' => 'teacher_fulltime',
            'password' => 'Password123!',
        ]);
        $res2->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['email' => 'gv.ft2@menglish.edu.vn']);

        // Allowed: student
        $res3 = $this->actingAs($this->academicStaff)->post(route('users.store'), [
            'name' => 'Học viên A',
            'email' => 'hv.allowed@menglish.edu.vn',
            'branch_id' => $this->branch->id,
            'role' => 'student',
            'password' => 'Password123!',
        ]);
        $res3->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['email' => 'hv.allowed@menglish.edu.vn']);

        // Forbidden: cannot create manager or admin or academic_lead
        $resForbidden = $this->actingAs($this->academicStaff)->post(route('users.store'), [
            'name' => 'Lead trái phép',
            'email' => 'lead.forbidden@menglish.edu.vn',
            'branch_id' => $this->branch->id,
            'role' => 'academic_lead',
            'password' => 'Password123!',
        ]);
        $resForbidden->assertSessionHasErrors(['role']);
        $this->assertDatabaseMissing('users', ['email' => 'lead.forbidden@menglish.edu.vn']);
    }
}
