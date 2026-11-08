<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TK-2026-0043: student accounts must never reach staff-only /portal/* screens (server-side).
class StudentBlockedFromStaffPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_gets_403_on_staff_portal_routes(): void
    {
        $this->seed(RoleSeeder::class);
        $student = User::factory()->create();
        $student->assignRole('student');

        foreach (['/portal/my-salary', '/portal/ta-tasks'] as $url) {
            $this->actingAs($student)->get($url)->assertForbidden();
        }
    }

    public function test_staff_can_still_open_my_salary(): void
    {
        $this->seed(RoleSeeder::class);
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher_parttime');

        $this->actingAs($teacher)->get(route('portal.my-salary'))->assertOk();
    }
}
